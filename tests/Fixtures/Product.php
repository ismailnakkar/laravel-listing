<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['day_cast' => 'date'];
    }
}
