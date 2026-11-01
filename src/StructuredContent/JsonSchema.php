<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/**
 * The JSON Schema subset type manifests are written in (oneOf, const, enum, type, string and number bounds, items,
 * required, additionalProperties: false, properties), read as the SIMAI Framework's validator reads it, with its
 * diagnostic codes. A schema is the manifest's decoded array; a value is a JSON value decoded as objects.
 */
final class JsonSchema
{
    /**
     * @param array<string, mixed>|null $schema
     * @param list<array{code: string, path: string, message: string}> $diagnostics
     */
    public static function validate(mixed $value, ?array $schema, string $path, array &$diagnostics): void
    {
        if ($schema === null) {
            return;
        }
        $push = static function (string $code, string $at, string $message) use (&$diagnostics): void {
            $diagnostics[] = ['code' => $code, 'path' => $at, 'message' => $message];
        };
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $candidate) {
                $local = [];
                self::validate($value, $candidate, $path, $local);
                if ($local === []) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                $push('schema_one_of', $path, 'Value must match exactly one allowed shape');
            }

            return;
        }
        if (array_key_exists('const', $schema) && ! Js::same($value, $schema['const'])) {
            $push('schema_const', $path, 'Value must equal '.Js::string($schema['const']));

            return;
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $found = false;
            foreach ($schema['enum'] as $allowed) {
                if (Js::same($value, $allowed)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $push('schema_enum', $path, 'Value is not in the allowed list');

                return;
            }
        }
        if (isset($schema['type'])) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $actual = Js::typeName($value);
            if (! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
                $push('schema_type', $path, 'Expected '.implode(' or ', $types));

                return;
            }
        }
        if (is_string($value)) {
            $length = Js::length($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $push('schema_min_length', $path, 'String is too short');
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $push('schema_max_length', $path, 'String is too long');
            }
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~uD', $value) !== 1) {
                $push('schema_pattern', $path, 'String has an invalid format');
            }
        }
        if (Js::isNumber($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $push('schema_minimum', $path, 'Number is too small');
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $push('schema_maximum', $path, 'Number is too large');
            }
        }
        if (is_array($value)) {
            $count = count($value);
            if (isset($schema['minItems']) && $count < $schema['minItems']) {
                $push('schema_min_items', $path, 'Array has too few items');
            }
            if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
                $push('schema_max_items', $path, 'Array has too many items');
            }
            if (($schema['uniqueItems'] ?? false) === true && count(array_unique(array_map(Js::stableStringify(...), $value))) !== $count) {
                $push('schema_unique_items', $path, 'Array items must be unique');
            }
            if (isset($schema['items']) && is_array($schema['items'])) {
                foreach ($value as $index => $entry) {
                    self::validate($entry, $schema['items'], $path.'['.$index.']', $diagnostics);
                }
            }
        }
        if (Js::isObject($value)) {
            $entries = Js::entries($value);
            foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $key) {
                if (! array_key_exists($key, $entries)) {
                    $push('schema_required', $path.'.'.$key, 'Required field '.$key.' is missing');
                }
            }
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            if (($schema['additionalProperties'] ?? null) === false) {
                foreach (array_keys($entries) as $key) {
                    if (! array_key_exists((string) $key, $properties)) {
                        $push('schema_additional_property', $path.'.'.$key, 'Field '.$key.' is not allowed');
                    }
                }
            }
            foreach ($properties as $key => $childSchema) {
                if (array_key_exists((string) $key, $entries)) {
                    self::validate($entries[$key], is_array($childSchema) ? $childSchema : null, $path.'.'.$key, $diagnostics);
                }
            }
        }
    }
}
