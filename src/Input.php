<?php

declare(strict_types=1);

namespace Listing;

use Illuminate\Support\Str;

/** @internal Narrows one raw query-string value: null when it does not read. */
final class Input
{
    /** Trimmed and non-empty. Invalid UTF-8 and NUL read as null: databases mishandle them. */
    public static function string(mixed $input, int $max = 255): ?string
    {
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8') || str_contains($input, "\0")) {
            return null;
        }

        $input = Str::trim($input);

        return $input === '' || mb_strlen($input) > $max ? null : $input;
    }

    /**
     * 0 to $max. Digits only, as (int) 'nope' is 0; range-checked, as (int) saturates; leading zeros stripped, as
     * FILTER_VALIDATE_INT rejects them.
     */
    public static function naturalInt(mixed $input, int $max): ?int
    {
        $digits = self::string($input) ?? '';
        $range = ['options' => ['max_range' => $max], 'flags' => FILTER_NULL_ON_FAILURE];

        return ctype_digit($digits) ? filter_var(ltrim($digits, '0') ?: '0', FILTER_VALIDATE_INT, $range) : null;
    }

    /** 1 to $max. */
    public static function positiveInt(mixed $input, int $max): ?int
    {
        return self::naturalInt($input, $max) ?: null;
    }
}
