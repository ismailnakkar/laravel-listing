<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Category extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
