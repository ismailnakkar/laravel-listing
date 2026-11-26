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
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * A list page's filters, sort and page, read leniently from the query string only, so no URL bounces the page. A
 * filter's column defaults to the key on the model's table; a closure in its place gets the query and the value, and
 * runs only when there is one.
 *
 * @template TModel of Model
 */
final class Listing
{
    use Conditionable;

    private const string SORT = 'sort';

    private const string PAGE = 'page';

    private const string CURSOR = 'cursor';

    /** The query keys the listing reads itself: no filter key may be one, or nest under one. */
    private const array OWN_KEYS = [self::SORT, self::PAGE, self::CURSOR];

    /** protectAllPages() keeps the offset in range up to this page size. */
    private const int MAX_PROTECTED_PAGE_SIZE = 10_000;

    /** SQLSTATE class 22, data exception: a cursor value the column cannot take. */
    private const string DATA_EXCEPTION = '22';

    /**
     * Each key's narrowed value, for refilling the form: null when none reads; enums() and ints() hold a list.
     *
     * @var array<string, mixed>
     */
    public private(set) array $values = [];

    /** The resolved `?sort=`, such as `-created_at`, for the form's hidden input. */
    public private(set) string $sort;

    /** @var array<string, string|CaseOrder> sort name => column, or an enum column in its cases' order */
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
        $this->default = new Sort($query->getModel()->getKeyName(), Direction::desc);
        $this->resolveSort();
    }

    /**
     * Reads the request given, else the current one; its page links follow the same request. A relation's rows keep
     * their pivot.
     *
     * @template TListed of Model
     *
     * @param  Query<TListed>|Relation<TListed, *, *>  $query
     * @return self<TListed>
     */
    public static function for(Builder $query, ?Request $request = null): self
    {
        return new self($query, $request ?? request());
    }

    /**
     * Makes Laravel's own paginators read `?page` as a listing does, from the query string only, for page sizes up to
     * 10,000. Call it once, in a service provider's boot().
     */
    public static function protectAllPages(): void
    {
        $maxPage = intdiv(PHP_INT_MAX, self::MAX_PROTECTED_PAGE_SIZE);

        Paginator::currentPageResolver(
            static fn (string $pageName = self::PAGE): int => Input::positiveInt(request()->query($pageName), $maxPage) ?? 1,
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
     * as whereLike(): the collation on MySQL and MariaDB, ILIKE on PostgreSQL, ASCII case ignored on
     * SQLite.
     *
     * @param  string|Expression|non-empty-list<string|Expression>|null  $columns
     */
    public function search(string $key, string|Expression|array|null $columns = null, int $max = 255): static
    {
        return $this->add(Filter::search($key, $columns ?? $this->qualify($key), $max));
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
        foreach ($columns as $name => $column) {
            if (is_int($name)) {
                [$name, $column] = [$column, $this->qualify($column)];
            }

            $this->sorts[$name] = $column;
        }

        $this->resolveSort();

        return $this;
    }

    /**
     * Adds a name `?sort=` may use that orders an enum column by its cases' declared order, ascending; a value no case
     * holds, or null, comes after them all, and descending reverses the whole order. No index serves it. The column
     * defaults to $name on the model's table; a name given again takes its new order.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public function sortsByCase(string $name, string $enum, ?string $column = null): static
    {
        $this->sorts[$name] = CaseOrder::of($enum, $column ?? $this->qualify($name));
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

        $this->current->apply($eloquent, $this->sorts[$this->current->name] ?? $this->qualify($this->current->name));

        return $query;
    }

    /**
     * Links keep the query string; a null or 0 $perPage is the model's, as in Laravel.
     *
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $perPage = $this->perPage($perPage);

        return $this->links($this->apply()->paginate($perPage, ['*'], self::PAGE, $this->page($perPage)));
    }

    /**
     * As paginate(), without the COUNT query.
     *
     * @return Paginator<int, TModel>
     */
    public function simplePaginate(?int $perPage = null): Paginator
    {
        $perPage = $this->perPage($perPage);

        return $this->links($this->apply()->simplePaginate($perPage, ['*'], self::PAGE, $this->page($perPage)));
    }

    /**
     * A cursor the sort or column cannot take (SQLSTATE class 22) shows the first page; other database errors throw.
     * A listing with a sortsByCase() sort throws: an order by position has no column for a cursor to hold.
     *
     * @return CursorPaginator<int, TModel>
     */
    public function cursorPaginate(?int $perPage = null): CursorPaginator
    {
        if (array_any($this->sorts, static fn (string|CaseOrder $by): bool => $by instanceof CaseOrder)) {
            throw new LogicException('A listing with a sortsByCase() sort pages with paginate() or simplePaginate().');
        }

        $perPage = $this->perPage($perPage);
        $cursor = $this->request->query(self::CURSOR);

        try {
            return $this->cursorPage($perPage, is_string($cursor) ? $cursor : null);
        } catch (UnexpectedValueException|InvalidArgumentException) {
            // A cursor from another sort order, or one holding a null.
        } catch (QueryException $e) {
            throw_unless(str_starts_with((string)$e->getCode(), self::DATA_EXCEPTION), $e);
        }

        return $this->cursorPage($perPage, null);
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

        return $this->request->fullUrlWithQuery([
            self::SORT   => (string)$this->current->toggled($name),
            self::PAGE   => null,
            self::CURSOR => null,
        ]);
    }

    /** A header's `aria-sort`: null on a column the list is not sorted by. */
    public function ariaSort(string $name): ?string
    {
        return $name === $this->current->name ? $this->current->direction->aria() : null;
    }

    /** Qualified, like a positional sort, so a defaulted column stays unambiguous on a join. */
    private function column(string $key, string|Closure|null $column): string|Closure
    {
        return $column ?? $this->qualify($key);
    }

    /** On the listed model's table. */
    private function qualify(string $column): string
    {
        return $this->query->getModel()->qualifyColumn($column);
    }

    private function add(Filter $filter): static
    {
        $root = Str::before($filter->key, '.');

        if (in_array($root, self::OWN_KEYS, true)) {
            throw new LogicException("[{$root}] is the listing's own query key.");
        }

        if (array_key_exists($filter->key, $this->filters)) {
            throw new LogicException("The listing already filters [{$filter->key}].");
        }

        $this->filters[$filter->key] = $filter;
        $this->values[$filter->key] = $filter->read(data_get($this->request->query(), $filter->key));

        return $this;
    }

    private function resolveSort(): void
    {
        $input = Input::string($this->request->query(self::SORT));
        $sort = $input === null ? null : Sort::parse($input);

        $this->current = $sort !== null && array_key_exists($sort->name, $this->sorts) ? $sort : $this->default;
        $this->sort = (string)$this->current;
    }

    private function perPage(?int $perPage): int
    {
        return $perPage ?: $this->query->getModel()->getPerPage();
    }

    /**
     * Links on the listing's request, with its query string, not the container's.
     *
     * @template TPaginator of LengthAwarePaginator<int, TModel>|Paginator<int, TModel>|CursorPaginator<int, TModel>
     *
     * @param  TPaginator  $paginator
     * @return TPaginator
     */
    private function links(LengthAwarePaginator|Paginator|CursorPaginator $paginator): LengthAwarePaginator|Paginator|CursorPaginator
    {
        return $paginator->withPath($this->request->url())->appends($this->request->query());
    }

    /** Capped so a huge `?page` cannot overflow the offset. */
    private function page(int $perPage): int
    {
        return Input::positiveInt($this->request->query(self::PAGE), intdiv(PHP_INT_MAX, $perPage)) ?? 1;
    }

    /**
     * Null is the first page. Laravel takes a string, even empty, as the cursor, and resolves a null one from the app
     * request's input, body included.
     *
     * @return CursorPaginator<int, TModel>
     */
    private function cursorPage(int $perPage, ?string $cursor): CursorPaginator
    {
        return $this->links($this->apply()->cursorPaginate($perPage, ['*'], self::CURSOR, $cursor ?? ''));
    }
}
