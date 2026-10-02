<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

enum Status: int
{
    case draft = 0;
    case live = 1;
}
