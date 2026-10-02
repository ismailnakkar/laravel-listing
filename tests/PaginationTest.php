<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
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
        $this->assertSame(15, $this->listing('')->paginate(0)->perPage());
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

    /** exe-laravel's link lists page by cursor through apply(); the README's guard turns a bad cursor into the first page. */
    public function test_apply_cursor_paginates_and_the_readme_guard_catches_a_foreign_or_forged_cursor(): void
    {
        $this->seedProducts(7);
        $byPrice = fn (): Listing => $this->listing('sort=price')->sorts('price', 'name');

        $first = $byPrice()->apply()->cursorPaginate(3);
        $next = $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', $first->nextCursor()?->encode());
        $this->assertSame(['P4', 'P7', 'P2'], $next->getCollection()->pluck('name')->all());
        $this->assertSame(['P3', 'P6', 'P1'], $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', 'garbage')->getCollection()->pluck('name')->all());

        // The README's guard, as written there.
        $guarded = function (?string $cursor) use ($byPrice): array {
            try {
                $links = $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', $cursor);
            } catch (UnexpectedValueException|InvalidArgumentException) {
                $links = $byPrice()->apply()->cursorPaginate(3, ['*'], 'cursor', '');
            }

            return $links->getCollection()->pluck('name')->all();
        };

        $foreign = $this->listing('sort=name')->sorts('price', 'name')->apply()->cursorPaginate(3)->nextCursor()?->encode();
        $forged = new Cursor(['products.price' => null, 'products.id' => null])->encode();

        foreach (['another order' => $foreign, 'a forged null' => $forged] as $case => $cursor) {
            $this->assertSame(['P3', 'P6', 'P1'], $guarded($cursor), $case);
        }
    }
}
