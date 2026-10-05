# laravel-listing: the `Listing` builder redesign

- **Date:** 2026-10-02
- **Status:** each section was approved in conversation, then the spec was self-reviewed by two adversarial reviewers
  (29 findings applied). It awaits the owner's review.
- **Replaces:** the `ListingRequest` / `Sort` / `Like` API in the 2026-10-01 working tree

## Goal

The package serves Blade list pages only, and its job is **easy querying and filtering**: a developer builds a
filterable, sortable, searchable and paginated list page with as little code as possible. Three properties hold
throughout:

1. **Never bounce.** No query-string value can cause a 4xx or 5xx, apart from the column-type and engine traps that
   section 2 lists as the developer's job. A value that does not narrow to its type reads as absent, so a mistyped id
   or a stale bookmark shows the default list.
2. **Typed values.** Every filter narrows its parameter to a type before it touches the query.
3. **Same behaviour on every engine Laravel 13 supports:** SQLite, MySQL, MariaDB, PostgreSQL and SQL Server.

JSON APIs, Inertia and JSON:API conventions are out of scope. For an API, use spatie/laravel-query-builder.

## Evidence behind the design

This design comes out of three rounds of measurement.

**Verification of the original package.** It was run on 5 real engines, read by adversarial reviewers, and put
through a mutation pass. Bugs that were found and fixed:

- **LIKE escaping:** it returned 0 rows on SQLite and SQL Server for terms containing `%`, `_` or `\`.
- **Joins:** the default `id` sort was ambiguous.
- **Bounces:** 302 and 422 responses came from the README's own `max:` rules.
- **PostgreSQL:** `lower(integer)` returned a 500.
- **Paging:** a missing tiebreak let rows repeat or vanish on PostgreSQL.
- **Input bytes:** invalid UTF-8 and NUL bytes returned a 500.

**Structure spike.** Five throwaway structures were each built on the same three Blade screens: orders with a join
and a two-column search, users with ULID keys, and a team's members through a `BelongsToMany`. Three judges scored
them.

| | Package `src` lines | Lines for 3 screens | Picked by |
|---|---|---|---|
| Plain Laravel, no package | 0 | 162, with about 8 traps left | none |
| Declarative request class | 210 | 159 | the Laravel-idioms judge |
| Imperative request class, trimmed | 135 | 141 | the minimalism judge |
| Laravel 13 attributes | 244 | 122 | none (fragile) |
| **Fluent `Listing::for()` builder** | 219 | 128 | **the DX judge; the owner chose it** |

What the spike showed:

- The package buys correctness, not lines.
- Blade is about two thirds of every screen.
- `?page=9223372036854775807` returns a 500 on PHP 8.5 in any design that does not cap the page.

From Spatie v7 the design borrows the declare-once chain, `?sort=-column` and sort aliases. It does not borrow
Spatie's strict 400s.

**Date spike.** The facts were measured on 7 Laravel date column types across 5 engines. A day-range design was then
implemented and checked by an adversarial verifier: about 81,000 listing pages and 12,366 hostile HTTP requests
(2,061 on each of six engine and driver runs), all of which returned 200. The verifier found two edge bugs; both are
fixed in section 3.

The SQL Server runs used two drivers, `pdo_dblib` and `pdo_sqlsrv`. Through `pdo_dblib`, SQL Server runs with
`ANSI_WARNINGS` off and returns NULL instead of raising conversion errors. The earlier SQL Server "no error" results
came through that driver, so release verification on SQL Server must use `pdo_sqlsrv` (section 4).

## 1. Public API: `Listing` is the only public class

```php
use Listing\Listing;

