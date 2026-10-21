<?php

declare(strict_types=1);

// Read by PHPStan only (phpstan.neon): an assertType() fails analysis when the type drifts.

namespace Listing\Tests\Types;

use Listing\Listing;
use Listing\Tests\Fixtures\Product;
use Listing\Tests\Fixtures\Team;

use function PHPStan\Testing\assertType;

$products = Listing::for(Product::query())->text('name')->sorts('price');
assertType('Listing\Listing<Listing\Tests\Fixtures\Product>', $products);
assertType('Illuminate\Pagination\LengthAwarePaginator<int, Listing\Tests\Fixtures\Product>', $products->paginate());
assertType('Illuminate\Pagination\Paginator<int, Listing\Tests\Fixtures\Product>', $products->simplePaginate());
assertType('Illuminate\Pagination\CursorPaginator<int, Listing\Tests\Fixtures\Product>', $products->cursorPaginate());
assertType('Listing\Tests\Fixtures\Product|null', $products->apply()->first());

$members = Listing::for((new Team)->members())->text('role', 'member_team.role');
assertType('Listing\Listing<Listing\Tests\Fixtures\Member>', $members);
