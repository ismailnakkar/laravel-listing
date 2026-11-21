<?php

declare(strict_types=1);

namespace Listing;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use InvalidArgumentException;
use ReflectionEnum;

/** @internal One filter: how its input narrows to a type, and the condition it adds. */
final readonly class Filter
{
    /** A backstop for a raised max_input_vars: each value is a binding. */
    private const int MAX_VALUES = 1000;

    /** It has no next day: a 5-digit year compares wrong as text. */
    private const string LAST_DAY = '9999-12-31';

    private function __construct(
        public string $key,
        private Closure $read,
        private Closure $apply,
    ) {}

    public static function int(string $key, string|Closure $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?int => Input::naturalInt($input, $max), self::where($column));
    }

    public static function text(string $key, string|Closure $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => Input::string($input, $max), self::where($column));
    }

    /** '0' is false, not absent: "No" is an answer. */
    public static function flag(string $key, string|Closure $column): self
    {
        return new self($key, fn (mixed $input): ?bool => match (Input::string($input)) {
            '1'     => true,
            '0'     => false,
            default => null,
        }, self::where($column));
    }

    /** @param  class-string<BackedEnum>  $enum */
    public static function enum(string $key, string $enum, string|Closure $column): self
    {
        return new self($key, self::caseOf($enum), self::where($column));
    }

    /** @param  class-string<BackedEnum>  $enum */
    public static function enums(string $key, string $enum, string|Closure $column): self
    {
        return self::many($key, self::caseOf($enum), $column);
    }

    public static function ints(string $key, string|Closure $column, int $max): self
    {
        return self::many($key, fn (mixed $input): ?int => Input::naturalInt($input, $max), $column);
    }

    /**
     * PostgreSQL casts each column to text, so a number, date or uuid can be searched.
     *
     * @param  string|Expression|non-empty-list<string|Expression>  $columns
     */
    public static function search(string $key, string|Expression|array $columns, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => Input::string($input, $max), function (Builder $query, string $term) use ($columns): void {
            $pattern = self::likePattern($term);
            $grammar = $query->getQuery()->getGrammar();
            $like = $grammar instanceof PostgresGrammar ? "(%s)::text ilike ? escape '!'" : "%s like ? escape '!'";

            $query->where(function (Builder $query) use ($columns, $grammar, $like, $pattern): void {
                foreach (is_array($columns) ? $columns : [$columns] as $column) {
                    $query->orWhereRaw(sprintf($like, $grammar->wrap($column)), [$pattern]);
                }
            });
        });
    }

    /** @param  '='|'>='|'<='  $operator */
    public static function date(string $key, string|Closure $column, string $operator): self
    {
        [$lower, $upper] = match ($operator) {
            '='  => [true, true],
            '>=' => [true, false],
            '<=' => [false, true],
        };

        $condition = $column instanceof Closure ? $column : self::wholeDays($column, $lower, $upper);

        return new self($key, self::day(...), $condition);
    }

    public function read(mixed $input): mixed
    {
        return ($this->read)($input);
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($value !== null && $value !== []) {
            ($this->apply)($query, $value);
        }
    }

    private static function where(string|Closure $column): Closure
    {
        return $column instanceof Closure ? $column : fn (Builder $query, mixed $value) => $query->where($column, $value);
    }

    /** Over MAX_VALUES values read as empty. */
    private static function many(string $key, Closure $read, string|Closure $column): self
    {
        return new self($key, function (mixed $input) use ($read): array {
            $inputs = is_array($input) ? $input : [$input];
            $values = [];

            foreach (count($inputs) > self::MAX_VALUES ? [] : $inputs as $item) {
                $value = $read($item);

                if ($value !== null) {
                    $values[$value instanceof BackedEnum ? $value->value : $value] = $value;
                }
            }

            return array_values($values);
        }, $column instanceof Closure ? $column : fn (Builder $query, array $values) => $query->whereIn($column, $values));
    }

    /**
     * An int-backed enum reads digits only, as (int) 'nope' is 0 and 0 can be a case. A pure enum throws here, at
     * declaration, not on the first value.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    private static function caseOf(string $enum): Closure
    {
        $reflection = new ReflectionEnum($enum);

        if (! $reflection->isBacked()) {
            throw new InvalidArgumentException("[{$enum}] is not a backed enum.");
        }

        $int = $reflection->getBackingType()->getName() === 'int';

        return function (mixed $input) use ($enum, $int): ?BackedEnum {
            $value = Input::string($input);

            if ($value === null || ($int && ! ctype_digit($value))) {
                return null;
            }

            return $enum::tryFrom($int ? (int)$value : $value);
        };
    }

    /** `!` is the escape, as it is plain text in every database. */
    private static function likePattern(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /** A real `Y-m-d`. */
    private static function day(mixed $input): ?string
    {
        $day = Input::string($input, 10);

        return $day !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) === 1
            && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $day : null;
    }

    /** Half-open string bounds, not whereDate(), so an index serves it. */
    private static function wholeDays(string $column, bool $lower, bool $upper): Closure
    {
        return function (Builder $query, string $day) use ($column, $lower, $upper): void {
            $start = new DateTimeImmutable($day, new DateTimeZone('UTC'));

            if ($lower) {
                $query->where($column, '>=', $start->format('Y-m-d'));
            }

            // Up to the last day is any date.
            if ($upper && $day !== self::LAST_DAY) {
                $query->where($column, '<', $start->modify('+1 day')->format('Y-m-d'));
            } elseif ($upper && ! $lower) {
                $query->whereNotNull($column);
            }
        };
    }
}
