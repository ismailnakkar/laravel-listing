<?php

declare(strict_types=1);

namespace Listing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Database\Query\Expression;
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
 * A list page's filters and sorts, read leniently from the query string only, so no URL bounces the page. A filter's
 * column defaults to the key on the model's table; a closure in its place runs only on a value, and gets the query
 * and the value.
 *
 * @template TModel of Model
 */
final class Listing
{
    use Conditionable;

    /**
     * Each key's narrowed value, for refilling the form: null when none reads; enums() and ints() hold a list.
     *
     * @var array<string, mixed>
     */
    public private(set) array $values = [];

    /** The resolved `?sort=`, such as `-created_at`, for the form's hidden input. */
    public private(set) string $sort;

    /** @var array<string, string> sort name => column */
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
     * Pass the current request; a relation's rows keep their pivot.
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
     * Makes Laravel's own paginators read `?page` as a listing does, from the query string only, for page sizes up to
     * 10,000. Call it once, in a service provider's boot().
     */
    public static function protectAllPages(): void
    {
        Paginator::currentPageResolver(
            static fn (string $pageName = 'page'): int => Filter::positiveInt(request()->query($pageName), intdiv(PHP_INT_MAX, 10_000)) ?? 1,
        );
    }

    /**
     * A whole number from 0 to $max, matched exactly.
     *
     * @param  string|(Closure(Query<*>, int): mixed)|null  $column
     */
    public function int(string $key, string|Closure|null $column = null, int $max = PHP_INT_MAX): static
    {
        return $this->add(Filter::int($key, $this->column($key, $column), $max));
    }

    /**
     * The trimmed string, matched exactly; empty is null.
     *
     * @param  string|(Closure(Query<*>, string): mixed)|null  $column
     */
    public function text(string $key, string|Closure|null $column = null, int $max = 255): static
    {
        return $this->add(Filter::text($key, $this->column($key, $column), $max));
    }

    /**
     * '1' as true, '0' as false, matched exactly.
     *
     * @param  string|(Closure(Query<*>, bool): mixed)|null  $column
     */
    public function flag(string $key, string|Closure|null $column = null): static
    {
        return $this->add(Filter::flag($key, $this->column($key, $column)));
    }

    /**
     * The backed case, matched exactly; an int-backed enum reads digits only.
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
     * Any of the backed cases a list names (`?status[]=draft&status[]=live`, or `?status=draft`); the rest are dropped.
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
     * Any of the whole numbers from 0 to $max a list names (`?category[]=3&category[]=7`, or `?category=3`); the rest
     * are dropped.
     *
     * @param  string|(Closure(Query<*>, non-empty-list<int>): mixed)|null  $column
     */
    public function ints(string $key, string|Closure|null $column = null, int $max = PHP_INT_MAX): static
    {
        return $this->add(Filter::ints($key, $this->column($key, $column), $max));
    }

    /**
     * Rows where any column contains the trimmed term literally; an expression is searched as written. Letter case is
     * as whereLike(): the collation on MySQL, MariaDB and SQL Server, ILIKE on PostgreSQL, ASCII case ignored on
     * SQLite.
     *
     * @param  string|Expression|non-empty-list<string|Expression>|null  $columns
     */
    public function search(string $key, string|Expression|array|null $columns = null, int $max = 255): static
    {
        return $this->add(Filter::search($key, $columns ?? $this->query->getModel()->qualifyColumn($key), $max));
    }

    /**
     * A day, `2026-10-01`, compared whole: '=' that day, '>=' from it, '<=' up to it; a range is two on one column.
     *
     * @param  string|(Closure(Query<*>, string): mixed)|null  $column
     * @param  '='|'>='|'<='  $operator
     */
    public function date(string $key, string|Closure|null $column = null, string $operator = '='): static
    {
        return $this->add(Filter::date($key, $this->column($key, $column), $operator));
    }

    /**
     * Adds names `?sort=` may use: a positional name is a column of the model's table, a named one maps to any column
     * or select alias (`sorts('total', customer: 'customer_name')`). A name given again takes its new column.
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

    /** `-created_at` for newest first; without it, the model's key descending. */
    public function defaultSort(string $sort): static
    {
        $this->default = Sort::parse($sort);
        $this->resolveSort();

        return $this;
    }

    /**
     * The filtered, sorted query, unpaged, on a clone; a relation stays a relation, so its pivot still loads.
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
     * Links keep the query string; a null or 0 $perPage is the model's, as in Laravel.
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
     * As paginate(), without the COUNT query.
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
     * A cursor the sort or column cannot take (SQLSTATE class 22) shows the first page; other database errors throw.
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
     * A header's link: the current URL with `sort` flipped, or set to $name descending, and `page` and `cursor`
     * dropped, so a new order never pairs with an old page.
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

    /** Qualified, like a positional sort, so a defaulted column stays unambiguous on a join. */
    private function column(string $key, string|Closure|null $column): string|Closure
    {
        return $column ?? $this->query->getModel()->qualifyColumn($key);
    }

    /** Capped so a huge `?page` cannot overflow the offset. */
    private function page(int $perPage): int
    {
        return Filter::positiveInt($this->request->query('page'), intdiv(PHP_INT_MAX, $perPage)) ?? 1;
    }

    private function add(Filter $filter): static
    {
        if (array_key_exists($filter->key, $this->filters)) {
            throw new LogicException("The listing already filters [{$filter->key}].");
        }

        $this->filters[$filter->key] = $filter;
        $this->values[$filter->key] = $filter->read(data_get($this->request->query(), $filter->key));

        return $this;
    }

    private function resolveSort(): void
    {
        $input = Filter::cleanString($this->request->query('sort'));
        $sort = $input === null ? null : Sort::parse($input);

        $this->current = $sort !== null && array_key_exists($sort->name, $this->sorts) ? $sort : $this->default;
        $this->sort = (string)$this->current;
    }
}
