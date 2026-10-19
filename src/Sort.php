<?php

declare(strict_types=1);

namespace Listing;

use Illuminate\Contracts\Database\Eloquent\Builder;

/** @internal `?sort=`: `total` is ascending, `-total` descending. */
final readonly class Sort
{
    /** @param  'asc'|'desc'  $direction */
    public function __construct(
        public string $name,
        public string $direction,
    ) {}

    public static function parse(string $sort): self
    {
        return str_starts_with($sort, '-') ? new self(substr($sort, 1), 'desc') : new self($sort, 'asc');
    }

    public function toggled(string $name): self
    {
        if ($name !== $this->name) {
            return new self($name, 'desc');
        }

        return new self($name, $this->direction === 'asc' ? 'desc' : 'asc');
    }

    /**
     * Replaces any order, so a relation's own `latest()` cannot outrank the header, then breaks ties on the key so rows
     * keep their place across pages; the key goes in once, as SQL Server rejects a repeated ORDER BY column.
     */
    public function apply(Builder $query, string $column): void
    {
        $key = $query->getModel()->getQualifiedKeyName();

        $query->reorder($column, $this->direction);

        if ($column !== $key) {
            $query->orderBy($key, $this->direction);
        }
    }

    public function __toString(): string
    {
        return ($this->direction === 'desc' ? '-' : '') . $this->name;
    }
}
