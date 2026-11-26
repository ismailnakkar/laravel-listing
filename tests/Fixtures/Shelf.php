<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

/** Declared top to bottom, which is not alphabetical. */
enum Shelf: string
{
    case top = 'top';
    case middle = 'middle';
    case bottom = 'bottom';
}
