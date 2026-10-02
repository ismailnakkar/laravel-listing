<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Listing\Listing;
use Listing\Tests\Fixtures\Category;
use Listing\Tests\Fixtures\Member;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Team;

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
        $this->assertSame(15, $this->listing('')->paginate(0)->perPage());
        $this->assertSame(15, $this->listing('')->simplePaginate()->perPage());
        $this->assertSame(5, $this->listing('')->simplePaginate(5)->perPage());
        $this->assertSame(15, $this->listing('page=2')->simplePaginate(0)->perPage());
        $this->assertSame(15, $this->listing('')->cursorPaginate(0)->perPage());
        $this->assertSame(5, $this->listing('')->cursorPaginate(5)->perPage());
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

        $simple = Listing::for($team->members(), $request)->simplePaginate(5);
        $this->assertSame(['M2', 'M1'], $simple->getCollection()->pluck('name')->all());
        $this->assertSame('editor', $simple->getCollection()->first()?->pivot?->role);

        $cursor = Listing::for($team->members(), $request)->cursorPaginate(5);
        $this->assertSame(['M7', 'M6', 'M5', 'M4', 'M3'], $cursor->getCollection()->pluck('name')->all());
        $this->assertSame('owner', $cursor->getCollection()->first()?->pivot?->role);
    }

    /** Laravel's own resolver takes ?page=9223372036854775807, a 500 on PHP 8.5; and a simple page never counts. */
    public function test_simple_paginate_caps_the_page_keeps_the_query_string_and_never_counts(): void
    {
        $this->seedProducts(20);
        DB::enableQueryLog();

        $page = $this->listing('tab=open&page=2')->simplePaginate(5);

        $this->assertInstanceOf(Paginator::class, $page);
        $this->assertSame(['P15', 'P14', 'P13', 'P12', 'P11'], $page->getCollection()->pluck('name')->all());
        $this->assertSame('http://localhost/products?tab=open&page=3', $page->nextPageUrl());
        $this->assertSame([], array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'count(')));

        foreach (['page=9223372036854775807', 'page=' . (intdiv(PHP_INT_MAX, 5) + 1), 'page[]=2', 'page=abc', 'page=0', 'page=-1'] as $query) {
            $this->assertSame(1, $this->listing($query)->simplePaginate(5)->currentPage(), $query);
        }
    }

    /** A cursor from another order, one forged to hold a null, garbage or an array: each shows the first page. */
    public function test_cursor_paginate_pages_by_cursor_and_shows_the_first_page_for_a_bad_cursor(): void
    {
        $this->seedProducts(7);
        $byPrice = fn (string $query = ''): Listing => $this->listing('sort=price' . $query)->sorts('price', 'name');
        $names = fn (CursorPaginator $page): array => $page->getCollection()->pluck('name')->all();

        $first = $byPrice()->cursorPaginate(3);
        $this->assertSame(['P3', 'P6', 'P1'], $names($first));
        $this->assertStringContainsString('sort=price', (string)$first->nextPageUrl());
        $this->assertSame(['P4', 'P7', 'P2'], $names($byPrice('&cursor=' . $first->nextCursor()?->encode())->cursorPaginate(3)));

        $foreign = $this->listing('sort=name')->sorts('price', 'name')->cursorPaginate(3)->nextCursor()?->encode();
        $forged = new Cursor(['products.price' => null, 'products.id' => null])->encode();

        foreach (['another order' => "&cursor={$foreign}", 'a forged null' => "&cursor={$forged}", 'garbage' => '&cursor=garbage', 'an array' => '&cursor[]=x'] as $case => $query) {
            $this->assertSame(['P3', 'P6', 'P1'], $names($byPrice($query)->cursorPaginate(3)), $case);
        }
    }

    /** PostgreSQL and SQL Server reject a cursor value the column cannot hold; SQLite and MySQL compare it and page on. */
    public function test_cursor_paginate_shows_the_first_page_for_a_cursor_value_the_column_cannot_hold(): void
    {
        $this->seedProducts(7);
        $forged = new Cursor(['products.price' => 'abc', 'products.id' => 'x'])->encode();

        $page = $this->listing("sort=price&cursor={$forged}")->sorts('price', 'name')->cursorPaginate(3);

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlsrv'], true)) {
            $this->assertSame(['P3', 'P6', 'P1'], $page->getCollection()->pluck('name')->all());
        }

        $this->assertLessThanOrEqual(3, $page->count());
    }

    /** Laravel reads each cursor value off the row by its name, so a joined column pages by its select alias. */
    public function test_cursor_paging_a_joined_column_by_its_select_alias_walks_every_row(): void
    {
        foreach (['Zeta' => ['A1', 'A2'], 'Alpha' => ['Z1', 'Z2'], 'Mid' => ['M1', 'M2']] as $category => $names) {
            $id = Category::create(['name' => $category])->id;

            foreach ($names as $name) {
                Product::create(['name' => $name, 'category_id' => $id]);
            }
        }

        $pages = [];
        $cursor = '';

        do {
            $request = Request::create('/products?sort=category&cursor=' . $cursor);
            $this->app->instance('request', $request);
            $query = Product::query()->join('categories', 'categories.id', '=', 'products.category_id')->select('products.*', 'categories.name as category_name');
            $page = Listing::for($query, $request)->sorts(category: 'category_name')->cursorPaginate(2);
            $pages[] = implode(',', $page->getCollection()->pluck('name')->all());
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null && count($pages) < 5);

        $this->assertSame(['Z1,Z2', 'M1,M2', 'A1,A2'], $pages);
    }

    /** Like every other parameter, the cursor comes from the query string, never a JSON body. */
    public function test_cursor_paginate_reads_the_cursor_from_the_query_string_only(): void
    {
        $this->seedProducts(7);
        $next = $this->listing('sort=price')->sorts('price', 'name')->cursorPaginate(3)->nextCursor()?->encode();

        $json = Request::create('/products?sort=price', 'GET', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode(['cursor' => $next]));
        $this->app->instance('request', $json);
        $page = Listing::for(Product::query(), $json)->sorts('price', 'name')->cursorPaginate(3);

        $this->assertSame(['P3', 'P6', 'P1'], $page->getCollection()->pluck('name')->all());
    }
}
