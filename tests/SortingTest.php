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
use Listing\Tests\Fixtures\Pure;
use Listing\Tests\Fixtures\Shelf;
use Listing\Tests\Fixtures\Status;
use Listing\Tests\Fixtures\Team;
use LogicException;

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

    /** Ties break on the key in the same direction, so one index on the column and the key serves it. */
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

    /** The hidden input sends `sort=name` back, which is off the allowlist and gives the same default. */
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

        // A default resolves through the aliases too, not as a missing `products.category`.
        $aliasDefault = Listing::for(clone $query, Request::create('/'))->sorts(category: 'categories.name')->defaultSort('-category');
        $this->assertSame(['Table', 'Wall', 'Floor', 'Desk'], $aliasDefault->apply()->pluck('name')->all());

        // Both tables have `name`; a positional sort orders by the model's.
        $byName = Listing::for(Product::query()->join('categories', 'categories.id', '=', 'products.category_id')->select('products.id'), Request::create('/?sort=name'))->sorts('name');
        $this->assertSame([1, 2, 4, 3], $byName->apply()->pluck('id')->map(intval(...))->all());
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

    public function test_a_sort_by_the_key_orders_by_it_once(): void
    {
        $orders = Listing::for(Product::query(), Request::create('/'))->apply()->getQuery()->orders;

        $this->assertSame([['column' => 'products.id', 'direction' => 'desc']], $orders);
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

        $lists = Listing::for(Product::query(), Request::create('/products?kind[]=book&kind[]=film&sort=price'))->sorts('price');
        $this->assertSame('http://localhost/products?kind%5B0%5D=book&kind%5B1%5D=film&sort=-price', $lists->sortUrl('price'));

        $descending = Listing::for(Product::query(), Request::create('/products?sort=-price'))->sorts('price');
        $this->assertSame('http://localhost/products?sort=price', $descending->sortUrl('price'));
        $this->assertSame('descending', $descending->ariaSort('price'));
    }

    public function test_sort_url_for_a_name_outside_the_allowlist_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Listing::for(Product::query(), Request::create('/'))->sorts('price')->sortUrl('name');
    }

    /** Desk bottom (id 1), Floor none (id 2), Wall top (id 3), Table middle (id 4), Lamp 'attic' (id 5), no case's. */
    private function seedShelves(): void
    {
        foreach ([['Desk', 'bottom'], ['Floor', null], ['Wall', 'top'], ['Table', 'middle'], ['Lamp', 'attic']] as [$name, $shelf]) {
            Product::create(['name' => $name, 'shelf' => $shelf]);
        }
    }

    public function test_a_case_sort_orders_by_the_enums_declared_cases_then_the_rest_by_key(): void
    {
        $this->seedShelves();

        $ascending = $this->listing('sort=shelf')->sortsByCase('shelf', Shelf::class);
        $this->assertSame(['Wall', 'Table', 'Desk', 'Floor', 'Lamp'], $this->names($ascending));
        $this->assertSame('shelf', $ascending->sort);

        $this->assertSame(['Lamp', 'Floor', 'Desk', 'Table', 'Wall'], $this->names($this->listing('sort=-shelf')->sortsByCase('shelf', Shelf::class)));
    }

    public function test_a_case_sort_serves_as_the_default_and_on_another_column(): void
    {
        $this->seedShelves();

        $default = $this->listing()->sortsByCase('shelf', Shelf::class)->defaultSort('shelf');
        $this->assertSame(['Wall', 'Table', 'Desk', 'Floor', 'Lamp'], $this->names($default));

        $named = $this->listing('sort=place')->sortsByCase('place', Shelf::class, 'products.shelf');
        $this->assertSame(['Wall', 'Table', 'Desk', 'Floor', 'Lamp'], $this->names($named));
    }

    public function test_an_int_backed_case_sort_orders_by_its_cases(): void
    {
        foreach ([['Desk', 1], ['Floor', null], ['Wall', 0]] as [$name, $status]) {
            Product::create(['name' => $name, 'status' => $status]);
        }

        $this->assertSame(['Wall', 'Desk', 'Floor'], $this->names($this->listing('sort=status')->sortsByCase('status', Status::class)));
    }

    public function test_a_name_given_again_swaps_a_case_sort_and_a_column_sort(): void
    {
        $this->seedShelves();

        // Where a column sort puts NULL is each database's own (last on PostgreSQL), so Floor's is left out.
        $byColumn = $this->listing('sort=shelf')->sortsByCase('shelf', Shelf::class)->sorts('shelf');
        $this->assertSame(['Lamp', 'Desk', 'Table', 'Wall'], $byColumn->apply()->whereNotNull('shelf')->pluck('name')->all());

        $byCase = $this->listing('sort=shelf')->sorts('shelf')->sortsByCase('shelf', Shelf::class);
        $this->assertSame(['Wall', 'Table', 'Desk', 'Floor', 'Lamp'], $this->names($byCase));
    }

    public function test_a_case_sort_pages_and_counts_and_its_header_links(): void
    {
        $this->seedShelves();

        $listing = $this->listing('sort=shelf')->sortsByCase('shelf', Shelf::class);

        $page = $listing->paginate(2);
        $this->assertSame(5, $page->total());
        $this->assertSame(['Wall', 'Table'], $page->getCollection()->pluck('name')->all());
        $this->assertSame(['Desk', 'Floor'], $this->listing('sort=shelf&page=2')->sortsByCase('shelf', Shelf::class)->simplePaginate(2)->getCollection()->pluck('name')->all());
        $this->assertSame('http://localhost/products?sort=-shelf', $listing->sortUrl('shelf'));
        $this->assertSame('ascending', $listing->ariaSort('shelf'));
    }

    public function test_a_case_sort_on_an_enum_that_is_not_backed_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a backed enum.');

        $this->listing()->sortsByCase('shelf', Pure::class); // @phpstan-ignore argument.type
    }

    /** A cursor holds each row's sort column; a position has none, so a cursor screen must not offer one. */
    public function test_a_listing_with_a_case_sort_refuses_cursor_paging(): void
    {
        $this->expectException(LogicException::class);

        $this->listing('sort=price')->sorts('price')->sortsByCase('shelf', Shelf::class)->cursorPaginate(2);
    }
}