public function index(Request $request)
{
    $orders = Order::query()
        ->join('customers', 'customers.id', '=', 'orders.customer_id')
        ->select('orders.*', 'customers.name as customer_name');

    $listing = Listing::for($orders, $request)
        ->enum('status', OrderStatus::class)
        ->flag('paid')
        ->search('q', ['orders.reference', 'customers.email'])
        ->date('from', 'orders.created_at', '>=')
        ->date('to', 'orders.created_at', '<=')
        ->sorts('reference', 'total', 'created_at', customer: 'customers.name')
        ->defaultSort('-created_at');

    return view('orders.index', ['orders' => $listing->paginate(), 'listing' => $listing]);
}
```

### The builder

| Method | Reads `?key=` as | Then, when there is a value |
|---|---|---|
| `for(Builder $query, Request $request): self` | — | Starts a listing of an Eloquent query or a relation (`$team->members()`). `Builder` is `Illuminate\Contracts\Database\Eloquent\Builder`. |
| `int(string $key, string\|Closure\|null $column = null, int $max = PHP_INT_MAX)` | a positive `int` up to `$max` | `where($column ?? <model table>.$key, $int)`. Was `id($key = 'id')` until 0.4, renamed with the key required by the owner on 2026-10-03: `->id()->id('user_id')` read as a column, not a type. |
| `text(string $key, string\|Closure\|null $column = null, int $max = 255)` | the trimmed string; empty is `null` | `where($column ?? <model table>.$key, $text)` |
| `flag(string $key, string\|Closure\|null $column = null)` | `'1'` as `true`, `'0'` as `false` | `where($column ?? <model table>.$key, $bool)` |
| `enum(string $key, class-string<BackedEnum> $enum, string\|Closure\|null $column = null)` | the backed case; an int-backed enum reads digits only | `where($column ?? <model table>.$key, $case)` |
| `enums(string $key, class-string<BackedEnum> $enum, string\|Closure\|null $column = null)` | a list of backed cases, `?key[]=a&key[]=b` or one `?key=a`; values that name no case are dropped, duplicates removed | `whereIn($column ?? <model table>.$key, $cases)` |
| `ints(string $key, string\|Closure\|null $column = null, int $max = PHP_INT_MAX)` | a list of positive `int`s up to `$max`, read like `enums()` | `whereIn($column ?? <model table>.$key, $ints)`. Was `ids()` until 0.4. |
| `search(string $key, string\|non-empty-list<string>\|null $columns = null, int $max = 255)` | the trimmed string; empty is `null` | any of the columns contains it as typed; letter case as `whereLike()`: the column's collation on MySQL, MariaDB and SQL Server, ILIKE on PostgreSQL, ASCII case ignored on SQLite (0.5, 2026-10-05). With no columns, `<model table>.$key`, as for every other filter (0.4, 2026-10-03). |
| `date(string $key, string\|Closure\|null $column = null, '='\|'>='\|'<=' $operator = '=')` | a real day, `Y-m-d`, from 1753-01-01 to 9999-12-31 | whole days; see section 3 |
| `sorts(string ...$columns): self` | — | Adds names that `?sort=` may use. Every call adds to them, and a name given again takes its new column. A positional name is a column of the model's table. A named argument is an alias for any other column or a select alias (`customer: 'customers.name'`). |
| `defaultSort(string $sort): self` | — | `'-created_at'`. Until it is called, the default is the model's key, descending. |
| `apply(): Builder` | — | A filtered and sorted **clone** of what `for()` was given, unpaged. A query gives an Eloquent builder; a relation gives the relation itself, so `get()` and `simplePaginate()` still select its pivot columns. Use it for an export or a second query on the same filters; page through the three paginate methods, which carry the page cap and the cursor guard. |
| `paginate(?int $perPage = null): LengthAwarePaginator` | `?page` | The filtered, sorted page. The page size is the argument, else (for `null` or `0`, as in Laravel) the model's `$perPage`. A page above the cap reads as absent, and the links keep the query string. |
| `simplePaginate(?int $perPage = null): Paginator` | `?page` | As `paginate()`, without a total, so with no COUNT query. Added for 0.2 (2026-10-02). |
| `cursorPaginate(?int $perPage = null): CursorPaginator` | `?cursor` | The page by cursor, with no COUNT and no OFFSET. The cursor comes from the query string; one from another sort order, one holding a null, or one holding a value the column cannot hold (SQLSTATE class 22 on PostgreSQL and SQL Server) shows the first page, and an error that is not the cursor's throws again from the retry. A joined sort column pages only by its select alias, and a page ending on a NULL sort value links back to the first page (Laravel). Added for 0.2 (2026-10-02). |

General rules for the filter methods:

- **A column left to its default is the model's own.** With no `$column`, a filter compares the key as a column of
  the listed model's table (`qualifyColumn($key)`), like a positional sort, so it stays unambiguous on a join. A
  column that is passed is used as written, so name a joined or pivot column in full (`customers.email`,
  `team_user.role`). Decided by the owner on 2026-10-02, after three fresh-app trials found the README's own join
  example returning a 500 on `?status=` when `customers` also has `status`.
- **List filters** (`enums()`, added for 0.1 on 2026-10-02 with `ids()`, now `ints()`). The value is a list, never `null`: empty when
  nothing reads, which adds nothing and calls no closure, so a view can test it with `in_array()`. More than 1000
  values read as an empty list, a backstop for a server that raises `max_input_vars`, since SQL Server takes at most
  2100 bindings.
- **Only the query string is read** (2026-10-02, after review): filters, `sort` and `page` come from
  `$request->query()`, never a form or JSON body. A list page's state is its URL, and a JSON body on a GET is not
  bound by `max_input_vars`, so it could stack list filters past SQL Server's 2100 bindings.
- **Typing.** `Listing` is generic over the listed model (`@template TModel`), so `paginate()` gives
  `LengthAwarePaginator<int, TModel>` and `apply()` the model's builder or relation; `tests/Types` pins it.
- **Closure filters.** A closure passed as `$column` is called only when the value is not `null` (or, for a list, not
  empty). It receives the
  concrete `Illuminate\Database\Eloquent\Builder` (for a relation, its underlying query) and the narrowed value:
  - `int`: an `int`
  - `flag`: a `bool`
  - `enum`: the enum case
  - `enums` and `ints`: a non-empty list of cases or `int`s
  - `text` and `date`: a `string` (`date` gives `Y-m-d`)
- **Order.** Filters apply in the order they are declared.
- **One filter per key.** Declaring a key that is already declared throws a `LogicException` where the screen
  declares it.
- **Conditional configuration.** `Listing` uses Laravel's `Illuminate\Support\Traits\Conditionable`, so a sort or
  filter can depend on the user: `->when($user->isAdmin(), fn (Listing $l) => $l->sorts('margin'))`.

### The view

The view uses the same object:

```blade
<form method="get">
    <input type="search" name="q" value="{{ $listing->values['q'] }}">
    <input type="date" name="from" value="{{ $listing->values['from'] }}">
    <input type="date" name="to" value="{{ $listing->values['to'] }}">
    <input type="hidden" name="sort" value="{{ $listing->sort }}">
    <button>Filter</button>
