# Listing Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `ListingRequest` / `Sort` / `Like` API with one public class, `Listing\Listing`: a fluent
`Listing::for($query, $request)` builder for Blade list pages whose filters and sorts never bounce the page.

**Architecture:**

- `Listing` is the only public class. It holds:
  - the query, an Eloquent builder or a relation
  - the request
  - the declared filters and sorts
- It hands the view `values`, `sort`, `sortUrl()` and `ariaSort()`.
- Two `@internal` classes do the work:
  - `Filter` narrows one query-string value to a type and adds its condition. The portable LIKE and the day range
    live here.
  - `Sort` parses `?sort=[-]name`, orders a query with a key tiebreak, and toggles.
- `Like.php` and `ListingRequest.php` are deleted.

**Tech Stack:** PHP 8.4+ (asymmetric visibility), Laravel 13, PHPUnit 12, Orchestra Testbench 11, Larastan (PHPStan
level 6), Pint.

**Spec:** `docs/superpowers/specs/2026-10-02-listing-builder-design.md`. Read it before starting; this plan argues
from it.

## Global Constraints

- PHP `^8.4` and Laravel `^13.0`; the dev dependencies stay as they are.
- Namespace `Listing\` → `src/`; tests `Listing\Tests\` → `tests/`.
- `Listing` is the only public class. `Filter` and `Sort` are `@internal`. `src/Like.php` and `src/ListingRequest.php`
  are deleted.
- Never bounce: no query-string value may cause a 4xx or 5xx, apart from the column-type and engine traps in spec
  section 2.
- Filter columns are used as written. Positional sort names are qualified with the model's table. Aliases are used as
  written.
- Every PHP file starts with `declare(strict_types=1);`, classes are `final`, and style follows `pint.json`
  (`vendor/bin/pint` fixes it).
- Tests assert rows and behaviour, not SQL strings. The two exceptions are the SQL Server binding tests: the `Ymd`
  day bounds and the `[` escape.
- The project ships no container, so every command runs on the host: `vendor/bin/phpunit`, `vendor/bin/pint`,
  `vendor/bin/phpstan analyse`, `composer …`.
- **Never run `git commit`, `git push`, `git tag`, `git merge` or `git rebase`.** Leave every change in the working
  tree; the owner commits. No attribution trailers anywhere.
- `exe-laravel` symlinks this working tree and stays broken until the owner decides otherwise. Do not touch any file
  in `/Users/ismail/Projects/exe-laravel`.

## Review Focus

Inputs the spec implies but no feature test would naturally hit, ranked by how likely each is to bite a user, with
the task whose tests pin it:

1. **An all-empty form submission.** `?q=&status=&paid=&from=&to=&sort=` must show the default list with every value
   `null`. Pinned in Task 6, `test_an_empty_form_submission_shows_the_default_list`.
2. **Refilling selects.** A `flag()` set to `false` and an `enum()` case must refill their `<select>` through `===`.
   Pinned in Task 6, `test_the_form_refills_from_the_narrowed_values`.
3. **Pivot filters.** A relation filtered on a pivot column named in full must still page with `->pivot`. Pinned in
   Task 3, `test_a_relation_filters_on_a_pivot_column_named_in_full`.
4. **`sortUrl()` parameters.** It must keep unrelated parameters (`tab=`) and drop both `page` and `cursor`. Pinned
   in Task 1, `test_sort_url_flips_or_starts_descending_and_drops_page_and_cursor`.
5. **A default sort outside the allowlist.** It must order by the model's column, and its own hidden-input value must
   read as absent on submit and fall back to the same default. Pinned in Task 1,
   `test_a_default_outside_the_allowlist_orders_by_the_models_column`.

---

## Pre-flight (no code)

- [ ] **Check the owner's checkpoint.** Run `git -C /Users/ismail/Projects/laravel-listing log --oneline -3` and
  `git -C /Users/ismail/Projects/laravel-listing status --short`. The spec (section 5, Process) has the owner commit
  the 2026-10-01 fixes before this plan starts. If `src/` still shows modifications on top of `c6e236c init`, stop
  and tell the owner. They choose either to commit with the commands below, or to go ahead uncommitted. Do not commit
  yourself.

  ```bash
  git -C /Users/ismail/Projects/laravel-listing add -A
  git -C /Users/ismail/Projects/laravel-listing commit -m "Fix list-screen bugs found in cross-database verification; add builder design spec"
  ```

- [ ] **Baseline gate.** Run `cd /Users/ismail/Projects/laravel-listing && composer check`. Expect pint passed,
  phpstan `[OK] No errors`, and `OK (26 tests, 92 assertions)`.

---

### Task 1: `Sort`, the `Listing` core, the test harness, and removing the old API

**Files:**
- Delete: `src/ListingRequest.php`, `src/Like.php`, `tests/LikeTest.php`, `tests/ListingRequestTest.php`,
  `tests/SortTest.php`, `tests/Fixtures/ProductListing.php`
- Create: `src/Listing.php`, `src/Filter.php` (narrowing helpers only; filters come in Task 3),
  `tests/SortingTest.php`, `tests/Fixtures/Category.php`, `tests/Fixtures/Team.php`, `tests/Fixtures/Member.php`,
  `tests/Fixtures/Pure.php`
- Rewrite: `src/Sort.php`, `tests/TestCase.php`, `tests/Fixtures/Product.php`
- Keep: `tests/Fixtures/Status.php` (int-backed: `draft = 0`, `live = 1`), `tests/Fixtures/Kind.php` (string-backed:
  `book`, `film`)

**Interfaces:**
- Produces:
  - `Listing\Listing`:
    - `static for(Illuminate\Contracts\Database\Eloquent\Builder $query, Illuminate\Http\Request $request): self`
    - `sorts(string ...$columns): self`
    - `defaultSort(string $sort): self`
    - `apply(): Illuminate\Contracts\Database\Eloquent\Builder`
    - `sortUrl(string $name): string`
    - `ariaSort(string $name): ?string`
    - `public private(set) string $sort`
    - `public private(set) array $values`
    - `use Illuminate\Support\Traits\Conditionable`
  - `Listing\Filter` (`@internal`):
    - `static cleanString(mixed $input, int $max = 255): ?string`
    - `static positiveInt(mixed $input, int $max = PHP_INT_MAX): ?int`
  - `Listing\Sort` (`@internal`):
    - `__construct(string $name, string $direction)`
    - `static parse(string $sort): self`
    - `toggled(string $name): self`
    - `apply(Builder $query, string $column): void`
    - `__toString(): string`
    - public readonly `$name` and `$direction`
  - `Listing\Tests\TestCase`: drops all tables, then creates `categories`, `products`, `teams`, `members` and
    `member_team`.

- [ ] **Step 1: Delete the old API and its tests**

```bash
cd /Users/ismail/Projects/laravel-listing
rm src/ListingRequest.php src/Like.php tests/LikeTest.php tests/ListingRequestTest.php tests/SortTest.php tests/Fixtures/ProductListing.php
```

- [ ] **Step 2: Write the test harness and the fixtures**

`tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Testbench on in-memory SQLite by default; Laravel's own DB_CONNECTION and DB_* variables run the same suite on a
 * server database, so tables are dropped first and nothing pins a connection.
 */
abstract class TestCase extends Testbench
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->string('name');
            $table->string('reference')->nullable();
            $table->string('kind')->nullable();
            $table->unsignedTinyInteger('status')->nullable();
            $table->boolean('paid')->default(false);
            $table->integer('price')->default(0);
            $table->date('day')->nullable();
            $table->date('day_cast')->nullable();
            $table->dateTime('at', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('member_team', function (Blueprint $table): void {
            $table->foreignId('member_id');
            $table->foreignId('team_id');
            $table->string('role');
            $table->timestamps();
        });
    }
}
```

`tests/Fixtures/Product.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    protected $guarded = [];

    /** `day_cast` is written the way Eloquent's date cast writes on SQLite: `2026-10-01 00:00:00`. */
    protected function casts(): array
    {
        return ['day_cast' => 'date'];
    }
}
```

`tests/Fixtures/Category.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Category extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
```

`tests/Fixtures/Member.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Member extends Model
{
    protected $guarded = [];
}
```

`tests/Fixtures/Team.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Team extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('role')->withTimestamps();
    }
}
```

`tests/Fixtures/Pure.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

enum Pure
{
    case one;
}
```

- [ ] **Step 3: Write the failing sorting tests**

`tests/SortingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Listing\Listing;
use Listing\Tests\Fixtures\Category;
use Listing\Tests\Fixtures\Member;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Team;

final class SortingTest extends TestCase
{
    /** Desk 30 (id 1), Floor 10 (id 2), Wall 20 (id 3), Table 10 (id 4), all in one category. */
    private function seedProducts(): void
    {
        $category = Category::create(['name' => 'Lamps']);

        foreach ([['Desk', 30], ['Floor', 10], ['Wall', 20], ['Table', 10]] as [$name, $price]) {
            Product::create(['category_id' => $category->id, 'name' => $name, 'price' => $price]);
        }
    }

    private function listing(string $query = ''): Listing
    {
        return Listing::for(Product::query(), Request::create('/products?' . $query));
    }

    /** @return list<string> */
    private function names(Listing $listing): array
    {
        return $listing->apply()->pluck('name')->all();
    }

    public function test_the_default_order_is_the_key_descending(): void
    {
        $this->seedProducts();

        $this->assertSame(['Table', 'Wall', 'Floor', 'Desk'], $this->names($this->listing()));
        $this->assertSame('-id', $this->listing()->sort);
    }

