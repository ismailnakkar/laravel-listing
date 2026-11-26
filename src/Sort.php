<?php

declare(strict_types=1);

namespace Listing;

use Illuminate\Contracts\Database\Eloquent\Builder;

/** @internal `?sort=`: `total` is ascending, `-total` descending. */
final readonly class Sort
{
    private const string DESCENDING = '-';

    public function __construct(
        public string $name,
        public Direction $direction,
    ) {}

    public static function parse(string $sort): self
    {
        return str_starts_with($sort, self::DESCENDING)
            ? new self(substr($sort, strlen(self::DESCENDING)), Direction::desc)
            : new self($sort, Direction::asc);
    }

    /** Flipped on its own column; any other starts descending. */
    public function toggled(string $name): self
    {
        return new self($name, $name === $this->name ? $this->direction->toggled() : Direction::desc);
    }

    /**
     * Replaces any order, so a relation's own `latest()` cannot outrank the header, then breaks ties on the key so rows
     * keep their place across pages; the key goes in once.
     */
    public function apply(Builder $query, string|CaseOrder $by): void
    {
        $key = $query->getModel()->getQualifiedKeyName();

        $query->reorder();

        if ($by instanceof CaseOrder) {
            $by->orderBy($query, $this->direction);
        } else {
            $query->orderBy($by, $this->direction->value);
        }

        if ($by !== $key) {
            $query->orderBy($key, $this->direction->value);
        }
    }

    public function __toString(): string
    {
        return ($this->direction === Direction::desc ? self::DESCENDING : '') . $this->name;
    }
}
