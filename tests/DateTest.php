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
