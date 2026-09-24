<?php

declare(strict_types=1);

namespace Gabrielesbaiz\DuplicateToolkit\Support;

use Stringable;

/**
 * Narrowing helpers for the many mixed values the framework hands us, such as
 * configuration values, console arguments and raw attribute values.
 *
 * @internal
 */
final class Cast
{
    /**
     * Narrow the given value to a string, falling back to the default.
     */
    public static function toString(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $default;
    }

    /**
     * Narrow the given value to an integer, falling back to the default.
     */
    public static function toInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * Narrow the given value to a boolean, falling back to the default.
     */
    public static function toBool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : $default);
    }

    /**
     * Get only the string entries of an arbitrary value.
     *
     * @return array<int, string>
     */
    public static function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return is_string($value) ? [$value] : [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $strings[] = $entry;
            } elseif (is_int($entry) || is_float($entry)) {
                $strings[] = (string) $entry;
            }
        }

        return $strings;
    }
}