</form>

<th @if ($listing->ariaSort('total')) aria-sort="{{ $listing->ariaSort('total') }}" @endif>
    <a href="{{ $listing->sortUrl('total') }}">Total</a>
</th>
```

| Member | Gives |
|---|---|
| `$listing->values` | `array<string, mixed>`, read-only (`public private(set)`). Each declared key maps to its narrowed value, or `null`. Refill the form from it, never from `request()`. |
| `$listing->sort` | `string`, read-only: the resolved `?sort=` value (`'-created_at'`), for the form's hidden input. |
| `$listing->sortUrl(string $name): string` | The full URL for a header link: the request's URL with every query parameter kept as sent, `sort` set, and `page` and `cursor` removed, so that a new order never pairs with an old page or cursor. The sorted column flips, and any other starts descending. A name that is not in `sorts()` throws an `InvalidArgumentException`. A header for a sort that only some users have must use the view-side version of the same condition. |
| `$listing->ariaSort(string $name): ?string` | `'ascending'` or `'descending'` on the sorted column, `null` on any other. |

### Internals

These classes are `@internal`, are never imported by users, and may change in any release.

```
src/
  Listing.php   the only public class: the builder, its values and the view helpers
  Filter.php    @internal: one declared filter, i.e. how its value narrows and the condition it adds,
                including the portable LIKE (formerly Like.php) and the day range
  Sort.php      @internal: ?sort= parsing, the order and its tiebreak, toggling
