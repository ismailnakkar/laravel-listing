<?php

declare(strict_types=1);

namespace Listing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as Query;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * A Blade list page's query: the filters and sorts its query string may set, read leniently, so that no URL bounces
 * the page. Only the query string is read, never the body. Build it with for(), given the current request.
 *
 * @template TModel of Model
 */
final class Listing
{
    use Conditionable;

    /**
     * Each declared key's narrowed value, what the filter form refills from: null when none reads, or for enums() and
     * ints() a list, empty when none reads.
     *
     * @var array<string, mixed>
     */
    public private(set) array $values = [];

    /** The resolved `?sort=` value, such as `-created_at`: what the form's hidden input carries. */
    public private(set) string $sort;

    /** @var array<string, string> each name `?sort=` may use, and the column it orders by */
    private array $sorts = [];

    /** @var array<string, Filter> */
    private array $filters = [];

    private Sort $default;

    private Sort $current;

    /** @param  Query<TModel>|Relation<TModel, *, *>  $query */
    private function __construct(
        private readonly Builder $query,
        private readonly Request $request,
    ) {
        $this->default = new Sort($query->getModel()->getKeyName(), 'desc');
        $this->resolveSort();
    }

    /**
     * Lists an Eloquent query, or a relation such as `$team->members()`, which keeps its pivot.
     *
     * @template TListed of Model
     *
     * @param  Query<TListed>|Relation<TListed, *, *>  $query
     * @return self<TListed>
     */
    public static function for(Builder $query, Request $request): self
    {
        return new self($query, $request);
    }

    /**
     * Gives every other paginator the listing's page rule (query string only, an offset that fits an int); call it
     * once in a service provider's boot().
     */
    public static function protectAllPages(): void
    {
        Paginator::currentPageResolver(
            static fn (string $pageName = 'page'): int => Filter::positiveInt(request()->query($pageName), intdiv(PHP_INT_MAX, 10_000)) ?? 1,
        );
    }

    /**
     * A positive int up to $max, matched exactly. In place of the column, a closure gets the query and the int.
     *
     * @param  string|(Closure(Query<*>, int): mixed)|null  $column
     */
    public function int(string $key, string|Closure|null $column = null, int $max = PHP_INT_MAX): static
    {
        return $this->add(Filter::int($key, $this->column($key, $column), $max));
    }

    /**
     * The trimmed string, matched exactly; empty is null. In place of the column, a closure gets the query and the
     * string.
     *
     * @param  string|(Closure(Query<*>, string): mixed)|null  $column
     */
    public function text(string $key, string|Closure|null $column = null, int $max = 255): static
    {
        return $this->add(Filter::text($key, $this->column($key, $column), $max));
    }

    /**
     * '1' as true, '0' as false, matched exactly. In place of the column, a closure gets the query and the bool.
     *
     * @param  string|(Closure(Query<*>, bool): mixed)|null  $column
     */
    public function flag(string $key, string|Closure|null $column = null): static
    {
        return $this->add(Filter::flag($key, $this->column($key, $column)));
    }

    /**
     * The backed case, matched exactly. In place of the column, a closure gets the query and the case.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  string|(Closure(Query<*>, TEnum): mixed)|null  $column
     */
    public function enum(string $key, string $enum, string|Closure|null $column = null): static
    {
        return $this->add(Filter::enum($key, $enum, $this->column($key, $column)));
    }

    /**
     * Any of the backed cases a list names, `?status[]=draft&status[]=live` or one `?status=draft`; values that name no
     * case are dropped. The value is a list of cases, empty when none reads, so the view can test it with in_array(). In
     * place of the column, a closure gets the query and the non-empty list.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  string|(Closure(Query<*>, non-empty-list<TEnum>): mixed)|null  $column
     */
    public function enums(string $key, string $enum, string|Closure|null $column = null): static
    {
        return $this->add(Filter::enums($key, $enum, $this->column($key, $column)));
    }

    /**
     * Any of the positive ints up to $max a list names, `?category[]=3&category[]=7` or one `?category=3`; anything else
     * is dropped. The value is a list of ints, empty when none reads. In place of the column, a closure gets the query
     * and the non-empty list.
     *
     * @param  string|(Closure(Query<*>, non-empty-list<int>): mixed)|null  $column
     */
    public function ints(string $key, string|Closure|null $column = null, int $max = PHP_INT_MAX): static
    {
        return $this->add(Filter::ints($key, $this->column($key, $column), $max));
    }

    /**
     * Rows where any of the columns contains the trimmed term as typed: `%`, `_`, `!` and `[` match themselves. Letter
     * case is as whereLike(): the column's collation on MySQL, MariaDB and SQL Server, ILIKE on PostgreSQL, ASCII case
     * ignored on SQLite. With no columns, the key's own column, as for every other filter.
     *
     * @param  string|non-empty-list<string>|null  $columns
     */
    public function search(string $key, string|array|null $columns = null, int $max = 255): static
    {
        return $this->add(Filter::search($key, $columns ?? $this->query->getModel()->qualifyColumn($key), $max));
    }

    /**
     * A day, `2026-10-01`, compared as whole days: '=' that day, '>=' on or after it, '<=' on or before it. A range is
     * two of them on one column. In place of the column, a closure gets the query and the day string.
     *
     * @param  string|(Closure(Query<*>, string): mixed)|null  $column
     * @param  '='|'>='|'<='  $operator
     */
    public function date(string $key, string|Closure|null $column = null, string $operator = '='): static
    {
        return $this->add(Filter::date($key, $this->column($key, $column), $operator));
    }

