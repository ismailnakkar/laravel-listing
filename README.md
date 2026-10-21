# laravel-listing

Typed, lenient query-string filters and sorts for Blade list pages. Declare them once in the controller; a mistyped id,
a stale bookmark or a hostile URL shows the default list, never a 4xx or 5xx.

## Install

```bash
composer require ismailnakkar/laravel-listing
```

Requires PHP 8.4 and Laravel 13. No service provider, config or views.

## Usage

```php
use App\Enums\OrderStatus;
use App\Models\Order;
use Listing\Listing;

public function index()
{
    $orders = Order::query()
        ->join('customers', 'customers.id', '=', 'orders.customer_id')
        ->select('orders.*', 'customers.name as customer_name');

    $listing = Listing::for($orders)
        ->enum('status', OrderStatus::class)
        ->flag('paid')
        ->search('q', ['orders.reference', 'customers.email'])
        ->date('from', 'orders.created_at', '>=')
        ->date('to', 'orders.created_at', '<=')
        ->sorts('reference', 'total', 'created_at', customer: 'customer_name')
        ->defaultSort('-created_at');

    return view('orders.index', ['orders' => $listing->paginate(), 'listing' => $listing]);
}
```

`?status=shipped&paid=0&q=lamp&from=2026-10-01&sort=-total&page=2` is the second page of unpaid, shipped orders
matching "lamp" since October 1st, largest first.

The listing reads the current request, or one passed second, and its page links follow the same request. Only the query
string is read: a form or JSON body never sets a filter, a sort or a page. `sort`, `page` and `cursor` are taken, so do
not use them as keys.

### Filters

| Method | Reads `?key=` as | Keeps rows where |
| --- | --- | --- |
| `int($key, $column = null, $max = PHP_INT_MAX)` | a whole number, `0` to `$max` | the column equals it |
| `text($key, $column = null, $max = 255)` | the trimmed string, up to `$max` characters | the column equals it |
| `flag($key, $column = null)` | `'1'` as `true`, `'0'` as `false` | the column equals it |
| `enum($key, Enum::class, $column = null)` | the backed case; an int-backed enum reads digits only | the column equals it |
| `enums($key, Enum::class, $column = null)` | a list of cases: `?key[]=a&key[]=b`, or `?key=a` | the column is any of them |
| `ints($key, $column = null, $max = PHP_INT_MAX)` | a list of whole numbers, `0` to `$max` | the column is any of them |
| `search($key, $columns = null, $max = 255)` | the trimmed string, up to `$max` characters | any column contains it |
| `date($key, $column = null, $operator = '=')` | a real day, `2026-10-01`, from `1753-01-01` | the column is on (`=`), on or after (`>=`) or on or before (`<=`) it |

- A value that does not read, an empty form field included, is `null` and filters nothing. `enums()` and `ints()` drop
  what does not read and hold a list, empty when nothing reads; more than 1000 values read as empty.
- The column defaults to the key on the listed model's table (`orders.status`), so it stays unambiguous on a join. A
  column you pass is used as written: name a joined or pivot column in full (`customers.email`, `team_user.role`).
- A `flag()` checkbox needs `value="1"`; without it the browser sends `on`, which reads as `null`.
- Declaring a key twice throws.

### Search

`%`, `_`, `!` and `[` in a term match themselves. Letter case follows the column's collation on MySQL, MariaDB and SQL
Server (a `_bin` or `_cs` column matches case exactly); PostgreSQL uses `ILIKE`, and SQLite ignores ASCII case.
PostgreSQL casts each column to text, so a number, date or uuid column can be searched.

An expression, alone or in the list, is searched as written. It is raw SQL: never build one from input.

```php
->search('q', DB::raw('customers.email collate utf8mb4_unicode_ci'))
```

### Date ranges

`?from=2026-10-01&to=2026-10-31` keeps `created_at >= '2026-10-01'` and `< '2026-11-01'`: both days count, whole, and
an index on the column serves the range. Days are on the column's own clock:

- `date`, `dateTime`, and `timestamp` outside MySQL and MariaDB: `app.timezone`, the clock Eloquent writes in.
- MySQL's and MariaDB's `timestamp`, PostgreSQL's `timestamptz`: the connection's time zone, so keep
  `database.connections.*.timezone` equal to `app.timezone` (a named zone on MySQL needs its time zone tables loaded).
- SQL Server's `timestampTz` (`datetimeoffset`): UTC.

### Sorts

`?sort=total` is ascending, `?sort=-total` descending. In `sorts()`, a positional name is a column of the listed model's
table, and a named argument maps a name to a select alias or another column (`customer: 'customer_name'`). Name a
joined column by its select alias: `cursorPaginate()` reads each row's sort value by that name. Each call adds names,
so a sort can depend on the user: `->when($user->isAdmin(), fn (Listing $l) => $l->sorts('margin'))`.

