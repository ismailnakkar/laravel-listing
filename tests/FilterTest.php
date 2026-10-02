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
        $this->assertSame('ééé', $this->listing('name=%C3%A9%C3%A9%C3%A9')->text('name', max: 3)->values['name']);
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

    /** On a self-join every bare column is ambiguous, so this fails unless each defaulted column names the model's table. */
    public function test_enums_read_a_list_of_cases_and_drop_the_rest(): void
    {
        $status = fn (string $query): mixed => $this->listing($query)->enums('status', Status::class)->values['status'];

        $this->assertSame([Status::draft, Status::live], $status('status[]=0&status[]=1'));
        $this->assertSame([Status::live], $status('status=1'));
        $this->assertSame([Status::live], $status('status[]=1&status[]=01&status[]=nope&status[]=&status[x][]=0'));

        foreach (['', 'status=', 'status[]=nope', 'status[a][b]=1', 'status[]=%FF'] as $query) {
            $this->assertSame([], $status($query), $query);
        }

        $this->assertSame([Kind::book], $this->listing('kind[]=book&kind[]=BOOK&kind[]=book')->enums('kind', Kind::class)->values['kind']);
    }

    public function test_ids_read_a_list_of_positive_ints_within_max(): void
    {
        $ids = fn (string $query, int $max = PHP_INT_MAX): mixed => $this->listing($query)->ids('category', max: $max)->values['category'];

        $this->assertSame([3, 1], $ids('category[]=3&category[]=1&category[]=003'));
        $this->assertSame([7], $ids('category=7'));
        $this->assertSame([5], $ids('category[]=5&category[]=2147483648', 2147483647));
        $this->assertSame([], $ids('category[]=0&category[]=abc&category[]=-1&category[][]=2'));
    }

    /** A GET with a JSON body is not bound by max_input_vars, and SQL Server takes at most 2100 bindings. */
    public function test_a_list_of_more_than_1000_values_reads_as_empty(): void
    {
        $ids = fn (int $count): mixed => Listing::for(Product::query(), Request::create('/', 'GET', ['category' => array_map(strval(...), range(1, $count))]))
            ->ids('category')->values['category'];

        $this->assertCount(1000, $ids(1000));
        $this->assertSame([], $ids(1001));
    }

    /** A list page's state is its URL: a JSON or form body never sets a filter, a sort or a page. */
    public function test_only_the_query_string_is_read(): void
    {
        $json = Request::create('/?name=Desk', 'GET', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string)json_encode(['category' => ['1', '2'], 'name' => 'Lamp']));
        $listing = Listing::for(Product::query(), $json)->ids('category')->text('name');

        $this->assertSame([], $listing->values['category']);
        $this->assertSame('Desk', $listing->values['name']);

        $posted = Listing::for(Product::query(), Request::create('/?sort=price', 'POST', ['name' => 'Lamp', 'sort' => 'name']))->text('name')->sorts('name', 'price');

        $this->assertNull($posted->values['name']);
        $this->assertSame('price', $posted->sort);
    }

    public function test_a_list_filter_keeps_rows_matching_any_value_and_an_empty_list_adds_nothing(): void
    {
        Product::create(['name' => 'Desk', 'kind' => 'book', 'status' => 1, 'category_id' => 1]);
        Product::create(['name' => 'Floor', 'kind' => 'film', 'status' => 0, 'category_id' => 2]);
        Product::create(['name' => 'Wall', 'kind' => 'film', 'status' => 1, 'category_id' => 3]);
        $calls = 0;

        $names = function (string $query) use (&$calls): array {
            return $this->listing($query)
                ->enums('status', Status::class)
                ->ids('category', 'category_id')
                ->enums('kind', Kind::class, function (Builder $q, array $kinds) use (&$calls): void {
                    $calls++;
                    $q->whereIn('kind', $kinds);
                })
                ->defaultSort('id')
                ->apply()->pluck('name')->all();
        };

        $this->assertSame(['Desk', 'Wall'], $names('status[]=1'));
        $this->assertSame(['Desk', 'Floor', 'Wall'], $names('status[]=0&status[]=1'));
        $this->assertSame(['Desk', 'Wall'], $names('category[]=1&category[]=3'));
        $this->assertSame(['Floor', 'Wall'], $names('kind[]=film'));
        $this->assertSame(['Desk', 'Floor', 'Wall'], $names('status[]=nope&category[]=0&kind[]=BOOK'));
        $this->assertSame(1, $calls);
    }

    public function test_a_column_left_to_default_is_the_models_own_on_a_join(): void
    {
        Product::create(['name' => 'Desk', 'status' => 1, 'paid' => true, 'day' => '2026-10-01']);
        Product::create(['name' => 'Floor', 'status' => 0, 'paid' => false, 'day' => '2026-10-02']);

        $query = Product::query()->join('products as twin', 'twin.id', '=', 'products.id')->select('products.*');
        $listing = Listing::for($query, Request::create('/?name=Desk&id=1&paid=1&status=1&day=2026-10-01'))
            ->text('name')->id()->flag('paid')->enum('status', Status::class)->date('day');

        $this->assertSame(['Desk'], $listing->apply()->pluck('name')->all());
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

        // A column passed bare is used as written: `role` is only on the pivot, so it resolves there, not on `members`.
        $bare = Listing::for($team->members(), $request)->text('role', 'role')->paginate();
        $this->assertSame(['Bob'], $bare->getCollection()->pluck('name')->all());
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
