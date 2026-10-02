<?php

declare(strict_types=1);

namespace Listing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Team extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsToMany<Member, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('role')->withTimestamps();
    }
}