    /**
     * Adds names `?sort=` may use. A positional name is a column of the model's table; a named argument names any other
     * column or a select alias: `sorts('total', customer: 'customers.name')`. A name given again takes its new column.
     */
    public function sorts(string ...$columns): static
    {
        $model = $this->query->getModel();

        foreach ($columns as $name => $column) {
            if (is_int($name)) {
                [$name, $column] = [$column, $model->qualifyColumn($column)];
            }

            $this->sorts[$name] = $column;
        }

        $this->resolveSort();

        return $this;
    }

    /** `-created_at` for newest first. Until it is called, the default is the model's key, descending. */
    public function defaultSort(string $sort): static
    {
        $this->default = Sort::parse($sort);
        $this->resolveSort();

        return $this;
    }

    /**
     * The filtered, sorted query, unpaged: a clone of what for() was given, so calling it twice never doubles a
     * filter. A query gives an Eloquent builder; a relation gives the relation, so its pivot columns still load.
     *
     * @return Query<TModel>|Relation<TModel, *, *>
     */
    public function apply(): Builder
    {
        $query = clone $this->query;
        $eloquent = $query instanceof Relation ? $query->getQuery() : $query;

        foreach ($this->filters as $key => $filter) {
            $filter->apply($eloquent, $this->values[$key]);
        }

        $this->current->apply($eloquent, $this->sorts[$this->current->name] ?? $eloquent->getModel()->qualifyColumn($this->current->name));

        return $query;
    }

    /**
     * The filtered, sorted page, its links keeping the query string. The page size is $perPage, else, for null or 0 as
     * in Laravel, the model's. `?page` reads like an id up to the last page whose offset fits an int, so a huge page
     * shows the first one instead of overflowing.
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->apply();
        $perPage = $perPage ?: $query->getModel()->getPerPage();

        return $query->paginate($perPage, ['*'], 'page', $this->page($perPage))->withQueryString();
    }

    /**
     * The filtered, sorted page without a total, so with no COUNT query. The page size and `?page` read as in
     * paginate().
     *
     * @return Paginator<int, TModel>
     */
    public function simplePaginate(?int $perPage = null): Paginator
    {
        $query = $this->apply();
        $perPage = $perPage ?: $query->getModel()->getPerPage();

        return $query->simplePaginate($perPage, ['*'], 'page', $this->page($perPage))->withQueryString();
    }

    /**
     * The filtered, sorted page by cursor; a cursor the sort or column cannot take (SQLSTATE class 22) shows the first
     * page, any other database error throws.
     *
     * @return CursorPaginator<int, TModel>
     */
    public function cursorPaginate(?int $perPage = null): CursorPaginator
    {
        $perPage = $perPage ?: $this->query->getModel()->getPerPage();
        $cursor = $this->request->query('cursor');

        try {
            return $this->apply()->cursorPaginate($perPage, ['*'], 'cursor', is_string($cursor) ? $cursor : '')->withQueryString();
        } catch (UnexpectedValueException|InvalidArgumentException) {
            // A cursor from another sort order, or one holding a null.
        } catch (QueryException $e) {
            throw_unless(str_starts_with((string)$e->getCode(), '22'), $e);
        }

        return $this->apply()->cursorPaginate($perPage, ['*'], 'cursor', '')->withQueryString();
    }

    /**
     * A header's link: the request's URL with every query parameter kept as sent, `sort` set, and `page` and `cursor`
     * removed, so that a new order never pairs with an old page or cursor. The sorted column flips; another starts
     * descending.
     */
    public function sortUrl(string $name): string
    {
        if (! array_key_exists($name, $this->sorts)) {
            throw new InvalidArgumentException("The listing has no sort named [{$name}].");
        }

        return $this->request->fullUrlWithQuery(['sort' => (string)$this->current->toggled($name), 'page' => null, 'cursor' => null]);
    }

    /** A header's `aria-sort`: null on a column the list is not sorted by. */
    public function ariaSort(string $name): ?string
    {
        if ($name !== $this->current->name) {
            return null;
        }

        return $this->current->direction === 'asc' ? 'ascending' : 'descending';
    }

    /**
     * The column a filter compares: the one given, as written, else the key as a column of the model's table, the same
     * as a positional sort, so a filter left to its default stays unambiguous on a join.
     */
    private function column(string $key, string|Closure|null $column): string|Closure
    {
        return $column ?? $this->query->getModel()->qualifyColumn($key);
    }

    /** `?page` read like an id, up to the last page whose offset fits an int; anything else is the first page. */
    private function page(int $perPage): int
    {
        return Filter::positiveInt($this->request->query('page'), intdiv(PHP_INT_MAX, $perPage)) ?? 1;
    }

    /** One filter per key: a second declaration is a mistake, and fails where the screen makes it. */
    private function add(Filter $filter): static
    {
        if (array_key_exists($filter->key, $this->filters)) {
            throw new LogicException("The listing already filters [{$filter->key}].");
        }

        $this->filters[$filter->key] = $filter;
        $this->values[$filter->key] = $filter->read(data_get($this->request->query(), $filter->key));

        return $this;
    }

    /** `?sort=` when the allowlist has its name; the default for anything else. */
    private function resolveSort(): void
    {
        $input = Filter::cleanString($this->request->query('sort'));
        $sort = $input === null ? null : Sort::parse($input);

        $this->current = $sort !== null && array_key_exists($sort->name, $this->sorts) ? $sort : $this->default;
        $this->sort = (string)$this->current;
    }
}