    /** Ties break on the qualified key in the same direction, so one index on the column and the key serves it. */
    public function test_sort_reads_an_allowed_name_in_either_direction(): void
    {
        $this->seedProducts();

        $ascending = $this->listing('sort=price')->sorts('name', 'price');
        $this->assertSame(['Floor', 'Table', 'Wall', 'Desk'], $this->names($ascending));
        $this->assertSame('price', $ascending->sort);

        $this->assertSame(['Desk', 'Wall', 'Table', 'Floor'], $this->names($this->listing('sort=-price')->sorts('price')));
    }

    public function test_anything_else_gives_the_default(): void
    {
        $this->seedProducts();

        foreach (['sort=password', 'sort=--price', 'sort[]=price', 'sort=', 'sort=-', 'sort=%FF', 'sort=' . str_repeat('a', 300)] as $query) {
            $listing = $this->listing($query)->sorts('price')->defaultSort('-name');

            $this->assertSame('-name', $listing->sort, $query);
            $this->assertSame(['Wall', 'Table', 'Floor', 'Desk'], $this->names($listing), $query);
        }
    }

    public function test_sorts_add_up_and_a_name_given_again_takes_its_new_column(): void
    {
        $this->seedProducts();

        $this->assertSame('price', $this->listing('sort=price')->sorts('name')->when(true, fn (Listing $l) => $l->sorts('price'))->sort);

        $relabelled = $this->listing('sort=label')->sorts(label: 'name')->sorts(label: 'price');
        $this->assertSame(['Floor', 'Table', 'Wall', 'Desk'], $this->names($relabelled));
    }

    public function test_until_sorts_is_called_every_sort_reads_as_absent(): void
    {
        $this->assertSame('-id', $this->listing('sort=price')->sort);
    }

    /** The hidden input sends `sort=name` back; it is not on the allowlist, so the same default applies again. */
    public function test_a_default_outside_the_allowlist_orders_by_the_models_column(): void
    {
        $this->seedProducts();

        $this->assertSame(['Desk', 'Floor', 'Table', 'Wall'], $this->names($this->listing()->sorts('price')->defaultSort('name')));
        $this->assertSame('name', $this->listing('sort=name')->sorts('price')->defaultSort('name')->sort);
    }

    /** On a join a bare `id` is ambiguous; SQLite only says so when the select names its columns, as here. */
    public function test_a_join_orders_by_qualified_columns(): void
    {
        $this->seedProducts();

        $query = Product::query()
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->select('products.name', 'categories.name as category');

        $default = Listing::for(clone $query, Request::create('/'))->sorts('price');
        $this->assertSame(['Table', 'Wall', 'Floor', 'Desk'], $default->apply()->pluck('name')->all());

        $byCategory = Listing::for(clone $query, Request::create('/?sort=category'))->sorts(category: 'categories.name');
        $this->assertSame(['Desk', 'Floor', 'Wall', 'Table'], $byCategory->apply()->pluck('name')->all());

        // The default resolves through the aliases too; as `products.category` it would fail on a missing column.
        $aliasDefault = Listing::for(clone $query, Request::create('/'))->sorts(category: 'categories.name')->defaultSort('-category');
        $this->assertSame(['Table', 'Wall', 'Floor', 'Desk'], $aliasDefault->apply()->pluck('name')->all());
    }

    public function test_a_relation_keeps_its_constraint_and_the_header_outranks_its_own_order(): void
    {
        $team = Team::create(['name' => 'Core']);

        foreach (['Ann' => 'owner', 'Bob' => 'editor', 'Cid' => 'editor'] as $name => $role) {
            $team->members()->attach(Member::create(['name' => $name]), ['role' => $role]);
        }

        Member::create(['name' => 'Zed']);

        $members = Listing::for($team->members()->latest('members.id'), Request::create('/?sort=name'))->sorts('name')->apply();

        $this->assertInstanceOf(BelongsToMany::class, $members);
        $this->assertSame(['Ann', 'Bob', 'Cid'], $members->get()->pluck('name')->all());
        $this->assertSame('owner', $members->get()->first()?->pivot?->role);
    }

    public function test_apply_works_on_a_clone(): void
    {
        $query = Product::query();

        Listing::for($query, Request::create('/?sort=price'))->sorts('price')->apply();

        $this->assertNull($query->getQuery()->orders);
    }

    public function test_sort_url_flips_or_starts_descending_and_drops_page_and_cursor(): void
    {
        $listing = Listing::for(Product::query(), Request::create('/products?tab=open&sort=price&page=3&cursor=abc'))->sorts('price', 'name');

        $this->assertSame('http://localhost/products?tab=open&sort=-price', $listing->sortUrl('price'));
        $this->assertSame('http://localhost/products?tab=open&sort=-name', $listing->sortUrl('name'));
        $this->assertSame('ascending', $listing->ariaSort('price'));
        $this->assertNull($listing->ariaSort('name'));

        $descending = Listing::for(Product::query(), Request::create('/products?sort=-price'))->sorts('price');
        $this->assertSame('http://localhost/products?sort=price', $descending->sortUrl('price'));
        $this->assertSame('descending', $descending->ariaSort('price'));
    }

    public function test_sort_url_for_a_name_outside_the_allowlist_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Listing::for(Product::query(), Request::create('/'))->sorts('price')->sortUrl('name');
    }
}
```

- [ ] **Step 4: Run the tests to watch them fail**

Run: `vendor/bin/phpunit tests/SortingTest.php`
Expected: errors with `Class "Listing\Listing" not found`.

- [ ] **Step 5: Write `src/Filter.php` (the narrowing helpers only)**

```php
<?php

declare(strict_types=1);

namespace Listing;

use Illuminate\Support\Str;

/**
 * @internal One declared filter: how its query-string value narrows to a type, and the condition it adds. A value that
 * does not narrow reads as null and adds nothing.
 */
final readonly class Filter
{
    /**
     * The trimmed string, or null when the input is not a string, is empty once trimmed, is longer than $max
     * characters, or holds bytes a database mishandles as text: invalid UTF-8 errors on PostgreSQL and matches every
     * row on MySQL, and a NUL cuts a LIKE pattern short on SQLite and PostgreSQL. Str::trim, so a pasted non-breaking
     * space goes too.
     */
    public static function cleanString(mixed $input, int $max = 255): ?string
    {
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            return null;
        }

        $input = Str::trim($input);

        return $input === '' || mb_strlen($input) > $max ? null : $input;
    }

    /** A positive integer up to $max, or null: digits only, because (int) 'nope' is 0, and in range, because (int) saturates. */
    public static function positiveInt(mixed $input, int $max = PHP_INT_MAX): ?int
    {
        $digits = ltrim(self::cleanString($input) ?? '', '0');
        $range = ['options' => ['max_range' => $max], 'flags' => FILTER_NULL_ON_FAILURE];

        return ctype_digit($digits) ? filter_var($digits, FILTER_VALIDATE_INT, $range) : null;
    }
}
```

- [ ] **Step 6: Rewrite `src/Sort.php`**

```php
<?php

declare(strict_types=1);

namespace Listing;

use Illuminate\Contracts\Database\Eloquent\Builder;

/** @internal `?sort=` as a name and a direction: `total` is ascending, `-total` descending. */
final readonly class Sort
{
    /** @param  'asc'|'desc'  $direction */
    public function __construct(
        public string $name,
        public string $direction,
    ) {}

    public static function parse(string $sort): self
    {
        return str_starts_with($sort, '-') ? new self(substr($sort, 1), 'desc') : new self($sort, 'asc');
    }

    /** What a header for $name links to: this sort flipped, or $name starting descending. */
    public function toggled(string $name): self
    {
        if ($name !== $this->name) {
            return new self($name, 'desc');
        }

        return new self($name, $this->direction === 'asc' ? 'desc' : 'asc');
    }

    /**
     * Orders by $column in place of any order the query had, so a relation's own `latest()` cannot outrank the header,
     * then by the model's qualified key in the same direction, so rows with equal values keep their place from one
     * page to the next.
     */
    public function apply(Builder $query, string $column): void
    {
        $key = $query->getModel()->getQualifiedKeyName();

        $query->reorder($column, $this->direction);

        if ($column !== $key) {
            $query->orderBy($key, $this->direction);
        }
    }

    public function __toString(): string
    {
        return ($this->direction === 'desc' ? '-' : '') . $this->name;
    }
}
```

- [ ] **Step 7: Write `src/Listing.php` (the core: no filters yet)**

```php
<?php

declare(strict_types=1);

namespace Listing;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;

/**
 * A Blade list page's query: the filters and sorts its query string may set, read leniently, so that no URL bounces
 * the page. Build it in the controller with for(); the view refills its form from `values` and links its headers
 * with sortUrl() and ariaSort().
 */
final class Listing
{
    use Conditionable;

    /** @var array<string, mixed> each declared key's narrowed value, or null: what the filter form refills from */
    public private(set) array $values = [];

    /** The resolved `?sort=` value, such as `-created_at`: what the form's hidden input carries. */
    public private(set) string $sort;

    /** @var array<string, string> each name `?sort=` may use, and the column it orders by */
    private array $sorts = [];

    private Sort $default;

    private Sort $current;

    private function __construct(
        private readonly Builder $query,
        private readonly Request $request,
    ) {
        $this->default = new Sort($query->getModel()->getKeyName(), 'desc');
        $this->resolveSort();
    }

