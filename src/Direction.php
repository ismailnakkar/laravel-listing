<?php

declare(strict_types=1);

namespace Listing;

/** @internal A sort's direction, valued as the query builder takes it. */
enum Direction: string
{
    case asc = 'asc';
    case desc = 'desc';

    public function toggled(): self
    {
        return match ($this) {
            self::asc  => self::desc,
            self::desc => self::asc,
        };
    }

    /** The header's `aria-sort`. */
    public function aria(): string
    {
        return match ($this) {
            self::asc  => 'ascending',
            self::desc => 'descending',
        };
    }
}
