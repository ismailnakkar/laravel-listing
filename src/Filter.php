<?php

declare(strict_types=1);

namespace Listing;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionEnum;

/**
 * @internal One declared filter: how its query-string value narrows to a type, and the condition it adds. A value that
 * does not narrow reads as null, or as an empty list for a list filter, and adds nothing.
 */
final readonly class Filter
{
    private function __construct(
        public string $key,
        private Closure $read,
        private Closure $apply,
    ) {}

    public static function int(string $key, string|Closure $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?int => self::positiveInt($input, $max), self::where($column));
    }

    public static function text(string $key, string|Closure $column, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => self::cleanString($input, $max), self::where($column));
    }

    /** '1' is true and '0' false, because "No" is an answer, not an absent filter; anything else is null. */
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

    /**
     * Any of the cases a list names: `?status[]=draft&status[]=live`, or one `?status=draft`.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function enums(string $key, string $enum, string|Closure $column): self
    {
        return self::many($key, self::caseOf($enum), $column);
    }

    /** Any of the positive ints up to $max a list names: `?category[]=3&category[]=7`, or one `?category=3`. */
    public static function ints(string $key, string|Closure $column, int $max): self
    {
        return self::many($key, fn (mixed $input): ?int => self::positiveInt($input, $max), $column);
    }

    /**
     * Rows where any of the columns contains the term as typed, in any letter case the database lowercases. `%` and
     * `_` are LIKE wildcards; `!` escapes them because it is plain text in every database's string literals, so one
     * ESCAPE clause works on MySQL, MariaDB, PostgreSQL, SQLite and SQL Server, which do not agree on a default; `[`
     * opens a character set on SQL Server. PostgreSQL has no lower() for a number, date or uuid, so a column is cast to
     * text there, as Laravel's own LIKE does; the parentheses keep a JSON path (`meta->tags[0]`) whole.
     *
     * @param  string|non-empty-list<string>  $columns
     */
    public static function search(string $key, string|array $columns, int $max): self
    {
        return new self($key, fn (mixed $input): ?string => self::cleanString($input, $max), function (Builder $query, string $term) use ($columns): void {
            $pattern = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']) . '%';
            $grammar = $query->getQuery()->getGrammar();
            $text = $grammar instanceof PostgresGrammar ? '(%s)::text' : '%s';

            $query->where(function (Builder $query) use ($columns, $grammar, $pattern, $text): void {
                foreach ((array)$columns as $column) {
                    $query->orWhereRaw('lower(' . sprintf($text, $grammar->wrap($column)) . ") like lower(?) escape '!'", [$pattern]);
                }
            });
        });
    }

    /**
     * A day as `<input type="date">` sends it, `2026-10-01`, from 1753-01-01, where SQL Server's legacy `datetime`
     * starts, to 9999-12-31. The operator compares whole days: '=' keeps that day, '>=' that day or later, '<=' that
     * day or earlier, through its last instant. A closure in place of the column gets the day string.
     *
     * The range is half-open and bound as plain strings, not whereDate(), so an index serves it, and not a DateTime,
     * which SQLite compares as text against a `Y-m-d` column. SQL Server gets `Ymd`, which reads the same in every
     * login language. Both bounds come from one UTC date, so a zone that skipped a day cannot shift them; 9999-12-31
     * has no next day, so as an upper bound alone it only keeps NULL dates out.
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

    /**
     * The trimmed string, or null when the input is not a string, is empty once trimmed, is longer than $max
     * characters, or holds bytes a database mishandles as text: invalid UTF-8 errors on PostgreSQL and matches every
     * row on MySQL, and a NUL cuts a LIKE pattern short on SQLite and PostgreSQL. Str::trim, so a pasted non-breaking
     * space goes too.
     */
    public static function cleanString(mixed $input, int $max = 255): ?string
    {
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            return null;
        }

        $input = Str::trim($input);

        return $input === '' || mb_strlen($input) > $max ? null : $input;
    }

    /** A positive integer up to $max, or null: digits only, because (int) 'nope' is 0, and in range, because (int) saturates. */
    public static function positiveInt(mixed $input, int $max = PHP_INT_MAX): ?int
    {
        $digits = ltrim(self::cleanString($input) ?? '', '0');
        $range = ['options' => ['max_range' => $max], 'flags' => FILTER_NULL_ON_FAILURE];

        return ctype_digit($digits) ? filter_var($digits, FILTER_VALIDATE_INT, $range) : null;
    }

    /**
     * Reads the case a value names. An int-backed enum is read from digits only: (int) 'nope' is 0, and 0 can be a case.
     * A class that is not a backed enum throws here, where the screen declares it, not when a value first arrives.
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

    /**
     * A list filter: each value read by $one, the unreadable dropped, duplicates removed, matched with whereIn(). The
     * value is the list, empty when nothing reads. More than 1000 values read as empty: a backstop for a server that
     * raises max_input_vars, since SQL Server takes at most 2100 bindings.
     */
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

    /** The input narrowed to this filter's type, or null; for a list filter, the list. */
    public function read(mixed $input): mixed
    {
        return ($this->read)($input);
    }

    /** Adds this filter's condition for a narrowed value; null or an empty list adds nothing. */
    public function apply(Builder $query, mixed $value): void
    {
        if ($value !== null && $value !== []) {
            ($this->apply)($query, $value);
        }
    }

    /** The column as written, or the developer's closure in its place. */
    private static function where(string|Closure $column): Closure
    {
        return $column instanceof Closure ? $column : fn (Builder $query, mixed $value) => $query->where($column, $value);
    }
}