    /** Lists an Eloquent query, or a relation such as `$team->members()`, which keeps its pivot. */
    public static function for(Builder $query, Request $request): self
    {
        return new self($query, $request);
    }

    /**
     * Adds names `?sort=` may use. A positional name is a column of the model's table; a named argument names any other
     * column or a select alias: `sorts('total', customer: 'customers.name')`. A name given again takes its new column.
     */
    public function sorts(string ...$columns): self
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
    public function defaultSort(string $sort): self
    {
        $this->default = Sort::parse($sort);
        $this->resolveSort();

        return $this;
    }

    /**
     * The filtered, sorted query, unpaged: a clone of what for() was given, so calling it twice never doubles a
     * filter. A query gives an Eloquent builder; a relation gives the relation, so its pivot columns still load.
     */
    public function apply(): Builder
    {
        $query = clone $this->query;
        $eloquent = $query instanceof Relation ? $query->getQuery() : $query;

        $this->current->apply($eloquent, $this->sorts[$this->current->name] ?? $eloquent->getModel()->qualifyColumn($this->current->name));

        return $query;
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

    /** `?sort=` when the allowlist has its name; the default for anything else. */
    private function resolveSort(): void
    {
        $input = Filter::cleanString($this->request->input('sort'));
        $sort = $input === null ? null : Sort::parse($input);

        $this->current = $sort !== null && array_key_exists($sort->name, $this->sorts) ? $sort : $this->default;
        $this->sort = (string)$this->current;
    }
}
```

- [ ] **Step 8: Run the tests to watch them pass**

Run: `vendor/bin/phpunit tests/SortingTest.php`
Expected: `OK (11 tests, …)`.

- [ ] **Step 9: Gate**

Run: `vendor/bin/pint && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpunit`
Expected: pint passed, `[OK] No errors`, `OK`. Leave the changes uncommitted.

---

### Task 2: `paginate()`, and cursor pagination through `apply()`

**Files:**
- Modify: `src/Listing.php` (add `paginate()`)
- Test: `tests/PaginationTest.php`

**Interfaces:**
- Consumes:
  - `Listing::for()` and `Listing::apply()` from Task 1
  - `Filter::positiveInt(mixed $input, int $max): ?int`
- Produces: `Listing::paginate(?int $perPage = null): Illuminate\Pagination\LengthAwarePaginator`

- [ ] **Step 1: Write the failing tests**

`tests/PaginationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Http\Request;
use Listing\Listing;
use Listing\Tests\Fixtures\Member;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Team;
use UnexpectedValueException;

final class PaginationTest extends TestCase
{
    private function seedProducts(int $count): void
    {
        foreach (range(1, $count) as $i) {
            Product::create(['name' => "P{$i}", 'price' => $i % 3]);
        }
    }

    /** Laravel's paginators read the container's request, so a listing built from another one binds it. */
    private function listing(string $query): Listing
    {
        $request = Request::create('/products?' . $query);
        $this->app->instance('request', $request);

        return Listing::for(Product::query(), $request);
    }

    public function test_the_page_size_is_the_argument_else_the_models(): void
    {
        $this->seedProducts(20);

        $this->assertSame(15, $this->listing('')->paginate()->perPage());
        $this->assertSame(5, $this->listing('')->paginate(5)->perPage());
    }

    public function test_page_reads_like_an_id_and_a_page_past_the_cap_gives_page_1(): void
    {
        $this->seedProducts(20);
        $cap = intdiv(PHP_INT_MAX, 5);

        $this->assertSame(2, $this->listing('page=2')->paginate(5)->currentPage());
        $this->assertSame($cap, $this->listing('page=' . $cap)->paginate(5)->currentPage());

        foreach (['page=9223372036854775807', 'page=' . ($cap + 1), 'page[]=2', 'page=abc', 'page=0', 'page=-1'] as $query) {
            $this->assertSame(1, $this->listing($query)->paginate(5)->currentPage(), $query);
        }
    }

    public function test_links_keep_the_query_string(): void
    {
        $this->seedProducts(20);

        $this->assertSame('http://localhost/products?tab=open&sort=-id&page=2', $this->listing('tab=open&sort=-id')->paginate(5)->nextPageUrl());
    }

    public function test_a_relation_pages_as_itself_and_keeps_its_pivot(): void
    {
        $team = Team::create(['name' => 'Core']);

        foreach (range(1, 7) as $i) {
            $team->members()->attach(Member::create(['name' => "M{$i}"]), ['role' => $i % 2 === 0 ? 'editor' : 'owner']);
        }

        $request = Request::create('/?page=2');
        $this->app->instance('request', $request);
        $page = Listing::for($team->members(), $request)->paginate(5);

        $this->assertSame(7, $page->total());
        $this->assertSame(['M2', 'M1'], $page->getCollection()->pluck('name')->all());
        $this->assertSame('editor', $page->getCollection()->first()?->pivot?->role);
    }

