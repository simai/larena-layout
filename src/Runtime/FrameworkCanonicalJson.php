<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class FrameworkCanonicalJson
{
    public const PROFILE = 'simai.recipe.canonical-json.v1';
    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    public function encode(mixed $value): string
    {
        return $this->value($value);
    }

    public function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', $this->encode($value));
    }

    private function value(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            if (abs($value) > self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('framework_canonical_integer_unsafe');
            }
            return (string) $value;
        }
        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value || abs($value) > self::MAX_SAFE_INTEGER) {
                throw new InvalidArgumentException('framework_canonical_number_invalid');
            }
            return (string) (int) $value;
        }
        if (is_string($value)) {
            return $this->string($value);
        }
        if ($value instanceof stdClass) {
            return $this->object(get_object_vars($value));
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('framework_canonical_value_invalid');
        }
        if (array_is_list($value)) {
            return '['.implode(',', array_map(fn (mixed $item): string => $this->value($item), $value)).']';
        }
        return $this->object($value);
    }

    /** @param array<int|string,mixed> $value */
    private function object(array $value): string
    {
        $keys = array_keys($value);
        usort($keys, fn (int|string $left, int|string $right): int => $this->compareKeys($left, $right));
        $pairs = [];
        foreach ($keys as $key) {
            $pairs[] = $this->string((string) $key).':'.$this->value($value[$key]);
        }
        return '{'.implode(',', $pairs).'}';
    }

    private function compareKeys(int|string $left, int|string $right): int
    {
        $leftIndex = $this->arrayIndex($left);
        $rightIndex = $this->arrayIndex($right);
        if ($leftIndex !== null || $rightIndex !== null) {
            if ($leftIndex === null) {
                return 1;
            }
            if ($rightIndex === null) {
                return -1;
            }
            return $leftIndex <=> $rightIndex;
        }
        return strcmp(mb_convert_encoding((string) $left, 'UTF-16BE', 'UTF-8'), mb_convert_encoding((string) $right, 'UTF-16BE', 'UTF-8'));
    }

    private function arrayIndex(int|string $key): ?int
    {
        $text = (string) $key;
        if ($text === '0') {
            return 0;
        }
        if (preg_match('/^[1-9][0-9]*$/D', $text) !== 1 || strlen($text) > 10) {
            return null;
        }
        $number = (int) $text;
        return $number <= 4_294_967_294 && (string) $number === $text ? $number : null;
    }

    private function string(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('framework_canonical_unicode_invalid');
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('framework_canonical_string_invalid', previous: $exception);
        }
    }
}
