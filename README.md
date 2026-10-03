# laravel-listing

Easy querying and filtering for Blade list pages. Declare, once, in the controller, the filters and sorts a page's
query string may set. The package reads them leniently, so a mistyped id, a stale bookmark or a hostile URL shows
the default list, never a 4xx or 5xx.

Requires PHP 8.4 and Laravel 13.

## Install

```bash
composer require ismailnakkar/laravel-listing
```

That is the whole install: no service provider, no config, no views.

## A listing

```php
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Listing\Listing;

final class OrderController
{
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
            ->sorts('reference', 'total', 'created_at', customer: 'customer_name')
            ->defaultSort('-created_at');

        return view('orders.index', ['orders' => $listing->paginate(), 'listing' => $listing]);
    }
}
```

`?status=shipped&paid=0&q=lamp&from=2026-10-01&sort=-total&page=2` is the second page of unpaid, shipped orders
matching "lamp" since October 1st, largest first. `paginate()` takes a page size, else the model's `$perPage`. Its
links keep the query string. A `?page` that is not a positive whole number, or so large that its offset would overflow,
shows the first page; a page past the last one is empty, as in Laravel. For large tables, `simplePaginate()` and
`cursorPaginate()` skip the COUNT; see [Paging](#paging).

### Filters

| Method | Reads `?key=` as | Keeps the rows where |
| --- | --- | --- |
| `id($key = 'id', $column = null, $max = PHP_INT_MAX)` | a positive `int` up to `$max` | the column is it |
| `text($key, $column = null, $max = 255)` | the trimmed string; empty is `null` | the column is it |
| `flag($key, $column = null)` | `'1'` as `true`, `'0'` as `false` | the column is it |
| `enum($key, Enum::class, $column = null)` | the backed case; an int-backed enum reads digits only | the column is it |
| `enums($key, Enum::class, $column = null)` | a list of backed cases: `?key[]=a&key[]=b`, or one `?key=a` | the column is any of them |
| `ids($key, $column = null, $max = PHP_INT_MAX)` | a list of positive `int`s up to `$max` | the column is any of them |
| `search($key, $columns, $max = 255)` | the trimmed string; empty is `null` | any of the columns contains it, as typed |
| `date($key, $column = null, $operator = '=')` | a real day, `2026-10-01` | the column falls on it (`=`), on or after it (`>=`), or on or before it (`<=`) |

- A value that does not read as its type is `null` and filters nothing, so the empty fields a form sends filter
  nothing either. `enums()` and `ids()` hold a list instead: values that do not read are dropped, none is an empty
  list, which filters nothing, and more than 1000 values read as an empty list.
- The column defaults to the key, as a column of the listed model's table, like a positional sort: `enum('status')` on
  orders compares `orders.status`, so it stays unambiguous on a join. A column you pass is used as written: name a
  joined or pivot column in full (`customers.email`, `team_user.role`).
- `flag()` reads `0` as `false` on purpose: "No" is an answer, not an absent filter. A checkbox needs `value="1"`;
  without it the browser sends `on`, which reads as `null`.
- Each key is declared once; declaring it again throws.
- Only the URL's query string is read: a form or JSON body never sets a filter, a sort or a page.

### Search

`search()` keeps the rows where any of the columns contains the term as typed: `%`, `_`, `!` and `[` match
themselves.

- **Letter case** does not matter as far as the database lowercases. SQLite and a PostgreSQL `C` locale lowercase
  ASCII letters only, and MySQL's and MariaDB's default collation ignores accents too.
- **PostgreSQL** casts each column to text, so a number, date or uuid column can be searched.
- **SQL Server** turns a legacy `datetime` column, which `timestamps()` creates there, into text such as
  `Oct  1 2026 12:00AM`, so a `2026-10-01` term misses. Filter days with `date()`, not `search()`.

### Date ranges

```php
->date('from', 'orders.created_at', '>=')
->date('to', 'orders.created_at', '<=')
```

Both days count, whole. `?from=2026-10-01&to=2026-10-31` keeps `created_at >= '2026-10-01'` and `< '2026-11-01'`, so
all of the 31st is in, and an index on the column serves the range. A day before 1753-01-01, where SQL Server's
`datetime` starts, reads as `null`.

From and to are days on the column's own clock:

- For `date` and `dateTime` columns, and `timestamp` outside MySQL and MariaDB, that is `app.timezone`, the clock
  Eloquent writes in.
- MySQL's and MariaDB's `timestamp` and PostgreSQL's `timestamptz` start a day at midnight in the connection's time
  zone, so keep `database.connections.*.timezone` equal to `app.timezone`. A named zone on MySQL needs its time zone
  tables loaded.
- SQL Server's `timestampTz` (`datetimeoffset`) compares instants in UTC. Eloquent's own rows still fall on their
  `app.timezone` day; a row written elsewhere with a real offset falls on its UTC day.

### Sorts

`sorts()` names what `?sort=` may use: `?sort=total` is ascending, `?sort=-total` descending.

- A positional name is a column of the listed model's table.
- A named argument gives a select alias or another column a name of its own: `customer: 'customer_name'`,
  `orders: 'orders_count'`. Name a joined column by its select alias, as here: `cursorPaginate()` reads each row's
  sort value by that name, so a joined column named in full (`customers.name`) pages wrong there.
- Every call adds names, so a sort can depend on the user: `->when($user->isAdmin(), fn (Listing $l) => $l->sorts('margin'))`.

How the order is chosen:

- Anything else in `?sort=` gives `defaultSort()`, or else the model's key, descending.
- The sort replaces any order the query had, so a relation's own `latest()` cannot outrank the header.
- Ties then order by the model's key in the same direction, so rows with equal values keep their place from one page
  to the next.

Keep the list narrow: each column needs an index, or the page scans on every click. On PostgreSQL the index has to
cover the column and the key.

### A relation

`Listing::for()` takes a relation as well as a query, and pages it as itself, so each row keeps its `pivot`:

```php
$listing = Listing::for($team->members(), $request)->text('role', 'team_user.role')->sorts('name');
```

### A filter the package does not cover

Pass a closure in place of the column. It runs only when there is a value, and gets the query and the typed value:

| Filter | Value the closure gets |
| --- | --- |
| `id()` | an `int` |
| `flag()` | a `bool` |
| `enum()` | the case |
| `enums()` | a non-empty list of cases |
| `ids()` | a non-empty list of `int`s |
| `text()` and `date()` | a `string` |

```php
use Illuminate\Database\Eloquent\Builder;

->id('team', fn (Builder $query, int $team) => $query->whereHas('teams', fn (Builder $q) => $q->whereKey($team)))
```

On a relation, the closure's query is the relation's own, so name a pivot column in full there too. A closure that
parses its value further must not hand the database an impossible value.

## The view

```blade
<form method="get">
    <input type="search" name="q" value="{{ $listing->values['q'] }}">
    <select name="status">
        <option value="">Any status</option>
        @foreach (\App\Enums\OrderStatus::cases() as $status)
            <option value="{{ $status->value }}" @selected($listing->values['status'] === $status)>{{ $status->name }}</option>
        @endforeach
    </select>
    <select name="paid">
        <option value="">Paid: any</option>
        <option value="1" @selected($listing->values['paid'] === true)>Paid: yes</option>
        <option value="0" @selected($listing->values['paid'] === false)>Paid: no</option>
    </select>
    <input type="date" name="from" value="{{ $listing->values['from'] }}">
    <input type="date" name="to" value="{{ $listing->values['to'] }}">
    <input type="hidden" name="sort" value="{{ $listing->sort }}">
    <button>Filter</button>
</form>
```

Refill the form from `$listing->values`, never from `request()`: `{{ request('q') }}` fails the page on `?q[]=x`.
Every declared key is there, holding its typed value or `null`, so compare with `===`: an `enum()` holds the case, a
`flag()` a `bool`. The hidden `sort` keeps the order when the form is sent.

For `enums()` or `ids()`, the field sends a list, from a multiple select or checkboxes named `status[]`, and the value
is a list, so the refill tests membership:

```blade
<select name="status[]" multiple>
    @foreach (\App\Enums\OrderStatus::cases() as $status)
        <option value="{{ $status->value }}" @selected(in_array($status, $listing->values['status'], true))>{{ $status->name }}</option>
    @endforeach
</select>
```

A sortable header:

```blade
<th @if ($listing->ariaSort('total')) aria-sort="{{ $listing->ariaSort('total') }}" @endif>
    <a href="{{ $listing->sortUrl('total') }}">Total</a>
</th>
```

- `sortUrl()` flips the sorted column and starts any other descending. It keeps the query string and drops `page`
  and `cursor`.
- `sortUrl()` throws for a name `sorts()` does not have, so a header for a sort that only some users have needs the
  same condition in the view.
- `ariaSort()` is `null` on a column the list is not sorted by.

To make each header one line, save this as `resources/views/components/sort-header.blade.php`:

```blade
@props(['listing', 'column'])
<th @if ($listing->ariaSort($column)) aria-sort="{{ $listing->ariaSort($column) }}" @endif {{ $attributes }}>
    <a href="{{ $listing->sortUrl($column) }}">{{ $slot }}</a>
</th>
```

```blade
<x-sort-header :listing="$listing" column="total">Total</x-sort-header>
```

## Paging

| Method | Gives | Use it for |
| --- | --- | --- |
| `paginate($perPage = null)` | a page with a total and page links | most screens |
| `simplePaginate($perPage = null)` | previous and next only, with no COUNT query | a table too large to count |
| `cursorPaginate($perPage = null)` | previous and next by cursor, with no COUNT and no OFFSET | a very large table |

Each takes a page size, else the model's `$perPage`, and its links keep the query string.

- **`?page`** reads like an id. One that is not a positive whole number, or so large its offset would overflow, shows
  the first page. Laravel's own resolver takes `?page=9223372036854775807`, a 500 on PHP 8.5.
- **Every other paginator.** Call `Listing::protectAllPages()` once, in a service provider's `boot()`, and Laravel's
  own `paginate()` and `simplePaginate()` read `?page` the same way, from the query string only, for page sizes up to
  10,000.
- **`?cursor`** comes from the query string like every other parameter. Laravel throws for a cursor from another sort
  order, such as a bookmark from before a deploy that changed the order, and for one holding a null. PostgreSQL and
  SQL Server also reject a hand-edited value the column cannot hold. `cursorPaginate()` shows the first page for each;
  an error that is not the cursor's throws again from the retry.
- **NULL sort values.** As in Laravel, a page that ends on a NULL sort value links back to the first page, and in the
  other direction the NULL rows never show. Give a cursor screen NOT NULL sort columns; `timestamps()` columns are
  nullable.
- **A joined column** pages by its select alias (see [Sorts](#sorts)).
- **A statement timeout.** On MySQL and MariaDB 12 or later, start the query with Laravel's `->timeout($seconds)`: the
  listing keeps it. Other databases ignore that call, so set their statement timeout around `cursorPaginate()`.

### Without paging

`apply()` is the filtered, sorted query without paging:

- an export, with `->lazy()`
- a count, a sum or a second query on the same filters

A relation stays a relation. Page through the three methods above rather than `apply()`: they carry the page cap and
the cursor guard.

## Reusing a listing

```php
final class OrderController
{
    public function index(Request $request)
    {
        $listing = self::listing($request);

        return view('orders.index', ['orders' => $listing->paginate(), 'listing' => $listing]);
    }

    public function export(Request $request)
    {
        return OrdersExport::of(self::listing($request)->apply()->lazy());
    }

    /** @return Listing<Order> */
    private static function listing(Request $request): Listing
    {
        return Listing::for(Order::query(), $request)->enum('status', OrderStatus::class)->sorts('total');
    }
}
```

## Authorization

The listing only reads the query string. Who may see the page is the route's business: put Laravel 13's
`#[Authorize('viewAny', Order::class)]` on the controller or action, or `->can('viewAny', Order::class)` on the route.

## Traps

- Refill the form from `$listing->values`, never from `request()`.
- Do not read list input with Laravel's `enum()`, `date()`, `boolean()`, `string()` or `integer()` on query strings:
  - Some throw: `enum()` on an int-backed enum, `date()` on garbage, `string()` on an array.
  - Others misread: `boolean('on')` is `true`, and `integer('12abc')` is `12`.
- `sort`, `page` and `cursor` are the listing's query-string names.
- **A misspelled bare column.** MySQL, MariaDB, PostgreSQL and SQL Server reject it. SQLite reads an unknown bare
  name as a string, so there it matches nothing, or every row in a search, with no error. Test on the database you
  deploy, or name the column in full (`orders.status`), which SQLite does check.

## Engine notes

- **PostgreSQL** raises an error, instead of matching nothing, when a value does not fit the column's type:
  - Give `id()` and `ids()` on an `integer` (not `bigInteger`) column `max: 2147483647`; one `ids()` value out of range
    fails the whole list.
  - Filter a `uuid` column with a closure that checks `Str::isUuid()` first.
- **GROUP BY or DISTINCT** listings fail on PostgreSQL and MySQL, because the key tiebreak orders by a column that is
  neither grouped nor selected.
- **SQL Server.** Use `pdo_sqlsrv`. Through `pdo_dblib`, SQL Server turns bound text into code page 1252 (so `％`
  becomes `%`) and returns NULL instead of raising conversion errors. Beyond the driver:
  - The legacy `smalldatetime` type (1900-01-01 to 2079-06-06) is not supported by `date()`; use a closure.
  - A legacy `datetime` stores a write at `23:59:59.999` as the next day's midnight; use `timestamps(precision: 3)`
    where that matters.
- **MySQL and MariaDB:** a legacy `utf8mb3` column fails on an emoji search; use `utf8mb4`.

## Testing

```bash
composer test     # phpunit, on in-memory SQLite
composer check    # pint --test, phpstan, then phpunit
```

The same suite runs on another engine through Laravel's own variables, for example PostgreSQL:

```bash
docker run -d --name listing-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=listing -p 127.0.0.1:5432:5432 postgres:18
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=listing DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/phpunit
```

CI runs SQLite on PHP 8.4 and 8.5, and PostgreSQL 18 and MySQL 8.4. Before a release, also run the suite against:

- MariaDB 11.8 (`DB_CONNECTION=mariadb`)
- SQL Server 2022 (`DB_CONNECTION=sqlsrv`) from a PHP with `pdo_sqlsrv`; through `pdo_dblib`, conversion errors
  vanish

Then do a mutation pass on SQLite and PostgreSQL: revert each guard, and some test must fail.

## Deliberately not here

Query DSLs, multi-column sort and JSON APIs. For an API, use spatie/laravel-query-builder. A list page that needs
more reads `$listing->values` and writes it.