    /** exe-laravel's link lists page by cursor through apply(); the README's guard catches a cursor from another order. */
    public function test_apply_cursor_paginates_and_the_readme_guard_catches_a_foreign_cursor(): void
    {
        $this->seedProducts(7);
        $byPrice = fn (): Listing => $this->listing('sort=price')->sorts('price', 'name');

        $first = $byPrice()->apply()->cursorPaginate(3);
        $next = $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', $first->nextCursor()?->encode());
        $this->assertSame(['P4', 'P7', 'P2'], $next->getCollection()->pluck('name')->all());
        $this->assertSame(['P3', 'P6', 'P1'], $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', 'garbage')->getCollection()->pluck('name')->all());

        $foreign = $this->listing('sort=name')->sorts('price', 'name')->apply()->cursorPaginate(3)->nextCursor()?->encode();

        try {
            $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', $foreign);
            $this->fail('A cursor from another order should throw.');
        } catch (UnexpectedValueException) {
            $this->assertSame(['P3', 'P6', 'P1'], $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', '')->getCollection()->pluck('name')->all());
        }
    }
}
```

The expected rows in the last test come from prices `i % 3` and ascending ties on the key:

- 0: P3, P6
- 1: P1, P4, P7
- 2: P2, P5

So by price, then key ascending, the order is P3, P6, P1, P4, P7, P2, P5. The first page is `[P3, P6, P1]`, and the
page after the cursor is `[P4, P7, P2]`.

- [ ] **Step 2: Run the tests to watch them fail**

Run: `vendor/bin/phpunit tests/PaginationTest.php`
Expected: 4 errors, `Call to undefined method Listing\Listing::paginate()`. The cursor test already passes, because
`apply()` exists since Task 1. It pins the behaviour exe-laravel relies on, and does not drive new code.

- [ ] **Step 3: Add `paginate()` to `src/Listing.php`**

Add these imports:

```php
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
```

Then add this method after `apply()`:

```php
    /**
     * The filtered, sorted page, its links keeping the query string. The page size is $perPage, else the model's.
     * `?page` reads like an id up to the last page whose offset fits an int, so a huge page shows the first one
     * instead of overflowing.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->apply();
        $perPage ??= $query->getModel()->getPerPage();
        $page = Filter::positiveInt($this->request->input('page'), intdiv(PHP_INT_MAX, $perPage)) ?? 1;

        return $query->paginate($perPage, ['*'], 'page', $page)->withQueryString();
    }
```

- [ ] **Step 4: Run the tests to watch them pass**

Run: `vendor/bin/phpunit tests/PaginationTest.php`
Expected: `OK (5 tests, …)`.

- [ ] **Step 5: Gate**

Run: `vendor/bin/pint && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpunit`
Expected: all green. Leave the changes uncommitted.

---

### Task 3: Filters `id`, `text`, `flag` and `enum`, with values, closures and declaration errors

**Files:**
- Modify: `src/Filter.php`: add the constructor, `read()`, `apply()`, `id()`, `text()`, `flag()`, `enum()` and
  `where()`
- Modify: `src/Listing.php`: add the four builder methods and `add()`, and apply the filters in `apply()`
- Test: `tests/FilterTest.php`

**Interfaces:**
- Consumes: `Filter::cleanString()`, `Filter::positiveInt()` and `Listing::apply()`
- Produces:
  - `Filter`:
    - `static id(string $key, string|Closure|null $column, int $max): self`
    - `static text(string $key, string|Closure|null $column, int $max): self`
    - `static flag(string $key, string|Closure|null $column): self`
    - `static enum(string $key, string $enum, string|Closure|null $column): self`
    - `read(mixed $input): mixed`
    - `apply(Builder $query, mixed $value): void`
    - `public string $key`
  - `Listing`:
    - `id(string $key = 'id', string|Closure|null $column = null, int $max = PHP_INT_MAX): self`
    - `text(string $key, string|Closure|null $column = null, int $max = 255): self`
    - `flag(string $key, string|Closure|null $column = null): self`
    - `enum(string $key, string $enum, string|Closure|null $column = null): self`
    - `private add(Filter $filter): self`

- [ ] **Step 1: Write the failing tests**

`tests/FilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Listing\Listing;
use Listing\Tests\Fixtures\Kind;
use Listing\Tests\Fixtures\Member;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Pure;
use Listing\Tests\Fixtures\Status;
use Listing\Tests\Fixtures\Team;
use LogicException;

final class FilterTest extends TestCase
{
    private function listing(string $query): Listing
    {
        return Listing::for(Product::query(), Request::create('/?' . $query));
    }

    public function test_text_is_the_trimmed_string_and_empty_or_unreadable_is_null(): void
    {
        $value = fn (string $query): mixed => $this->listing($query)->text('name')->values['name'];

        $this->assertSame('lamp', $value('name=%20lamp%C2%A0'));
        $this->assertSame('0', $value('name=0'));

        foreach (['', 'name=', 'name=%20%20', 'name[]=x', 'name[a][b]=x', 'name=%FF', 'name=a%00b', 'name=' . str_repeat('a', 256)] as $query) {
            $this->assertNull($value($query), $query);
        }

        $this->assertNull($this->listing('name=abcd')->text('name', max: 3)->values['name']);
    }

    public function test_id_is_a_positive_int_within_max(): void
    {
        $value = fn (string $query, int $max = PHP_INT_MAX): mixed => $this->listing($query)->id(max: $max)->values['id'];

        $this->assertSame(7, $value('id=7'));
        $this->assertSame(7, $value('id=007'));
        $this->assertSame(2147483647, $value('id=2147483647', 2147483647));
        $this->assertNull($value('id=2147483648', 2147483647));

        foreach (['id=0', 'id=-1', 'id=%2B1', 'id=1.0', 'id=1e3', 'id=abc', 'id[]=1', 'id=9223372036854775808'] as $query) {
            $this->assertNull($value($query), $query);
        }
    }

    public function test_flag_reads_1_and_0_and_nothing_else(): void
    {
        $value = fn (string $query): mixed => $this->listing($query)->flag('paid')->values['paid'];

        $this->assertTrue($value('paid=1'));
        $this->assertFalse($value('paid=0'));

        foreach (['paid=on', 'paid=true', 'paid=yes', 'paid=', 'paid=01', 'paid[]=1'] as $query) {
            $this->assertNull($value($query), $query);
        }
    }

    public function test_enum_reads_the_backed_case_and_an_int_backed_one_from_digits_only(): void
    {
        $status = fn (string $query): mixed => $this->listing($query)->enum('status', Status::class)->values['status'];
        $kind = fn (string $query): mixed => $this->listing($query)->enum('kind', Kind::class)->values['kind'];

        $this->assertSame(Status::draft, $status('status=0'));
        $this->assertSame(Status::live, $status('status=01'));

        foreach (['status=1x', 'status=-1', 'status=%2B1', 'status=nope', 'status=', 'status[]=1'] as $query) {
            $this->assertNull($status($query), $query);
        }

        $this->assertSame(Kind::book, $kind('kind=%20book%20'));
        $this->assertNull($kind('kind=BOOK'));
    }

    public function test_a_filter_narrows_the_rows_and_null_adds_nothing(): void
    {
        Product::create(['name' => 'Desk', 'kind' => 'book', 'status' => 1, 'paid' => true]);
        Product::create(['name' => 'Floor', 'kind' => 'film', 'status' => 0, 'paid' => false]);

        $names = fn (string $query): array => $this->listing($query)
            ->text('name')->id()->flag('paid')->enum('status', Status::class)->enum('kind', Kind::class)
            ->apply()->pluck('name')->all();

        $this->assertSame(['Floor', 'Desk'], $names(''));
        $this->assertSame(['Desk'], $names('name=Desk'));
        $this->assertSame(['Floor'], $names('paid=0'));
        $this->assertSame(['Floor'], $names('status=0'));
        $this->assertSame(['Desk'], $names('kind=book&paid=1'));
        $this->assertSame(['Desk'], $names('id=1'));
        $this->assertSame(['Floor', 'Desk'], $names('name=&paid=on&status=nope&kind=BOOK&id=abc'));
    }

    public function test_a_closure_replaces_the_column_and_gets_the_typed_value_only_when_there_is_one(): void
    {
        Product::create(['name' => 'Desk', 'price' => 30]);
        Product::create(['name' => 'Floor', 'price' => 10]);
        $calls = 0;

        // A plain closure: an arrow function would capture $calls by value, and the count would stay 0.
        $listing = function (string $query) use (&$calls): Listing {
            return $this->listing($query)->id('min', function (Builder $q, int $min) use (&$calls): void {
                $calls++;
                $q->where('price', '>=', $min);
            });
        };

        $this->assertSame(['Desk'], $listing('min=20')->apply()->pluck('name')->all());
        $this->assertSame(['Floor', 'Desk'], $listing('min=abc')->apply()->pluck('name')->all());
        $this->assertSame(1, $calls);
    }

    public function test_a_dot_key_reads_nested_input(): void
    {
        $this->assertSame('lamp', $this->listing('filter[name]=lamp')->text('filter.name', 'name')->values['filter.name']);
    }

    public function test_a_relation_filters_on_a_pivot_column_named_in_full(): void
    {
        $team = Team::create(['name' => 'Core']);
        $team->members()->attach(Member::create(['name' => 'Ann']), ['role' => 'owner']);
        $team->members()->attach(Member::create(['name' => 'Bob']), ['role' => 'editor']);

        $request = Request::create('/?role=editor');
        $this->app->instance('request', $request);
        $members = Listing::for($team->members(), $request)->text('role', 'member_team.role')->paginate();

        $this->assertSame(['Bob'], $members->getCollection()->pluck('name')->all());
        $this->assertSame('editor', $members->getCollection()->first()?->pivot?->role);
    }

    public function test_a_key_declared_twice_throws(): void
    {
        $this->expectException(LogicException::class);

        $this->listing('')->text('name')->flag('name');
    }

    public function test_an_enum_that_is_not_backed_throws_where_it_is_declared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->listing('')->enum('pure', Pure::class);
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `vendor/bin/phpunit tests/FilterTest.php`
Expected: errors, `Call to undefined method Listing\Listing::text()` (and `id()`, `flag()`, `enum()`).

- [ ] **Step 3: Extend `src/Filter.php`**

Replace the `use` block and add the constructor and the factories. The whole class after this step:

```php
<?php

declare(strict_types=1);

namespace Listing;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * @internal One declared filter: how its query-string value narrows to a type, and the condition it adds. A value that
 * does not narrow reads as null and adds nothing.
 */
final readonly class Filter
{
    private function __construct(
        public string $key,
        private Closure $read,
        private Closure $apply,
    ) {}

    public static function id(string $key, string|Closure|null $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?int => self::positiveInt($input, $max), self::where($column ?? $key));
    }

    public static function text(string $key, string|Closure|null $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => self::cleanString($input, $max), self::where($column ?? $key));
    }

    /** '1' is true and '0' false, because "No" is an answer, not an absent filter; anything else is null. */
    public static function flag(string $key, string|Closure|null $column): self
    {
        return new self($key, fn (mixed $input): ?bool => match (self::cleanString($input)) {
            '1'     => true,
            '0'     => false,
            default => null,
        }, self::where($column ?? $key));
    }

    /**
     * The case the value names. An int-backed enum is read from digits only: (int) 'nope' is 0, and 0 can be a case.
     * A class that is not a backed enum throws here, where the screen declares it, not when a value first arrives.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enum(string $key, string $enum, string|Closure|null $column): self
    {
        $reflection = new ReflectionEnum($enum);

        if (! $reflection->isBacked()) {
            throw new InvalidArgumentException("[{$enum}] is not a backed enum.");
        }

        $int = $reflection->getBackingType()->getName() === 'int';

        return new self($key, function (mixed $input) use ($enum, $int): ?BackedEnum {
            $value = self::cleanString($input);

            if ($value === null || ($int && ! ctype_digit($value))) {
                return null;
            }

            return $enum::tryFrom($int ? (int)$value : $value);
        }, self::where($column ?? $key));
    }

    /** (cleanString() and positiveInt() from Task 1 stay here, unchanged.) */

    /** The input narrowed to this filter's type, or null. */
    public function read(mixed $input): mixed
    {
        return ($this->read)($input);
    }

    /** Adds this filter's condition for a narrowed value; null adds nothing. */
    public function apply(Builder $query, mixed $value): void
    {
        if ($value !== null) {
            ($this->apply)($query, $value);
        }
    }

    /** The column as written, or the developer's closure in its place. */
    private static function where(string|Closure $column): Closure
    {
        return $column instanceof Closure ? $column : fn (Builder $query, mixed $value) => $query->where($column, $value);
    }
}
```

Keep `cleanString()` and `positiveInt()` exactly as Task 1 wrote them, between `enum()` and `read()`. The comment
line above marks where; delete the comment.

- [ ] **Step 4: Extend `src/Listing.php`**

Add these imports:

```php
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder as Query;
use LogicException;
```

Add the property after `private array $sorts = [];`:

```php
    /** @var array<string, Filter> */
    private array $filters = [];
```

Add the builder methods after `for()`:

```php
    /**
     * A positive int up to $max, matched exactly. In place of the column, a closure gets the query and the int.
     *
     * @param  string|(Closure(Query<*>, int): mixed)|null  $column
     */
    public function id(string $key = 'id', string|Closure|null $column = null, int $max = PHP_INT_MAX): self
    {
        return $this->add(Filter::id($key, $column, $max));
    }

    /**
     * The trimmed string, matched exactly; empty is null. In place of the column, a closure gets the query and the
     * string.
     *
     * @param  string|(Closure(Query<*>, string): mixed)|null  $column
     */
    public function text(string $key, string|Closure|null $column = null, int $max = 255): self
    {
        return $this->add(Filter::text($key, $column, $max));
    }

    /**
     * '1' as true, '0' as false, matched exactly. In place of the column, a closure gets the query and the bool.
     *
     * @param  string|(Closure(Query<*>, bool): mixed)|null  $column
     */
    public function flag(string $key, string|Closure|null $column = null): self
    {
        return $this->add(Filter::flag($key, $column));
    }

