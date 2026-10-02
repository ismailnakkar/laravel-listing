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

        // Both tables have `name`; a positional sort orders by the model's own, never by an ambiguous bare name.
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

    /** SQL Server rejects a column listed twice in ORDER BY (error 169), so a sort by the key orders by it once. */
    public function test_a_sort_by_the_key_orders_by_it_once(): void
    {
        $sql = Listing::for(Product::on('sqlsrv'), Request::create('/'))->apply()->toSql();

        $this->assertStringEndsWith('order by [products].[id] desc', $sql);
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
}