```

### Decisions

- **Namespace:** `Listing\`, the package's name, in the same bare product-name style as the owner's other packages
  (`Subscriptions\`, `VisitReceipts\`, `FloodControl\`, `Seo\`); users write `use Listing\Listing;`. The known
  trade-off is accepted: an app with its own `Listing\` namespace would share the prefix with the package.
- **Versions:** PHP `^8.4`, because the builder uses asymmetric visibility, and Laravel `^13.0`.
- **No packaged Blade component.** The README gives a copy-paste anonymous component that makes each sortable header
  one line.
- **Authorization:** Laravel 13's `#[Authorize('viewAny', Order::class)]` controller attribute, or `can:` middleware.
  The README documents it; there is no code for it.
- **Reuse:** a static method or private method that returns the configured `Listing`. The README documents it; there
  is no code for it.
- **Cursor pagination and statement timeouts** (the exe-laravel link lists, through its `LinkSearch` service): these
  use `cursorPaginate()`, called inside the app's statement timeout. `sortUrl()` drops `cursor`. Both were first
  guarded in the app through `apply()`; the exe migration found the copy-paste guard wrong twice, so 0.2 moved the page
  cap and the cursor guard into `simplePaginate()` and `cursorPaginate()`.
- **Carried over:**
  - A header starts a new column descending.
  - `flag()` reads `0` as `false`, because "No" is an answer, not an absent filter.

## 2. One page load, and what can fail

1. `Listing::for($query, $request)` keeps the query and the request. Nothing runs yet. A relation is listed as
   itself, so its rows keep their `pivot`.
2. Each filter method narrows the query string's `$key` once, into a typed value or `null`, and stores it in
   `$listing->values[$key]`.
   - `null` means the filter adds nothing and its closure is not called.
   - `text()` and `search()` give `null`, never `''`, for an empty value or one that does not narrow. So the empty
     inputs that a GET form submits (`?q=&from=`) filter nothing. `'0'` is a value.
3. `for()` starts the sort at the model's key, descending. Every `sorts()` or `defaultSort()` call resolves it again,
   so it is final once the chain ends. Until `sorts()` is called the allowlist is empty, and every `?sort=` reads as
   absent.
   - `?sort=` is narrowed like `text()`: trimmed, at most 255 characters. One leading `-` is stripped, and the name
     that is left must be on the allowlist.
   - A positional name orders by the model's qualified column (`orders.total`). An alias orders by its column as
     written.
   - Anything else gives `defaultSort()`, whose name resolves through the same aliases, or failing that the model's
     qualified column.