    /**
     * The backed case, matched exactly. In place of the column, a closure gets the query and the case.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  string|(Closure(Query<*>, TEnum): mixed)|null  $column
     */
    public function enum(string $key, string $enum, string|Closure|null $column = null): self
    {
        return $this->add(Filter::enum($key, $enum, $column));
    }
```

Add `add()` before `resolveSort()`:

```php
    /** One filter per key: a second declaration is a mistake, and fails where the screen makes it. */
    private function add(Filter $filter): self
    {
        if (array_key_exists($filter->key, $this->filters)) {
            throw new LogicException("The listing already filters [{$filter->key}].");
        }

        $this->filters[$filter->key] = $filter;
        $this->values[$filter->key] = $filter->read($this->request->input($filter->key));

        return $this;
    }
```

In `apply()`, apply the filters before the sort:

```php
        $query = clone $this->query;
        $eloquent = $query instanceof Relation ? $query->getQuery() : $query;

        foreach ($this->filters as $key => $filter) {
            $filter->apply($eloquent, $this->values[$key]);
        }

        $this->current->apply($eloquent, $this->sorts[$this->current->name] ?? $eloquent->getModel()->qualifyColumn($this->current->name));

        return $query;
```

- [ ] **Step 5: Run the tests to watch them pass**

Run: `vendor/bin/phpunit tests/FilterTest.php`
Expected: `OK (10 tests, …)`.

- [ ] **Step 6: Gate**

Run: `vendor/bin/pint && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpunit`
Expected: all green. Leave the changes uncommitted.

---

### Task 4: `search()`, the portable literal LIKE

**Files:**
- Modify: `src/Filter.php` (add `search()`), `src/Listing.php` (add `search()`)
- Test: `tests/SearchTest.php`

**Interfaces:**
- Consumes: `Filter::cleanString()` and `Listing::add()`
- Produces:
  - `Filter::search(string $key, string|array $columns, int $max): self`
  - `Listing::search(string $key, string|array $columns, int $max = 255): self`

- [ ] **Step 1: Write the failing tests**

`tests/SearchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Listing\Listing;
use Listing\Tests\Fixtures\Product;

final class SearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['100% cotton', null], ['1000 cotton', null], ['a_b', null], ['axb', null], ['c:\dir', null], ['c:dir', null],
            ['[x]', null], ['50!', null], ['Alice', null], ['Desk', 'lamp'], ['Lamp shade', 'textile']] as [$name, $kind]) {
            Product::create(['name' => $name, 'kind' => $kind]);
        }
    }

    /**
     * @param  string|non-empty-list<string>  $columns
     * @param  Builder<Product>|null  $query
     * @return list<string>
     */
    private function search(string|array $columns, string $term, ?Builder $query = null): array
    {
        return Listing::for($query ?? Product::query(), Request::create('/?' . http_build_query(['q' => $term])))
            ->search('q', $columns)
            ->defaultSort('id')
            ->apply()->pluck('name')->all();
    }

    public function test_the_wildcards_in_a_term_are_matched_literally(): void
    {
        $this->assertSame(['100% cotton'], $this->search('name', '100%'));
        $this->assertSame(['a_b'], $this->search('name', 'a_b'));
        $this->assertSame(['c:\dir'], $this->search('name', 'c:\dir'));
        $this->assertSame(['[x]'], $this->search('name', '[x]'));
        $this->assertSame(['50!'], $this->search('name', '50!'));
    }

    /** `[` opens a character set on SQL Server only, so no row test on the other engines can tell it is escaped. */
    public function test_the_pattern_escapes_a_bracket_for_sql_server(): void
    {
        $query = Listing::for(Product::query(), Request::create('/?q=%5Bx%5D'))->search('q', 'name')->apply();

        $this->assertSame(['%![x]%'], $query->getBindings());
    }

    /** SQLite's LIKE ignores ASCII case by itself, so turn that off there to see lower() work. */
    public function test_a_term_matches_in_any_letter_case(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA case_sensitive_like = ON');
        }

        $this->assertSame(['Alice'], $this->search('name', 'aLICE'));
    }

    /** The columns are OR'ed inside their own group, so a where() before the search still holds. */
    public function test_any_of_the_columns_may_match_inside_their_own_group(): void
    {
        $this->assertSame(['Lamp shade'], $this->search('name', 'lamp'));
        $this->assertSame(['Desk', 'Lamp shade'], $this->search(['name', 'kind'], 'lamp'));
        $this->assertSame(['Lamp shade'], $this->search(['name', 'kind'], 'lamp', Product::query()->where('kind', 'textile')));
    }

    /** PostgreSQL has no lower() for a number, so the column is cast to text there; the PostgreSQL CI leg pins it. */
    public function test_a_number_column_can_be_searched(): void
    {
        Product::create(['name' => 'Priced', 'price' => 512]);

        $this->assertSame(['Priced'], $this->search('price', '51'));
    }

    public function test_an_empty_term_filters_nothing(): void
    {
        $this->assertCount(11, $this->search('name', ''));
        $this->assertCount(11, $this->search('name', '   '));
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `vendor/bin/phpunit tests/SearchTest.php`
Expected: errors, `Call to undefined method Listing\Listing::search()`.

- [ ] **Step 3: Add `search()` to `src/Filter.php`**

Add the import `use Illuminate\Database\Query\Grammars\PostgresGrammar;`, and this factory after `enum()`:

```php
    /**
     * Rows where any of the columns contains the term as typed, in any letter case the database lowercases. `%` and
     * `_` are LIKE wildcards; `!` escapes them because it is plain text in every database's string literals, so one
     * ESCAPE clause works on MySQL, MariaDB, PostgreSQL, SQLite and SQL Server, which do not agree on a default; `[`
     * opens a character set on SQL Server. PostgreSQL has no lower() for a number, date or uuid, so a column is cast to
     * text there, as Laravel's own LIKE does; the parentheses keep a JSON path (`meta->tags[0]`) whole.
     *
     * @param  string|non-empty-list<string>  $columns
     */
    public static function search(string $key, string|array $columns, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => self::cleanString($input, $max), function (Builder $query, string $term) use ($columns): void {
            $pattern = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']) . '%';
            $grammar = $query->getQuery()->getGrammar();
            $text = $grammar instanceof PostgresGrammar ? '(%s)::text' : '%s';

            $query->where(function (Builder $query) use ($columns, $grammar, $pattern, $text): void {
                foreach ((array)$columns as $column) {
                    $query->orWhereRaw('lower(' . sprintf($text, $grammar->wrap($column)) . ") like lower(?) escape '!'", [$pattern]);
                }
            });
        });
    }
```

- [ ] **Step 4: Add `search()` to `src/Listing.php`**, after `enum()`:

```php
    /**
     * Rows where any of the columns contains the trimmed term as typed: `%`, `_`, `!` and `[` match themselves.
     *
     * @param  string|non-empty-list<string>  $columns
     */
    public function search(string $key, string|array $columns, int $max = 255): self
    {
        return $this->add(Filter::search($key, $columns, $max));
    }
```

- [ ] **Step 5: Run the tests to watch them pass**

Run: `vendor/bin/phpunit tests/SearchTest.php`
Expected: `OK (6 tests, …)`.

- [ ] **Step 6: Gate**

Run: `vendor/bin/pint && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpunit`
Expected: all green. Leave the changes uncommitted.

---

### Task 5: `date()`, whole days on every engine

**Files:**
- Modify: `src/Filter.php` (add `date()`), `src/Listing.php` (add `date()`)
- Test: `tests/DateTest.php`

**Interfaces:**
- Consumes: `Filter::cleanString()` and `Listing::add()`
- Produces:
  - `Filter::date(string $key, string|Closure|null $column, string $operator): self`
  - `Listing::date(string $key, string|Closure|null $column = null, string $operator = '='): self`

- [ ] **Step 1: Write the failing tests**

`tests/DateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Listing\Listing;
use Listing\Tests\Fixtures\Product;
use UnhandledMatchError;

final class DateTest extends TestCase
{
    /** P1 to P4 straddle 2026-10-01 at both of its edges; Undated has no date at all. */
    private function seedProducts(): void
    {
        foreach ([
            ['2026-09-30', '2026-09-30 23:59:59.999'],
            ['2026-10-01', '2026-10-01 00:00:00.000'],
            ['2026-10-01', '2026-10-01 23:59:59.999'],
            ['2026-10-02', '2026-10-02 00:00:00.000'],
        ] as $i => [$day, $at]) {
            Product::create(['name' => 'P' . ($i + 1), 'day' => $day, 'day_cast' => $day, 'at' => $at]);
        }

        Product::create(['name' => 'Undated']);
    }

    /** @return list<string> */
    private function names(string $column, string $query): array
    {
        return Listing::for(Product::query(), Request::create('/?' . $query))
            ->date('on', $column)
            ->date('from', $column, '>=')
            ->date('to', $column, '<=')
            ->defaultSort('id')
            ->apply()->pluck('name')->all();
    }

    private function day(string $input): mixed
    {
        return Listing::for(Product::query(), Request::create('/?' . http_build_query(['on' => $input])))->date('on')->values['on'];
    }

    /** `day` holds `Y-m-d`, `day_cast` what Eloquent's date cast writes (`Y-m-d 00:00:00` on SQLite), `at` milliseconds. */
    public function test_whole_days_on_every_kind_of_date_column(): void
    {
        $this->seedProducts();

        foreach (['day', 'day_cast', 'at'] as $column) {
            $this->assertSame(['P2', 'P3'], $this->names($column, 'on=2026-10-01'), $column);
            $this->assertSame(['P2', 'P3', 'P4'], $this->names($column, 'from=2026-10-01'), $column);
            $this->assertSame(['P1', 'P2', 'P3'], $this->names($column, 'to=2026-10-01'), $column);
            $this->assertSame(['P2', 'P3'], $this->names($column, 'from=2026-10-01&to=2026-10-01'), $column);
            $this->assertSame([], $this->names($column, 'from=2026-10-02&to=2026-10-01'), $column);
        }
    }

    public function test_the_edges_and_rows_without_a_date(): void
    {
        $this->seedProducts();

        foreach (['day', 'day_cast', 'at'] as $column) {
            $this->assertSame(['P1', 'P2', 'P3', 'P4'], $this->names($column, 'from=1753-01-01&to=9999-12-31'), $column);
            $this->assertSame(['P1', 'P2', 'P3', 'P4'], $this->names($column, 'to=9999-12-31'), $column);
            $this->assertSame([], $this->names($column, 'on=9999-12-31'), $column);
            $this->assertSame(['P1', 'P2', 'P3', 'P4', 'Undated'], $this->names($column, 'to=1752-12-31'), $column);
        }
    }

    public function test_a_day_is_a_real_y_m_d_from_1753(): void
    {
        foreach (['2026-10-01', '2028-02-29', '1753-01-01', '9999-12-31'] as $day) {
            $this->assertSame($day, $this->day($day));
        }

        $this->assertSame('2026-10-01', $this->day(" 2026-10-01\u{A0}"));

        foreach (['', '2026-02-29', '2026-02-30', '2026-13-01', '2026-00-10', '2026-10-32', '2026-1-1', '2026-01-01T10:00',
            '2026-10-01 00:00:00', '20261001', '01/10/2026', '+2026-10-01', "\u{FF12}\u{FF10}\u{FF12}\u{FF16}-10-01",
            '0000-01-01', '1752-12-31', '10000-01-01', "2026-10\x0001", "\xFF"] as $input) {
            $this->assertNull($this->day($input), var_export($input, true));
        }

        $this->assertNull(Listing::for(Product::query(), Request::create('/?on[]=2026-10-01'))->date('on')->values['on']);
    }

    /** SQL Server reads `2026-10-01` by the login's language for a legacy datetime; `20261001` reads the same in all. */
    public function test_sql_server_gets_each_day_as_ymd(): void
    {
        $query = Listing::for(Product::on('sqlsrv'), Request::create('/?on=2026-10-01'))->date('on', 'at')->apply();

        $this->assertSame(['20261001', '20261002'], $query->getBindings());
    }

    /** Samoa went from 2011-12-29 to 2011-12-31; the bounds are calendar days, whatever the app's time zone. */
    public function test_day_arithmetic_ignores_the_app_time_zone(): void
    {
        foreach (['P1' => '2011-12-29', 'P2' => '2011-12-30', 'P3' => '2011-12-31'] as $name => $day) {
            Product::create(['name' => $name, 'day' => $day]);
        }

        $zone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Apia');

        try {
            $this->assertSame(['P2'], $this->names('day', 'on=2011-12-30'));
            $this->assertSame(['P2', 'P3'], $this->names('day', 'from=2011-12-30'));
            $this->assertSame(['P1', 'P2'], $this->names('day', 'to=2011-12-30'));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_a_closure_gets_the_day_string(): void
    {
        $seen = null;

        Listing::for(Product::query(), Request::create('/?on=2026-10-01'))
            ->date('on', function (Builder $query, string $day) use (&$seen): void {
                $seen = $day;
            })
            ->apply();

        $this->assertSame('2026-10-01', $seen);
    }

    public function test_only_whole_day_operators_are_taken(): void
    {
        $this->expectException(UnhandledMatchError::class);

        Listing::for(Product::query(), Request::create('/'))->date('after', 'at', '>');
    }
}
```

- [ ] **Step 2: Run the tests to watch them fail**

Run: `vendor/bin/phpunit tests/DateTest.php`
Expected: errors, `Call to undefined method Listing\Listing::date()`.

- [ ] **Step 3: Add `date()` to `src/Filter.php`**

Add these imports:

```php
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
```

Then add this factory after `search()`:

```php
    /**
     * A day as `<input type="date">` sends it, `2026-10-01`, from 1753-01-01, where SQL Server's legacy `datetime`
     * starts, to 9999-12-31. The operator compares whole days: '=' keeps that day, '>=' that day or later, '<=' that
     * day or earlier, through its last instant. A closure in place of the column gets the day string.
     *
     * The range is half-open and bound as plain strings, not whereDate(), so an index serves it, and not a DateTime,
     * which SQLite compares as text against a `Y-m-d` column. SQL Server gets `Ymd`, which reads the same in every
     * login language. Both bounds come from one UTC date, so a zone that skipped a day cannot shift them; 9999-12-31
     * has no next day, so as an upper bound alone it only keeps NULL dates out.
     *
     * @param  '='|'>='|'<='  $operator
     */
    public static function date(string $key, string|Closure|null $column, string $operator): self
    {
        [$lower, $upper] = match ($operator) {
            '='  => [true, true],
            '>=' => [true, false],
            '<=' => [false, true],
        };
        $column ??= $key;

        return new self($key, function (mixed $input): ?string {
            $day = self::cleanString($input, 10);

            return $day !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) === 1
                && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && $day >= '1753-01-01' ? $day : null;
        }, $column instanceof Closure ? $column : function (Builder $query, string $day) use ($column, $lower, $upper): void {
            $format = $query->getQuery()->getGrammar() instanceof SqlServerGrammar ? 'Ymd' : 'Y-m-d';
            $start = new DateTimeImmutable($day, new DateTimeZone('UTC'));

            if ($lower) {
                $query->where($column, '>=', $start->format($format));
            }

            if ($upper && $day !== '9999-12-31') {
                $query->where($column, '<', $start->modify('+1 day')->format($format));
            } elseif ($upper && ! $lower) {
                $query->whereNotNull($column);
            }
        });
    }
```

- [ ] **Step 4: Add `date()` to `src/Listing.php`**, after `search()`:

```php
    /**
     * A day, `2026-10-01`, compared as whole days: '=' that day, '>=' on or after it, '<=' on or before it. A range is
     * two of them on one column. In place of the column, a closure gets the query and the day string.
     *
     * @param  string|(Closure(Query<*>, string): mixed)|null  $column
     * @param  '='|'>='|'<='  $operator
     */
    public function date(string $key, string|Closure|null $column = null, string $operator = '='): self
    {
        return $this->add(Filter::date($key, $column, $operator));
    }
```

- [ ] **Step 5: Run the tests to watch them pass**

Run: `vendor/bin/phpunit tests/DateTest.php`
Expected: `OK (7 tests, …)`.

- [ ] **Step 6: Gate**

Run: `vendor/bin/pint && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpunit`
Expected: all green. Leave the changes uncommitted.

---

### Task 6: A Blade screen end to end: refill, headers and the hostile-input provider

**Files:**
- Create: `tests/Fixtures/views/products.blade.php`
- Test: `tests/ScreenTest.php`

**Interfaces:**
- Consumes: all of `Listing`, through a real route and a Blade view

- [ ] **Step 1: Write the view**

`tests/Fixtures/views/products.blade.php`:

```blade
<form method="get">
    <input type="search" name="q" value="{{ $listing->values['q'] }}">
    <select name="status">
        <option value="">Any status</option>
        @foreach (\Listing\Tests\Fixtures\Status::cases() as $case)
            <option value="{{ $case->value }}" @selected($listing->values['status'] === $case)>{{ $case->name }}</option>
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
<table>
    <thead>
        <tr>
            @foreach (['name' => 'Name', 'price' => 'Price'] as $column => $label)
                <th @if ($listing->ariaSort($column)) aria-sort="{{ $listing->ariaSort($column) }}" @endif>
                    <a href="{{ $listing->sortUrl($column) }}">{{ $label }}</a>
                </th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($products as $product)
            <tr><td data-name>{{ $product->name }}</td></tr>
        @endforeach
    </tbody>
</table>
```

- [ ] **Step 2: Write the failing screen tests**

`tests/ScreenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Listing\Listing;
use Listing\Tests\Fixtures\Kind;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Status;
use PHPUnit\Framework\Attributes\DataProvider;

final class ScreenTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/products', function (Request $request) {
            $listing = Listing::for(Product::query(), $request)
                ->search('q', ['name', 'reference'])
                ->enum('status', Status::class)
                ->enum('kind', Kind::class)
                ->flag('paid')
                ->id()
                ->date('from', 'day', '>=')
                ->date('to', 'day', '<=')
                ->sorts('name', 'price');

            return view('products', ['products' => $listing->paginate(3), 'listing' => $listing]);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([['Desk', 30, 1, true, '2026-01-02'], ['Floor', 10, 0, false, '2026-01-03'],
            ['Wall', 20, 1, false, '2026-01-04'], ['Table', 10, 0, true, '2026-01-05']] as [$name, $price, $status, $paid, $day]) {
            Product::create(['name' => $name, 'price' => $price, 'status' => $status, 'paid' => $paid, 'day' => $day, 'kind' => 'book']);
        }
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return list<string>
     */
    private function names(TestResponse $response): array
    {
        preg_match_all('#<td data-name>(.*?)</td>#', (string)$response->getContent(), $matches);

        return $matches[1];
    }

    public function test_filters_sort_and_page_through_http(): void
    {
        $this->assertSame(['Desk', 'Table'], $this->names($this->get('/products?paid=1&sort=-price')->assertOk()));
        $this->assertSame(['Desk'], $this->names($this->get('/products?page=2')->assertOk()));
    }

    public function test_the_form_refills_from_the_narrowed_values(): void
    {
        $html = (string)$this->get('/products?q=%20desk%20&status=1&paid=0&from=2026-01-02&to=2026-02-30&sort=-price')->assertOk()->getContent();

        $this->assertStringContainsString('name="q" value="desk"', $html);
        $this->assertStringContainsString('<option value="1" selected>live</option>', $html);
        $this->assertStringContainsString('<option value="0" selected>Paid: no</option>', $html);
        $this->assertStringContainsString('name="from" value="2026-01-02"', $html);
        $this->assertStringContainsString('name="to" value=""', $html);
        $this->assertStringContainsString('name="sort" value="-price"', $html);
    }

    public function test_an_empty_form_submission_shows_the_default_list(): void
    {
        $response = $this->get('/products?q=&status=&paid=&from=&to=&sort=')->assertOk();

        $this->assertSame(['Table', 'Wall', 'Floor'], $this->names($response));
        $this->assertStringNotContainsString('selected', (string)$response->getContent());
    }

    public function test_headers_link_the_toggled_sort_and_drop_the_page(): void
    {
        $html = (string)$this->get('/products?tab=open&sort=price&page=2')->assertOk()->getContent();

        $this->assertStringContainsString('href="http://localhost/products?tab=open&amp;sort=-price"', $html);
        $this->assertStringContainsString('href="http://localhost/products?tab=open&amp;sort=-name"', $html);
        $this->assertSame(1, substr_count($html, 'aria-sort="ascending"'));
    }

    /** @return iterable<string, array{string}> */
    public static function hostile(): iterable
    {
        foreach ([
            'q[]=x', 'q[a][b]=x', 'q=%FF', 'q=a%00b', 'q=' . str_repeat('a', 300),
            'id=99999999999999999999', 'id[]=1', 'status=nope', 'status=1x', 'status[]=1', 'kind=BOOK', 'paid=on', 'paid[]=1',
            'sort=--price', 'sort[]=price', 'sort=password', 'sort=%FF',
            'page=9223372036854775807', 'page[]=2', 'page=-1',
            'from=2026-02-30', 'to=2026-13-01', 'from[]=2026-01-02', 'to=10000-01-01', 'from=0000-01-01', 'from=2026-01%0002',
        ] as $query) {
            yield $query => [$query];
        }
    }

    #[DataProvider('hostile')]
    public function test_hostile_input_shows_the_default_list(string $query): void
    {
        $response = $this->get('/products?' . $query)->assertOk();
        $html = (string)$response->getContent();

        $this->assertSame(['Table', 'Wall', 'Floor'], $this->names($response));
        $this->assertStringContainsString('name="from" value=""', $html);
        $this->assertStringContainsString('name="to" value=""', $html);
    }
}
```

- [ ] **Step 3: Run the tests**

Run: `vendor/bin/phpunit tests/ScreenTest.php`
Expected: `OK (30 tests, …)`: 4 tests plus 26 data-provider cases. The screen code already exists, so these tests
prove the integration rather than drive new code.

To confirm they can fail: temporarily change `Filter::cleanString()` to return `$input` without the UTF-8 check. Run
again; at least `q=%FF` must fail. Revert the change.

- [ ] **Step 4: Gate**

Run: `vendor/bin/pint && vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress --memory-limit=1G && vendor/bin/phpunit`
Expected: all green. Leave the changes uncommitted.

---

### Task 7: Packaging, tooling and CI

**Files:**
- Modify: `composer.json`, `pint.json`, `phpunit.xml`, `.gitattributes`, `.github/workflows/tests.yml`

**Interfaces:** none; tooling only.

- [ ] **Step 1: Rewrite `composer.json`** (the authors block and `require-dev` are unchanged)

```json
{
    "name": "ismailnakkar/laravel-listing",
    "description": "Easy, typed querying and filtering for Blade list pages: declare filters and sorts once, and no query string ever bounces the page.",
    "keywords": ["laravel", "listing", "filter", "sort", "search", "pagination", "blade"],
    "homepage": "https://github.com/ismailnakkar/laravel-listing",
    "license": "MIT",
    "authors": [
        {
            "name": "Ismail Nakkar",
            "email": "juge.steam@gmail.com"
        }
    ],
    "require": {
        "php": "^8.4",
        "laravel/framework": "^13.0"
    },
    "require-dev": {
        "larastan/larastan": "^3.12",
        "laravel/pint": "^1.27",
        "orchestra/testbench": "^11.0",
        "phpunit/phpunit": "^12.5"
    },
    "autoload": {
        "psr-4": {
            "Listing\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Listing\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "vendor/bin/phpunit",
        "check": [
            "vendor/bin/pint --test",
            "vendor/bin/phpstan analyse --no-progress --memory-limit=1G",
            "vendor/bin/phpunit"
        ]
    },
    "config": {
        "sort-packages": true
    }
}
```

Run: `composer validate --strict`
Expected: `./composer.json is valid`.

- [ ] **Step 2: Rewrite `pint.json`**

This drops the `laravel` preset key, which is Pint's default, and the 7 rules the preset already sets.
`fully_qualified_strict_types` stays while the lowest leg installs Pint 1.27, whose preset sets it to `false`.

```json
{
    "rules": {
        "declare_strict_types": true,
        "binary_operator_spaces": {
            "operators": {
                "=>": "align_single_space_minimal"
            }
        },
        "trailing_comma_in_multiline": {
            "elements": ["arguments", "arrays", "match", "parameters"]
        },
        "global_namespace_import": {
            "import_classes": true,
            "import_constants": false,
            "import_functions": false
        },
        "fully_qualified_strict_types": {
            "import_symbols": true
        },
        "concat_space": {
            "spacing": "one"
        },
        "cast_spaces": {
            "space": "none"
        }
    }
}
```

Run: `vendor/bin/pint --test`
Expected: passed, with no file changed. A failure means a removed rule was not a preset duplicate: restore that rule
and say so in your report.

- [ ] **Step 3: Rewrite `phpunit.xml`**

This drops `bootstrap`, which `vendor/bin/phpunit` already loads, and the `LOG_DEPRECATIONS_WHILE_TESTING` block,
whose comment was proven false.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         cacheDirectory=".phpunit.cache"
         colors="true"
         executionOrder="random"
         beStrictAboutOutputDuringTests="true"
         failOnDeprecation="true"
         failOnNotice="true"
         failOnRisky="true"
         failOnWarning="true"
         displayDetailsOnPhpunitDeprecations="true">
    <testsuites>
        <testsuite name="Package">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source restrictNotices="true"
            restrictWarnings="true"
            ignoreIndirectDeprecations="true">
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4: Rewrite `.gitattributes`**

```
/.github        export-ignore
/docs           export-ignore
/tests          export-ignore
/.gitattributes export-ignore
/.gitignore     export-ignore
/phpstan.neon   export-ignore
/phpunit.xml    export-ignore
/pint.json      export-ignore
```

- [ ] **Step 5: Find the current major version of `actions/checkout`**

Run: `gh api repos/actions/checkout/releases/latest --jq .tag_name`. If `gh` is not authenticated, read
https://github.com/actions/checkout/releases. Use that major, for example `v7`, in place of `<CHECKOUT>` below.

- [ ] **Step 6: Rewrite `.github/workflows/tests.yml`**

```yaml
name: tests

on:
  push:
    branches: [main]
  pull_request:

permissions:
  contents: read

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  sqlite:
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        php: ['8.4', '8.5']
        deps: [prefer-lowest, prefer-stable]

    name: SQLite · PHP ${{ matrix.php }} · ${{ matrix.deps }}

    steps:
      - uses: actions/checkout@<CHECKOUT>

      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none

      # The lowest leg installs the declared Laravel 13.0 floor, which has security advisories; malware and
      # abandoned-package blocking stay on.
      - run: composer update --${{ matrix.deps }} --prefer-dist --no-interaction --no-progress
        env:
          COMPOSER_POLICY_ADVISORIES_BLOCK: ${{ matrix.deps == 'prefer-lowest' && '0' || '1' }}

      - run: composer check

  database:
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        include:
          - connection: pgsql
            image: postgres:18
            port: 5432
            username: postgres
            health: pg_isready
          - connection: mysql
            image: mysql:8.4
            port: 3306
            username: root
            health: mysqladmin ping -h 127.0.0.1 -psecret

    name: ${{ matrix.connection }} · PHP 8.5 · prefer-stable

    services:
      database:
        image: ${{ matrix.image }}
        env:
          POSTGRES_PASSWORD: secret
          POSTGRES_DB: listing
          MYSQL_ROOT_PASSWORD: secret
          MYSQL_DATABASE: listing
        ports:
          - ${{ matrix.port }}:${{ matrix.port }}
        options: >-
          --health-cmd "${{ matrix.health }}"
          --health-interval 5s
          --health-timeout 5s
          --health-retries 20

    steps:
      - uses: actions/checkout@<CHECKOUT>

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_pgsql, pdo_mysql
          coverage: none

      - run: composer update --prefer-stable --prefer-dist --no-interaction --no-progress

      - run: vendor/bin/phpunit
        env:
          DB_CONNECTION: ${{ matrix.connection }}
          DB_HOST: 127.0.0.1
          DB_PORT: ${{ matrix.port }}
          DB_DATABASE: listing
          DB_USERNAME: ${{ matrix.username }}
          DB_PASSWORD: secret
```

Run: `ruby -ryaml -e 'YAML.load_file(".github/workflows/tests.yml"); puts "ok"'`
Expected: `ok`.

- [ ] **Step 7: Gate**

Run: `composer check`
Expected: all green. Leave the changes uncommitted.

---

### Task 8: The README

**Files:**
- Rewrite: `README.md`

**Interfaces:** documents everything Tasks 1–7 built.

- [ ] **Step 1: Write `README.md`**

Write the README below exactly. The outer fence uses four backticks so that the README's own code blocks survive.

````markdown
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
            ->sorts('reference', 'total', 'created_at', customer: 'customers.name')
            ->defaultSort('-created_at');

        return view('orders.index', ['orders' => $listing->paginate(), 'listing' => $listing]);
    }
}
```

`?status=shipped&paid=0&q=lamp&from=2026-10-01&sort=-total&page=2` is the second page of unpaid, shipped orders
matching "lamp" since October 1st, largest first. `paginate()` takes a page size, else the model's `$perPage`.

### Filters

| Method | Reads `?key=` as | Keeps the rows where |
| --- | --- | --- |
| `id($key = 'id', $column = null, $max = PHP_INT_MAX)` | a positive `int` up to `$max` | the column is it |
| `text($key, $column = null, $max = 255)` | the trimmed string; empty is `null` | the column is it |
| `flag($key, $column = null)` | `'1'` as `true`, `'0'` as `false` | the column is it |
| `enum($key, Enum::class, $column = null)` | the backed case; an int-backed enum reads digits only | the column is it |
| `search($key, $columns, $max = 255)` | the trimmed string; empty is `null` | any of the columns contains it, as typed |
| `date($key, $column = null, $operator = '=')` | a real day, `2026-10-01` | the column falls on it (`=`), on or after it (`>=`), or on or before it (`<=`) |

- A value that does not read as its type is `null` and filters nothing, so the empty fields a form sends filter
  nothing either.
- The column defaults to the key and is used as written: on a join, or for a relation's pivot table, name it in full
  (`customers.email`, `team_user.role`).
- `flag()` reads `0` as `false` on purpose: "No" is an answer, not an absent filter. A checkbox needs `value="1"`;
  without it the browser sends `on`, which reads as `null`.
- Each key is declared once; declaring it again throws.

### Search

`search()` keeps the rows where any of the columns contains the term as typed: `%`, `_`, `!` and `[` match
themselves.

- **Letter case** does not matter as far as the database lowercases. SQLite and a PostgreSQL `C` locale lowercase
  ASCII letters only, and MySQL's and MariaDB's default collation ignores accents too.
- **PostgreSQL** casts each column to text, so a number, date or uuid column can be searched.

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
- A named argument gives a joined column or a select alias a name of its own: `customer: 'customers.name'`,
  `orders: 'orders_count'`.
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
| `text()` and `date()` | a `string` |

```php
->id('team', fn (Builder $query, int $team) => $query->whereHas('teams', fn (Builder $q) => $q->whereKey($team)))
```

On a relation, the closure's query is the relation's own, so name a pivot column in full there too. A closure that
parses its value further must not hand the database an impossible value.

## The view

```blade
<form method="get">
    <input type="search" name="q" value="{{ $listing->values['q'] }}">
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
The hidden `sort` keeps the order when the form is sent.

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

## Paging it yourself

`apply()` is the filtered, sorted query without paging. Use it for:

- an export
- `simplePaginate()` or `cursorPaginate()`
- a second paginator
- a query run under a statement timeout

A relation stays a relation.

Laravel's own page resolver accepts `?page=9223372036854775807`, a 500 on PHP 8.5, so pass the page yourself:

```php
$perPage = 25;
$page = filter_var($request->query('page'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, $perPage)]]) ?: 1;

$rows = $listing->apply()->simplePaginate($perPage, ['*'], 'page', $page);
```

A garbage `?cursor` gives the first page, but a well-formed one from another sort order makes Laravel throw. Show the
first page instead:

```php
try {
    $links = $listing->apply()->cursorPaginate(25)->withQueryString();
} catch (UnexpectedValueException) {
    $links = $listing->apply()->cursorPaginate(25, ['*'], 'cursor', '')->withQueryString();
}
```

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
  - Give `id()` on an `integer` (not `bigInteger`) column `max: 2147483647`.
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
````

- [ ] **Step 2: Check every code sample against the code**

For each PHP and Blade sample in the README, find the test that runs the same shape:

| README sample | Test |
| --- | --- |
| The controller | `ScreenTest`'s route |
| The view | `products.blade.php` |
| The page line, the cursor guard | `PaginationTest` |
| Closures | `FilterTest` and `DateTest` |
| Relation, pivot | `FilterTest::test_a_relation_filters_on_a_pivot_column_named_in_full` |

The anonymous component is the one sample with no test. Render it once, then throw both files away.

1. Copy the README's component, unchanged, to `tests/Fixtures/views/components/sort-header.blade.php`.
2. Write the scratch test `tests/ComponentScratchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Listing\Listing;
use Listing\Tests\Fixtures\Product;

final class ComponentScratchTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__ . '/Fixtures/views']);
    }

    public function test_the_readme_header_component_renders(): void
    {
        $listing = Listing::for(Product::query(), Request::create('/products?sort=price'))->sorts('price');

        $html = Blade::render('<x-sort-header :listing="$listing" column="price" class="num">Price</x-sort-header>', ['listing' => $listing]);

        $this->assertStringContainsString('aria-sort="ascending"', $html);
        $this->assertStringContainsString('class="num"', $html);
        $this->assertStringContainsString('href="http://localhost/products?sort=-price"', $html);
        $this->assertStringContainsString('>Price</a>', $html);
    }
}
```

3. Run `vendor/bin/phpunit tests/ComponentScratchTest.php` and expect `OK (1 test, 4 assertions)`. If it fails, fix
   the README's component, not the test.
4. Delete both files:

```bash
rm tests/ComponentScratchTest.php tests/Fixtures/views/components/sort-header.blade.php
rmdir tests/Fixtures/views/components
```

- [ ] **Step 3: Gate**

Run: `composer check`
Expected: all green. Leave the changes uncommitted.

---

### Task 9: Final verification

**Files:** none changed, unless a check fails, in which case fix it in the owning task's files and re-run that
task's gate.

- [ ] **Step 1: The full gate on the host (PHP 8.5)**

Run: `cd /Users/ismail/Projects/laravel-listing && composer check`
Expected: pint passed, `[OK] No errors`, `OK (…)` with no failures, warnings, deprecations or risky tests.

- [ ] **Step 2: PHP 8.4**

```bash
S=$(mktemp -d)
rsync -a --exclude .git --exclude .phpunit.cache /Users/ismail/Projects/laravel-listing/ "$S/pkg/"
docker run --rm -v "$S/pkg:/app" -w /app php:8.4-cli sh -c 'php vendor/bin/phpstan analyse --no-progress --memory-limit=1G && php vendor/bin/phpunit --do-not-cache-result'
```

Expected: `[OK] No errors`, then `OK (…)`.

- [ ] **Step 3: PostgreSQL 18 and MySQL 8.4, as the CI legs run them**

```bash
docker run -d --name ll-final-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=listing -p 127.0.0.1::5432 postgres:18
docker run -d --name ll-final-my -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=listing -p 127.0.0.1::3306 mysql:8.4
PG=$(docker port ll-final-pg 5432 | head -1 | cut -d: -f2); MY=$(docker port ll-final-my 3306 | head -1 | cut -d: -f2)
until docker exec ll-final-pg pg_isready -q; do sleep 1; done
until docker exec ll-final-my mysqladmin ping -h 127.0.0.1 -psecret --silent; do sleep 1; done
cd /Users/ismail/Projects/laravel-listing
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=$PG DB_DATABASE=listing DB_USERNAME=postgres DB_PASSWORD=secret vendor/bin/phpunit --do-not-cache-result
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=$MY DB_DATABASE=listing DB_USERNAME=root DB_PASSWORD=secret vendor/bin/phpunit --do-not-cache-result
docker rm -f ll-final-pg ll-final-my
```

Expected: `OK (…)` on both. `SearchTest::test_a_number_column_can_be_searched` is the PostgreSQL cast's guard. If
foreground `sleep` is blocked, poll in a single command, or wait with the Monitor tool.

- [ ] **Step 4: Review**

Get a fresh review of the whole diff against the spec. Use superpowers:requesting-code-review, with the spec and this
plan as the requirements, plus a ponytail-review pass for anything over-built. Fix what holds up, and re-run Steps
1–3 after any fix.

- [ ] **Step 5: Hand over (do not commit)**

Report to the owner:

- what changed
- the gate results from Steps 1–3
- that the release checklist (MariaDB, SQL Server through `pdo_sqlsrv`, the mutation pass) is still theirs
- that exe-laravel is still broken, and is waiting on their call about who migrates it

Give them the commands, without running them. The first stages every change:

```bash
git -C /Users/ismail/Projects/laravel-listing add -A
```

The second creates the commit, authored by the owner:

```bash
git -C /Users/ismail/Projects/laravel-listing commit -m "Replace ListingRequest with the Listing builder"
```