Any other `?sort=` gives `defaultSort()`, else the model's key, descending. The sort replaces any order the query had,
a relation's own `latest()` included, and ties order by the key in the same direction. Index each sortable column; on
PostgreSQL, the index must cover the column and the key.

### Relations

`Listing::for()` takes a relation too, and pages it as itself, so each row keeps its `pivot`:

```php
Listing::for($team->members())->text('role', 'team_user.role')->sorts('name');
```

### Custom filters

Every filter but `search()` takes a closure in place of the column. It runs only when there is a value, and gets the
query and that value (a non-empty list for `enums()` and `ints()`).

```php
use Illuminate\Database\Eloquent\Builder;

->int('team', fn (Builder $query, int $team) => $query->whereHas('teams', fn (Builder $q) => $q->whereKey($team)))
```

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

Refill the form from `$listing->values`, never from the request: `{{ request('q') }}` fails the page on `?q[]=x`, and
Laravel's request helpers (`enum()`, `date()`, `boolean()`, `string()`, `integer()`) throw on or misread such input.
Every declared key is there, holding its typed value or `null`, so compare with `===`. The hidden `sort` keeps the order
when the form is sent.

For `enums()` and `ints()`, name the field `status[]` and test membership:

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

`sortUrl()` flips the sorted column and starts any other descending; it keeps the query string and drops `page` and
`cursor`. It throws for a name `sorts()` lacks, so a header for a user-dependent sort needs the same condition.

## Paging

| Method | Gives |
| --- | --- |
| `paginate($perPage = null)` | a page with a total and page links |
| `simplePaginate($perPage = null)` | previous and next, with no COUNT query |
| `cursorPaginate($perPage = null)` | previous and next by cursor, with no COUNT and no OFFSET |

Each takes a page size, else the model's `$perPage`, and its links keep the query string.

- A `?page` that is not a positive whole number, or so large its offset would overflow, shows the first page.
- Call `Listing::protectAllPages()` once, in a service provider's `boot()`, and Laravel's own `paginate()` and
  `simplePaginate()` read `?page` the same way, from the query string only, for page sizes up to 10,000.
- A `?cursor` from another sort order, holding a null, or holding a value the column rejects shows the first page; any
  other database error throws.
- As in Laravel, cursor paging skips or repeats rows at a NULL sort value: give a cursor screen NOT NULL sort columns;
  `timestamps()` columns are nullable.

`apply()` is the filtered, sorted query without paging, for an export with `->lazy()`, a count or a second query; a
relation stays a relation. Page with the methods above, not `apply()`: they carry the page cap and the cursor guard.

## Form requests

Give the request no `rules()`: a failing rule redirects, so a URL the listing would ignore bounces. To build the listing
once, keep it in a declared property (`private ?Listing $listing = null;`, then
`return $this->listing ??= Listing::for($query, $this)->…;`): an undeclared `$this->listing` reads `?listing=`.

## Database notes

- **SQLite** reads a misspelled bare column as a string, so it matches nothing, or every row in a search, with no
  error. Test on the database you deploy, or name columns in full (`orders.status`).
- **PostgreSQL** fails, instead of matching nothing, on a value the column's type cannot hold. Give `int()` and
  `ints()` on an `integer` column `max: 2147483647`, and filter a `uuid` column with a closure that checks
  `Str::isUuid()` first.
- **GROUP BY or DISTINCT** listings fail on PostgreSQL and MySQL: the key tiebreak orders by a column that is neither
  grouped nor selected.
- **SQL Server** needs `pdo_sqlsrv`: `pdo_dblib` turns bound text into code page 1252 (`％` becomes `%`) and returns
  NULL instead of raising conversion errors. `date()` does not support `smalldatetime`; use a closure. `search()`
  misses a `2026-10-01` term in a `datetime` column (what `timestamps()` creates there), which reads as
  `Oct  1 2026 12:00AM`; filter days with `date()`.
- **MySQL and MariaDB:** a `utf8mb3` column fails on an emoji search; use `utf8mb4`.

## Testing

Apps need no fake or setup; outside an HTTP call, pass the request: `Listing::for($query, Request::create('/?q=lamp'))`.
The package's suite:

```bash
composer check    # pint --test, phpstan, then phpunit on in-memory SQLite
docker run -d --name listing-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=listing -p 127.0.0.1:5432:5432 postgres:18
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=listing DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/phpunit
```

Before a release, run SQL Server 2022 (`DB_CONNECTION=sqlsrv`, `pdo_sqlsrv`) by hand, and a mutation pass on SQLite and
PostgreSQL: revert each guard, and some test must fail.

## Upgrading

### From 0.5

- `?key=0` on `int()` and `ints()` now keeps the rows whose column is `0` instead of filtering nothing. An "any"
  option or link that sends `0` must send an empty value.

## Licence

MIT.
