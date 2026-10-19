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
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionEnum;

/** @internal One filter: how its input narrows to a type, and the condition it adds. */
final readonly class Filter
{
    private function __construct(
        public string $key,
        private Closure $read,
        private Closure $apply,
    ) {}

    public static function int(string $key, string|Closure $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?int => self::naturalInt($input, $max), self::where($column));
    }

    public static function text(string $key, string|Closure $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => self::cleanString($input, $max), self::where($column));
    }

    /** '0' is false, not absent: "No" is an answer. */
    public static function flag(string $key, string|Closure $column): self
    {
        return new self($key, fn (mixed $input): ?bool => match (self::cleanString($input)) {
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
        return self::many($key, fn (mixed $input): ?int => self::naturalInt($input, $max), $column);
    }

    /**
     * `!` is the escape as it is plain text in every database; `[` is a wildcard on SQL Server. PostgreSQL casts each
     * column to text, so a number, date or uuid can be searched.
     *
     * @param  string|Expression|non-empty-list<string|Expression>  $columns
     */
    public static function search(string $key, string|Expression|array $columns, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => self::cleanString($input, $max), function (Builder $query, string $term) use ($columns): void {
            $pattern = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']) . '%';
            $grammar = $query->getQuery()->getGrammar();
            $like = $grammar instanceof PostgresGrammar ? "(%s)::text ilike ? escape '!'" : "%s like ? escape '!'";

            $query->where(function (Builder $query) use ($columns, $grammar, $like, $pattern): void {
                foreach (is_array($columns) ? $columns : [$columns] as $column) {
                    $query->orWhereRaw(sprintf($like, $grammar->wrap($column)), [$pattern]);
                }
            });
        });
    }

    /**
     * `Y-m-d` from 1753-01-01, where SQL Server's datetime starts. Half-open string bounds, not whereDate(), so an
     * index serves it.
     *
     * @param  '='|'>='|'<='  $operator
     */
    public static function date(string $key, string|Closure $column, string $operator): self
    {
        [$lower, $upper] = match ($operator) {
            '='  => [true, true],
            '>=' => [true, false],
            '<=' => [false, true],
        };

        return new self($key, function (mixed $input): ?string {
            $day = self::cleanString($input, 10);

            return $day !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $m) === 1
                && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && $day >= '1753-01-01' ? $day : null;
        }, $column instanceof Closure ? $column : function (Builder $query, string $day) use ($column, $lower, $upper): void {
            $format = $query->getQuery()->getGrammar() instanceof SqlServerGrammar ? 'Ymd' : 'Y-m-d';
            $start = new DateTimeImmutable($day, new DateTimeZone('UTC'));

            if ($lower) {
                $query->where($column, '>=', $start->format($format));
            }

            if ($upper && $day !== '9999-12-31') {
                $query->where($column, '<', $start->modify('+1 day')->format($format));
            } elseif ($upper && ! $lower) {
                $query->whereNotNull($column);
            }
        });
    }

    /** Invalid UTF-8 and NUL read as null: databases mishandle them. */
    public static function cleanString(mixed $input, int $max = 255): ?string
    {
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            return null;
        }

        $input = Str::trim($input);

        return $input === '' || mb_strlen($input) > $max ? null : $input;
    }

    /**
     * Digits only, as (int) 'nope' is 0; range-checked, as (int) saturates; leading zeros stripped, as
     * FILTER_VALIDATE_INT rejects them.
     */
    private static function naturalInt(mixed $input, int $max): ?int
    {
        $digits = self::cleanString($input) ?? '';
        $range = ['options' => ['max_range' => $max], 'flags' => FILTER_NULL_ON_FAILURE];

        return ctype_digit($digits) ? filter_var(ltrim($digits, '0') ?: '0', FILTER_VALIDATE_INT, $range) : null;
    }

    public static function positiveInt(mixed $input, int $max = PHP_INT_MAX): ?int
    {
        return self::naturalInt($input, $max) ?: null;
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
            $value = self::cleanString($input);

            if ($value === null || ($int && ! ctype_digit($value))) {
                return null;
            }

            return $enum::tryFrom($int ? (int)$value : $value);
        };
    }

    /** Over 1000 values read as empty: a backstop for a raised max_input_vars, as SQL Server takes 2100 bindings. */
    private static function many(string $key, Closure $one, string|Closure $column): self
    {
        return new self($key, function (mixed $input) use ($one): array {
            $inputs = is_array($input) ? $input : [$input];
            $values = [];

            foreach (count($inputs) > 1000 ? [] : $inputs as $item) {
                $value = $one($item);

                if ($value !== null) {
                    $values[$value instanceof BackedEnum ? $value->value : $value] = $value;
                }
            }

            return array_values($values);
        }, $column instanceof Closure ? $column : fn (Builder $query, array $values) => $query->whereIn($column, $values));
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
}