4. `apply()` clones what `for()` was given. `clone` on an Eloquent builder or a relation clones the underlying query,
   so calling `apply()` or `paginate()` twice never doubles the filters.
   - Each filter with a value adds its condition to the clone, or to a relation's underlying query.
   - Then the sort: `reorder($column, $direction)`, which replaces any order the query or the relation had (for
     example a relation's `latest()`), then the model's qualified key in the same direction, unless the column is
     the key.
5. `paginate($perPage = null)`:
   - Computes `$perPage = $perPage ?: $model->getPerPage()`, so `0` takes the model's size, as in Laravel.
   - Narrows `?page` like an id whose `$max` is `intdiv(PHP_INT_MAX, $perPage)`. A larger page, or anything else
     that does not narrow, gives page 1.
   - Calls `paginate($perPage, ['*'], 'page', $page)` on the clone. A relation pages as itself, so `BelongsToMany`
     still selects its pivot columns.
   - Returns the paginator with `withQueryString()`.

**Never fails, whatever the URL holds**, apart from the traps listed below. Each of these reads as absent, and the
page returns 200 with the default list:

- arrays and nested arrays
- invalid UTF-8, NUL bytes and overlong values
- unknown enum values, and ids past the filter's `$max`
- impossible or out-of-range dates
- a garbage or stale `?sort`
- a huge `?page`
- a garbage, foreign or forged `?cursor`, on `cursorPaginate()`

**Fails loudly, on purpose, for developer mistakes:**

- **A column passed bare that is ambiguous on a join.** A passed column is used as written, so every engine raises
  this, SQLite included.
- **A column that does not exist.** MySQL, MariaDB, PostgreSQL and SQL Server raise this, and SQLite does for a
  qualified name (`orders.paidd`). SQLite reads an unknown bare name as a string literal, so there `where('paidd', …)`
  matches nothing and a `search()` over it can match every row, without an error. The README says so. Either mistake
  surfaces only once the filter has a value.
- **A closure whose value parameter does not match its filter.** This is a `TypeError` under strict types, and
  PHPStan flags it through the callable docblocks.
- **`enum()` given a class that is not a backed enum.** This fails when the screen declares the filter
  (`ReflectionEnum::isBacked()`), whatever the query string holds.
- **`date()` given an operator other than `'='`, `'>='` or `'<='`.** This fails when the screen declares the filter.
- **A filter key declared twice.** This fails when the screen declares the filter.
- **`sortUrl()` given a name that is not in `sorts()`.** This fails when the view renders.

**Stays the developer's job, and is documented:**

- A closure that parses its value further must not hand the database an impossible value.
- Authorization.
- `sort`, `page` and `cursor` are reserved query-string names.
- **Paging `apply()` yourself** (a second paginator, a custom page name). Laravel's own page resolver is uncapped, so
  `?page=9223372036854775807` is a 500 on PHP 8.5. A cursor is a 500 too when it comes from another sort order
  (`UnexpectedValueException`), holds a null (`InvalidArgumentException`, found by the exe-laravel sweep) or holds a
  value the column cannot hold (SQLSTATE class 22 on PostgreSQL and SQL Server). The three paginate methods guard
  each of these; code that pages `apply()` itself has to repeat their guards.
- Engine and driver quirks the package cannot see:
  - **PostgreSQL raises, instead of matching nothing, when a value does not fit the column's type.** An `int()` on an
    `integer` (not `bigInteger`) column needs `max: 2147483647`, and so does an `ints()`, where one value out of range fails
    the whole list. A `uuid` column needs a closure that checks
    `Str::isUuid()` first, never `text()`.
  - pdo_dblib on SQL Server converts bound text to code page 1252.
  - A legacy `utf8mb3` column fails on an emoji search on MySQL and MariaDB.
  - GROUP BY or DISTINCT together with the key tiebreak fails on PostgreSQL and MySQL.
  - SQL Server's legacy `smalldatetime` (section 3).

## 3. The date filter

```php
->date('from', 'orders.created_at', '>=')   // that day or later
->date('to', 'orders.created_at', '<=')     // that day or earlier, through its last instant
->date('on', 'orders.created_at')           // '=' (the default): that one day
```

**Narrowing.** The value is what `<input type="date">` sends.

1. It goes through the text narrowing: a string of valid UTF-8, with no NUL byte, trimmed by `Str::trim`.
2. It must match `/^\d{4}-\d{2}-\d{2}$/D`, pass `checkdate()`, and be `>= '1753-01-01'`. The four-digit year implies
   the ceiling of `9999-12-31`.
3. Anything else is `null`, so the filter adds nothing.

`$listing->values[$key]` holds that `Y-m-d` string, so it refills `<input type="date">` with no formatting. A closure
receives the same string.

**SQL.** The range is half-open with plain string bindings, measured as the only form that is correct on all 7 date
column types across 5 engines:

- `'>='` adds `where(col, '>=', day)`.
- `'<='` adds `where(col, '<', next day)`.
- `'='` adds both.

The details:

- **Not `whereDate()`.** It scans instead of using the column's index on SQLite, MySQL and PostgreSQL.
- **Not a `DateTimeInterface` binding.** On SQLite, which compares dates as text, a `date` column then drops the
  boundary day.
- **`Ymd` on SQL Server.** On `SqlServerGrammar` the bound strings are `Ymd`. A British, German or French login reads
  `Y-m-d` for a legacy `datetime` as year-day-month: wrong rows, or a 500 for days over 12.
- **UTC arithmetic.** Both bounds come from one `new DateTimeImmutable($day, new DateTimeZone('UTC'))`, so a timezone
  that skipped a calendar day, such as Pacific/Apia on 2011-12-30, cannot shift either bound. In that zone, a plain
  `new DateTimeImmutable('2011-12-30')` is already 2011-12-31.
- **`9999-12-31` has no next day.** As an upper bound alone it adds `whereNotNull(col)` instead of `<`, so rows with
  a NULL date stay out, as they do for every other day.
- **The 1753 floor applies on every engine**, so behaviour is identical everywhere. It is where SQL Server's legacy
  `datetime` (Laravel's `timestamps()` there) starts. `?to=1700-01-01` reads as "no filter".
- **Operators:** only `'='`, `'>='` and `'<='`. Blade date inputs are inclusive, and adding `'>'` or `'<'` later
  would not break anything.

**Timezones** (one README paragraph). From and to are calendar days on the column's own clock:

- For `date`/`dateTime` columns everywhere, and `timestamp` outside MySQL and MariaDB, that clock is
  `config('app.timezone')`, because Eloquent writes wall-clock time.
- MySQL and MariaDB `timestamp`, and PostgreSQL `timestamptz`, resolve each day at midnight in the connection's time
  zone. Keep `database.connections.*.timezone` equal to `app.timezone`. A named zone on MySQL needs its time zone
  tables loaded.
- SQL Server's `timestampTz` (`datetimeoffset`) compares instants in UTC. Eloquent writes wall-clock time with a
  `+00:00` offset, so its own rows still fall on their `app.timezone` day. A row written elsewhere with a real offset
  falls on its UTC day.
- SQL Server's legacy `datetime` keeps 1/300 of a second, so a write at `23:59:59.999` is stored as the next day's
  midnight (`.997` and `.998` stay on the same day). This is SQL Server storage, not the filter; use
  `timestamps(precision: 3)` (`datetime2`) where it matters.

**Not supported, and documented:** SQL Server's legacy `smalldatetime` (1900-01-01 to 2079-06-06). Filter it with a
closure.

## 4. Testing

The package suite runs on PHPUnit 12 with Testbench. By default it uses Testbench's in-memory SQLite. The PostgreSQL
and MySQL CI legs run the same suite against their service containers by setting Laravel's own `DB_CONNECTION`,
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`, which Testbench reads. The suite has no
database variable of its own, and `tests/TestCase.php` never pins a connection; it only drops all tables between
tests.

**Unit tests**, through `Listing` where possible:

- the narrowing of each filter type, including empty values and the closure escape hatch
- sort parsing, additive `sorts()`, `sortUrl()` (`page` and `cursor` dropped, unknown name) and `ariaSort()`
- the order on a join, on a relation and over a `latest()`
- the LIKE: wildcards, letter case, the OR grouping, and a search over a number column (PostgreSQL's `::text` cast)
- `values`
- an `apply()` that is safe to call twice, and that returns a relation for a relation
- the page cap and the default page size
- the date filter's whole days, edges, NULL rows and a skipped-day timezone
- the fails-loudly cases: duplicate key, non-backed enum, bad date operator, unknown `sortUrl()` name

**One shared hostile-input data provider**, run end to end against a fixture screen. Each case must return 200 with
the default rows, and the date inputs must refill empty, so that a wrongly accepted value cannot pass unseen:

- arrays and nested arrays
- `%FF`, an inner NUL, and values of 300 characters or more
- `99999999999999999999`, `--total` and `sort[]=x`
- unknown enum values, string- and int-backed (`status=nope`, `role=1x`), and `paid=on`
- `page=9223372036854775807` and `page[]=2`
- impossible and out-of-range dates

**Assert rows and behaviour, not SQL strings**, so that the same suite runs on every engine. There are two stated
exceptions: bindings tests for the SQL Server-only branches, which are their only guard while SQL Server is not in CI.
They cover the `Ymd` day bounds and the `[` escape in the LIKE pattern.

**CI**, in `.github/workflows/tests.yml`:

- **SQLite:** PHP 8.4 and 8.5, each with the lowest and the stable dependencies. The lowest leg sets
  `COMPOSER_POLICY_ADVISORIES_BLOCK=0`, so that it installs the declared Laravel 13.0 floor rather than the first
  release without a security advisory. Composer's malware and abandoned-package blocking stays on.
- **PostgreSQL 18 and MySQL 8.4:** service containers running the same suite on PHP 8.5 with the stable dependencies,
  selected through the `DB_*` variables.
- `actions/checkout` moves to its current major version.

**Release checklist.** These are not tasks of the implementation plan; the README's Testing section gives their
commands:

- the same suite through `DB_CONNECTION` against SQL Server 2022, through `pdo_sqlsrv` and not `pdo_dblib`
- the same suite against MariaDB 11.8
- a mutation pass on SQLite and PostgreSQL: with each guard reverted, some test must fail on at least one of them

## 5. Packaging and cleanup

- **`composer.json`:**
  - Autoload `Listing\` → `src/`, and `Listing\Tests\` → `tests/`, as today.
  - PHP `^8.4` and Laravel `^13.0`.
  - The description and keywords describe the builder.
  - Remove four keys:
    - `type` and `minimum-stability`, which restate Composer's defaults (`library`, `stable`)
    - `prefer-stable`, which does nothing while the minimum stability is `stable`
    - `support.issues`, which Packagist fills in from GitHub
  - Keep only the `test` and `check` scripts.
- **README:** rewritten around `Listing::for()`. It covers:
  - a controller example
  - the builder table
  - sorts and aliases
  - the view, with the copy-paste anonymous header component
  - date ranges and their timezones
  - custom filters (closures)
  - the reuse pattern
  - `#[Authorize]`
  - paging: `paginate()`, `simplePaginate()` and `cursorPaginate()`, and `apply()` without paging
  - engine notes:
    - SQL Server drivers
    - `utf8mb3`
    - `smalldatetime`
    - GROUP BY or DISTINCT with the key tiebreak
    - PostgreSQL `integer` and `uuid` columns
    - SQLite and misspelled bare columns
  - a short trap list:
    - never refill the form from `request()`
    - never use Laravel's `enum()`, `date()`, `boolean()`, `string()` or `integer()` on list input
    - `sort`, `page` and `cursor` are reserved
- **Ponytail cuts that survive the redesign:**
  - `pint.json`: drop `"preset": "laravel"`, which is Pint's default, and the 7 rules the Laravel preset already
    sets: `single_line_empty_body`, `ordered_imports`, `no_unused_imports`, `not_operator_with_successor_space`,
    `type_declaration_spaces`, `nullable_type_declaration_for_default_null_value` and
    `class_attributes_separation`. Keep `fully_qualified_strict_types` while the lowest leg installs Pint 1.27, whose
    preset sets it to `false`.
  - `phpunit.xml`: drop the `LOG_DEPRECATIONS_WHILE_TESTING` block and the redundant `bootstrap`.
  - `.gitattributes`: drop the comment, and add `/docs export-ignore`.
  - Larastan stays, as the Laravel norm.
- **Process:**
  - The owner commits the 2026-10-01 verified fixes as a checkpoint before implementation starts. Claude never
    commits, pushes or tags.
  - The release tag and the Packagist submission stay with the owner.

## Consumers

**exe-laravel** requires the package from Packagist (`^0.2`, locked at v0.2.0 as of 2026-10-03), so this working
tree does not reach it. Its five list screens build their listings on rule-less form requests (`Concerns/FiltersLinks`
shares the links filters). Moving it to 0.4 turns `->id($key, …)` into `->int($key, …)` and a bare `->id()` into
`->int('id')`.

## Migrating from the 2026-10-01 API

| Before | After |
|---|---|
| a `ListingRequest` subclass and its `applyTo()` | a `Listing::for()` chain in the controller |
| `text()`, `id()`, `flag()`, `choice()` accessors | `->text()`, `->int($key)`, `->flag()`, `->enum()` declarations, read through `$listing->values` |
| `sortable()` and `defaultSort(): Sort` | `->sorts(...)` and `->defaultSort('-created_at')` |
| `?order=total&dir=asc` | `?sort=total`; `?sort=-total` for descending |
| `Like::contains($query, $columns, $term)` | `->search($key, $columns)` |
| `request()->fullUrlWithQuery($sort->toggle('total'))`, `$sort->ariaSort('total')` | `$listing->sortUrl('total')`, `$listing->ariaSort('total')` |
| `PER_PAGE` and `perPage()` | the model's `$perPage`, or `paginate(25)` |
| cursor pagination over the query | `$listing->cursorPaginate()` |

## Out of scope for this round

- Laravel 12 and PHP 8.3 support
- multi-column sort (`?sort=a,-b`)
- a packaged Blade component
- a standalone public LIKE helper; it can return later as a builder method if a screen needs one
- `uuid()` or `ulid()` filter methods; use a closure with `Str::isUuid()` or `Str::isUlid()`
- `'>'` and `'<'` date operators, and a per-viewer timezone (a closure covers it)
- anything API- or JSON:API-shaped
