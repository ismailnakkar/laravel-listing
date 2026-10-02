<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    protected $guarded = [];

    /** `day_cast` is written the way Eloquent's date cast writes on SQLite: `2026-10-01 00:00:00`. */
    protected function casts(): array
    {
        return ['day_cast' => 'date'];
    }
}
