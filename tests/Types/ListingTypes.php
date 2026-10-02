<?php

declare(strict_types=1);

// PHPStan reads this file (phpstan.neon); PHPUnit skips it. Each assertType() fails analysis when the type drifts.

namespace Listing\Tests\Types;

use Illuminate\Http\Request;
use Listing\Listing;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Team;

use function PHPStan\Testing\assertType;

$request = Request::create('/');

$products = Listing::for(Product::query(), $request)->text('name')->sorts('price');
assertType('Listing\Listing<Listing\Tests\Fixtures\Product>', $products);
assertType('Illuminate\Pagination\LengthAwarePaginator<int, Listing\Tests\Fixtures\Product>', $products->paginate());
assertType('Listing\Tests\Fixtures\Product|null', $products->apply()->first());

$members = Listing::for((new Team)->members(), $request)->text('role', 'member_team.role');
assertType('Listing\Listing<Listing\Tests\Fixtures\Member>', $members);
