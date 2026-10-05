<?php

declare(strict_types=1);

namespace Listing\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    /** A default collation, ILIKE and SQLite's own LIKE all ignore case. */
    public function test_a_term_matches_in_any_letter_case(): void
    {
        $this->assertSame(['Alice'], $this->search('name', 'aLICE'));
    }

    /** The column is compared as stored, so its collation decides letter case and no function runs per row. */
    public function test_the_column_is_not_lowercased(): void
    {
        $query = Listing::for(Product::query(), Request::create('/?q=Alice'))->search('q', 'name')->apply();

        $this->assertStringNotContainsStringIgnoringCase('lower(', $query->toSql());
    }

    /** A `_bin` column matches letter case exactly, as its collation says. */
    public function test_a_binary_collated_column_matches_letter_case_exactly(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A per-column binary collation is a MySQL and MariaDB check.');
        }

        Schema::table('products', fn (Blueprint $table) => $table->string('reference')->nullable()->collation('utf8mb4_bin')->change());
        Product::create(['name' => 'Binary', 'reference' => 'Alice']);

        $this->assertSame([], $this->search('reference', 'ALICE'));
        $this->assertSame(['Binary'], $this->search('reference', 'Alice'));
    }

    /** The columns are OR'ed inside their own group, so a where() before the search still holds. */
    public function test_any_of_the_columns_may_match_inside_their_own_group(): void
    {
        $this->assertSame(['Lamp shade'], $this->search('name', 'lamp'));
        $this->assertSame(['Desk', 'Lamp shade'], $this->search(['name', 'kind'], 'lamp'));
        $this->assertSame(['Lamp shade'], $this->search(['name', 'kind'], 'lamp', Product::query()->where('kind', 'textile')));
    }

    /** As for every other filter, no columns is the key's own column, qualified, so a join with its own `name` holds. */
    public function test_with_no_columns_the_key_is_the_models_own_column(): void
    {
        $query = Product::query()->join('products as twin', 'twin.id', '=', 'products.id')->select('products.*');

        $this->assertSame(['Lamp shade'], Listing::for($query, Request::create('/?name=lamp'))->search('name')->apply()->pluck('name')->all());
    }

    /** PostgreSQL has no ILIKE for a number, so the column is cast to text there; the PostgreSQL CI leg pins it. */
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
