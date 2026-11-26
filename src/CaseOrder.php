<?php

declare(strict_types=1);

namespace Listing;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\Builder;
use InvalidArgumentException;

/** @internal An enum column in its cases' declared order; a value no case holds, or null, comes after them all. */
final readonly class CaseOrder
{
    /** @param  list<int|string>  $values  the backing values, in declared order */
    private function __construct(
        private string $column,
        private array $values,
    ) {}

    /** @param  class-string<BackedEnum>  $enum */
    public static function of(string $enum, string $column): self
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("[{$enum}] is not a backed enum.");
        }

        return new self($column, array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases()));
    }

    /** `case <column> when ? then 0 when ? then 1 … else <count> end`, its values bound. */
    public function orderBy(Builder $query, Direction $direction): void
    {
        $whens = implode(' ', array_map(static fn (int $position): string => "when ? then {$position}", array_keys($this->values)));
        $column = $query->getQuery()->getGrammar()->wrap($this->column);

        $query->orderByRaw("case {$column} {$whens} else " . count($this->values) . " end {$direction->value}", $this->values);
    }
}
