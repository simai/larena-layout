<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

use stdClass;

/**
 * The JavaScript readings the SIMAI Framework's renderer relies on, for a Document decoded as JSON objects
 * (json_decode without assoc): a JSON object is a stdClass, an array a list. String length is counted in UTF-16 code
 * units and an integer is any number without a fraction, as in JavaScript, so a limit or a type reads the same here.
 */
final class Js
{
    // JavaScript's \s and String.prototype.trim(): ASCII white space, NBSP, the Unicode spaces and the BOM.
    public const WHITE_SPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass;
    }

    public static function has(mixed $object, string $key): bool
    {
        return $object instanceof stdClass && property_exists($object, $key);
    }

    public static function get(mixed $object, string $key): mixed
    {
        return $object instanceof stdClass && property_exists($object, $key) ? $object->{$key} : null;
    }

    /** @return array<string, mixed> */
    public static function entries(mixed $object): array
    {
        return $object instanceof stdClass ? get_object_vars($object) : [];
    }

    public static function isInteger(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /** The UTF-16 length of a string, as String.prototype.length counts it. */
    public static function length(string $value): int
    {
        $length = 0;
        foreach (mb_str_split($value, 1, 'UTF-8') as $character) {
            $length += mb_ord($character, 'UTF-8') > 0xFFFF ? 2 : 1;
        }

        return $length;
    }

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^['.self::WHITE_SPACE.']+|['.self::WHITE_SPACE.']+$/uD', '', $value);
    }

    /** The typeof-like name JSON Schema validation uses: null, array, integer, number, string, boolean or object. */
    public static function typeName(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_array($value) => 'array',
            self::isInteger($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            default => 'object',
        };
    }

    /** Strict equality of two JSON values, numbers compared by value (1 and 1.0 are one number in JavaScript). */
    public static function same(mixed $left, mixed $right): bool
    {
        if (self::isNumber($left) && self::isNumber($right)) {
            return (float) $left === (float) $right;
        }

        return $left === $right;
    }

    /** String(value) for a JSON scalar. */
    public static function string(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            is_string($value) => $value,
            is_array($value) => implode(',', array_map(static fn (mixed $entry): string => $entry === null ? '' : self::string($entry), $value)),
            default => '[object Object]',
        };
    }

    public static function number(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if (floor($value) === $value && abs($value) < 1e21) {
            return sprintf('%.0f', $value);
        }

        return (string) json_encode($value);
    }

    /** JSON.stringify with every object's keys sorted: the identity of a value whatever its key order. */
    public static function stableStringify(mixed $value): string
    {
        return (string) json_encode(self::canonical($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }
        if ($value instanceof stdClass) {
            $entries = get_object_vars($value);
            ksort($entries, SORT_STRING);
            $sorted = new stdClass();
            foreach ($entries as $key => $entry) {
                $sorted->{$key} = self::canonical($entry);
            }

            return $sorted;
        }

        return $value;
    }

    /** The escaping of the Framework's renderer, for text and attribute values alike. */
    public static function escapeHtml(mixed $value): string
    {
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&#39;'], self::string($value));
    }
}
