<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

use stdClass;

/**
 * Validates a Composition Document (simai.composition.document, schema version 1) as the SIMAI Framework's
 * `SF.Composition.validate` does: the Document's own fields, every node against its type's manifest, the declared
 * region rules and the content rules (links, item parents, one outlet). Diagnostics are {code, path, message}; the
 * kit compares code and path.
 *
 * Not read here: the routing of a ui-layout `layout.scope` (ports and routes between endpoints). Structured content
 * has no scope; a ui-layout Document that declares routes is checked by the Framework, not by this validator.
 */
final class Validator
{
    private const PROFILES = ['ui-layout', 'structured-content'];

    private const DOCUMENT_FIELDS = ['schema', 'schema_version', 'id', 'profile', 'locale', 'root', 'extensions'];

    private const NODE_FIELDS = ['id', 'type', 'data', 'props', 'slots', 'presentation', 'bindings', 'extensions'];

    private const PRESENTATION_FIELDS = ['view', 'preset', 'modifiers'];

    private const BINDING_FIELDS = ['owner', 'ref', 'target', 'revision'];

    private const FORBIDDEN_KEYS = [
        'html', 'innerhtml', 'script', 'javascript', 'php', 'eval', 'function',
        'expression', 'query', 'sql', 'graphql', 'class', 'classname', 'rootclass',
        'cssclass', 'secret', 'password', 'token', 'cookie', 'authorization', 'request',
    ];

    private const SAFE_SCHEMES = ['http:', 'https:', 'mailto:', 'tel:'];

    // The one extension the Framework itself reads (routing.mjs ENDPOINT_EXTENSION).
    private const SUPPORTED_EXTENSIONS = ['simai.composition:endpoint'];

    private const ID = '/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,119}$/uD';

    private const MAX_DOCUMENT_BYTES = 1024 * 1024;

    private const MAX_DEPTH = 32;

    private const MAX_NODES = 2000;

    private const MAX_CHILDREN_PER_SLOT = 500;

    /** @var list<array{code: string, path: string, message: string}> */
    private array $diagnostics = [];

    /** @var array<string, true> */
    private array $ids = [];

    private int $nodeCount = 0;

    private function __construct(private readonly TypeRegistry $registry)
    {
    }

    /** @return list<array{code: string, path: string, message: string}> */
    public static function validate(mixed $document, ?TypeRegistry $registry = null): array
    {
        $validator = new self($registry ?? TypeRegistry::builtIn());
        $validator->run($document);

        return $validator->diagnostics;
    }

    /** Whether a URL is one content may hold: relative, a fragment, or http:, https:, mailto: or tel:. */
    public static function validUrl(mixed $value): bool
    {
        $value = is_string($value) ? $value : Js::string($value);
        // Browsers read "/\host" like "//host": backslashes never form a local link.
        if (preg_match('#^(?:/|\.?\./|\#)#u', $value) === 1) {
            return ! str_starts_with($value, '//') && ! str_contains($value, '\\');
        }

        return in_array(Url::protocol($value), self::SAFE_SCHEMES, true);
    }

    private function push(string $code, string $path, string $message): void
    {
        $this->diagnostics[] = ['code' => $code, 'path' => $path, 'message' => $message];
    }

    private function run(mixed $document): void
    {
        $serialized = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($serialized === false) {
            $this->push('document_not_json', '$', 'Document must be JSON serializable');

            return;
        }
        if (strlen($serialized) > self::MAX_DOCUMENT_BYTES) {
            $this->push('document_too_large', '$', 'Document exceeds the byte limit');
        }
        if (! Js::isObject($document)) {
            $this->diagnostics = [['code' => 'document_invalid', 'path' => '$', 'message' => 'Document must be an object']];

            return;
        }
        $this->unknownFields($document, self::DOCUMENT_FIELDS, '$');
        if (! self::isDocumentSchema($document)) {
            $this->push('schema_unknown', '$.schema', 'Expected simai.composition.document with schema_version 1');
        }
        $id = Js::get($document, 'id');
        if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
            $this->push('document_id_invalid', '$.id', 'Document id is invalid');
        }
        if (! in_array(Js::get($document, 'profile'), self::PROFILES, true)) {
            $this->push('profile_unknown', '$.profile', 'Profile is not supported');
        }
        if (Js::has($document, 'locale')) {
            $locale = Js::get($document, 'locale');
            if (! is_string($locale) || preg_match('/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/uD', $locale) !== 1) {
                $this->push('locale_invalid', '$.locale', 'Locale is invalid');
            }
        }
        if (Js::has($document, 'extensions')) {
            $this->extensions(Js::get($document, 'extensions'), '$.extensions');
        }
        $this->forbiddenKeys($document, '$');

        $root = Js::get($document, 'root');
        $this->visit($root, '$.root', 1, Js::get($document, 'profile'));
        if ($this->nodeCount > self::MAX_NODES) {
            $this->push('node_limit', '$.root', 'Composition has too many nodes');

            return;
        }
        RegionRules::validate($root, $this->registry, $this->diagnostics);
        $this->contentRules($root);
    }

    /** The format a Document declares, in either of its forms: the name with schema_version, or the older name.v1. */
    private static function isDocumentSchema(stdClass $document): bool
    {
        $label = Js::get($document, 'schema') ?? Js::get($document, 'schema_id');
        if (! is_string($label) || $label === '') {
            return false;
        }
        $version = Js::get($document, 'schema_version');
        if (preg_match('/^(.+)\.v([0-9]+)$/uD', $label, $match) === 1) {
            $name = $match[1];
            $declared = (int) $match[2];
        } else {
            $name = $label;
            $declared = Js::isInteger($version) ? (int) $version : null;
        }
        if ($name !== 'simai.composition.document' || $declared !== 1) {
            return false;
        }

        return ! Js::has($document, 'schema_version') || Js::same($version, 1);
    }

    /** @param list<string> $allowed */
    private function unknownFields(mixed $value, array $allowed, string $path): void
    {
        foreach (array_keys(Js::entries($value)) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->push('unknown_field', $path.'.'.$key, 'Unknown field '.$key);
            }
        }
    }

    private function forbiddenKeys(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $index => $entry) {
                $this->forbiddenKeys($entry, $path.'['.$index.']');
            }

            return;
        }
        foreach (Js::entries($value) as $key => $entry) {
            $key = (string) $key;
            if (in_array(str_replace('-', '', mb_strtolower($key, 'UTF-8')), self::FORBIDDEN_KEYS, true)) {
                $this->push('executable_or_secret_field_forbidden', $path.'.'.$key, 'Field '.$key.' is not portable composition data');
            }
            $this->forbiddenKeys($entry, $path.'.'.$key);
        }
    }

    private function extensions(mixed $value, string $path): void
    {
        if (! Js::isObject($value)) {
            $this->push('extensions_invalid', $path, 'Extensions must be an object');

            return;
        }
        foreach (Js::entries($value) as $key => $entry) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9.-]*:[a-z][a-z0-9._-]*$/uD', $key) !== 1) {
                $this->push('extension_name_invalid', $path.'.'.$key, 'Extension keys must be namespaced');
            }
            if (Js::isObject($entry) && Js::get($entry, 'required') === true && ! in_array($key, self::SUPPORTED_EXTENSIONS, true)) {
                $this->push('extension_required_unknown', $path.'.'.$key, 'Required extension '.$key.' is not supported');
            }
        }
    }

    private function visit(mixed $node, string $path, int $depth, mixed $profile): void
    {
        $this->nodeCount++;
        if ($this->nodeCount > self::MAX_NODES) {
            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $this->push('depth_limit', $path, 'Composition is too deeply nested');
        }
        if (! Js::isObject($node)) {
            $this->push('node_invalid', $path, 'Node must be an object');

            return;
        }
        $this->unknownFields($node, self::NODE_FIELDS, $path);
        $id = Js::get($node, 'id');
        if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
            $this->push('node_id_invalid', $path.'.id', 'Node id is invalid');
        } elseif (isset($this->ids[$id])) {
            $this->push('node_id_duplicate', $path.'.id', 'Duplicate node id '.$id);
        } else {
            $this->ids[$id] = true;
        }
        $type = Js::get($node, 'type');
        $manifest = $this->registry->manifest($type);
        if ($manifest === null) {
            $this->push('type_unknown', $path.'.type', 'Unknown type '.(is_string($type) ? $type : ''));

            return;
        }
        if (! in_array($profile, $manifest['profiles'], true)) {
            $this->push('type_profile_unsupported', $path.'.type', $type.' does not support '.Js::string($profile));
        }
        $data = Js::get($node, 'data');
        $props = Js::get($node, 'props');
        JsonSchema::validate(self::truthy($data) ? $data : new stdClass(), $manifest['data_schema'] ?? null, $path.'.data', $this->diagnostics);
        JsonSchema::validate(self::truthy($props) ? $props : new stdClass(), $manifest['props_schema'] ?? null, $path.'.props', $this->diagnostics);
        if (Js::has($node, 'presentation')) {
            $this->presentation(Js::get($node, 'presentation'), $manifest, $path.'.presentation');
        }
        if (Js::has($node, 'extensions')) {
            $this->extensions(Js::get($node, 'extensions'), $path.'.extensions');
        }
        if (Js::has($node, 'bindings')) {
            $this->bindings(Js::get($node, 'bindings'), $path.'.bindings');
        }
        $slots = Js::get($node, 'slots');
        $slots = self::truthy($slots) ? $slots : new stdClass();
        if (! Js::isObject($slots)) {
            $this->push('slots_invalid', $path.'.slots', 'Slots must be an object');

            return;
        }
        $entries = Js::entries($slots);
        if (($manifest['mode'] ?? null) === 'leaf' && $entries !== []) {
            $this->push('leaf_slots_forbidden', $path.'.slots', 'Leaf type cannot contain slots');
        }
        $declared = is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($entries as $slotName => $children) {
            $slotName = (string) $slotName;
            $slotPath = $path.'.slots.'.$slotName;
            $slot = $declared[$slotName] ?? null;
            if (! is_array($slot)) {
                $this->push('slot_unknown', $slotPath, 'Slot '.$slotName.' is not registered');
                continue;
            }
            if (! is_array($children)) {
                $this->push('slot_children_invalid', $slotPath, 'Slot children must be an array');
                continue;
            }
            $count = count($children);
            if ($count > self::MAX_CHILDREN_PER_SLOT || (isset($slot['max']) && $count > $slot['max']) || (isset($slot['min']) && $count < $slot['min'])) {
                $this->push('slot_cardinality', $slotPath, 'Slot child count is outside the allowed range');
            }
            foreach ($children as $index => $child) {
                $childPath = $slotPath.'['.$index.']';
                $childType = Js::get($child, 'type');
                $childManifest = $this->registry->manifest($childType);
                if ($childManifest !== null && isset($slot['types']) && ! in_array($childType, $slot['types'], true)) {
                    $this->push('slot_child_type_forbidden', $childPath, $childType.' is not allowed in this slot');
                }
                if ($childManifest !== null && isset($slot['categories']) && ! in_array($childManifest['category'], $slot['categories'], true)) {
                    $this->push('slot_child_category_forbidden', $childPath, $childManifest['category'].' is not allowed in this slot');
                }
                $this->visit($child, $childPath, $depth + 1, $profile);
            }
        }
        foreach ($declared as $slotName => $slot) {
            if (($slot['min'] ?? 0) > 0 && ! array_key_exists((string) $slotName, $entries)) {
                $this->push('slot_required', $path.'.slots.'.$slotName, 'Slot '.$slotName.' is required');
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function presentation(mixed $presentation, array $manifest, string $path): void
    {
        if (! Js::isObject($presentation)) {
            $this->push('presentation_invalid', $path, 'Presentation must be an object');

            return;
        }
        $this->unknownFields($presentation, self::PRESENTATION_FIELDS, $path);
        $contract = is_array($manifest['presentation'] ?? null) ? $manifest['presentation'] : [];
        $view = Js::get($presentation, 'view');
        if (self::truthy($view) && ! in_array($view, $contract['views'] ?? [], true)) {
            $this->push('view_unknown', $path.'.view', 'View is not registered');
        }
        $preset = Js::get($presentation, 'preset');
        if (self::truthy($preset) && ! in_array($preset, $contract['presets'] ?? [], true)) {
            $this->push('preset_unknown', $path.'.preset', 'Preset is not registered');
        }
        if (Js::has($presentation, 'modifiers')) {
            $modifiers = Js::get($presentation, 'modifiers');
            if (! is_array($modifiers)) {
                $this->push('modifiers_invalid', $path.'.modifiers', 'Modifiers must be an array');
            } else {
                foreach ($modifiers as $modifier) {
                    if (! in_array($modifier, $contract['modifiers'] ?? [], true)) {
                        $this->push('modifier_unknown', $path.'.modifiers', 'Modifier '.Js::string($modifier).' is not registered');
                    }
                }
            }
        }
    }

    private function bindings(mixed $bindings, string $path): void
    {
        if (! is_array($bindings)) {
            $this->push('bindings_invalid', $path, 'Bindings must be an array');

            return;
        }
        foreach ($bindings as $index => $binding) {
            $bindingPath = $path.'['.$index.']';
            if (! Js::isObject($binding)) {
                $this->push('binding_invalid', $bindingPath, 'Binding must be an object');
                continue;
            }
            $this->unknownFields($binding, self::BINDING_FIELDS, $bindingPath);
            foreach (['owner', 'ref', 'target'] as $key) {
                $value = Js::get($binding, $key);
                if (! is_string($value) || $value === '') {
                    $this->push('binding_field_invalid', $bindingPath.'.'.$key, $key.' is required');
                }
            }
            if (Js::has($binding, 'revision')) {
                $revision = Js::get($binding, 'revision');
                if (! is_string($revision) || $revision === '') {
                    $this->push('binding_revision_invalid', $bindingPath.'.revision', 'Revision must be a non-empty string');
                }
            }
        }
    }

    /**
     * Content rules beyond a type's own schema: links and image sources follow the Document's URL rule, an item type
     * stands only in its own parent, and a layout holds at most one outlet.
     */
    private function contentRules(mixed $root): void
    {
        $outlets = 0;
        $visit = function (mixed $node, string $path, mixed $parentType) use (&$visit, &$outlets): void {
            if (! Js::isObject($node)) {
                return;
            }
            foreach (self::inlineFields($node, $path) as [$content, $contentPath]) {
                foreach ($content as $index => $inline) {
                    if (Js::get($inline, 'type') === 'link' && ! self::validUrl(Js::get($inline, 'href'))) {
                        $this->push('unsafe_url', $contentPath.'['.$index.'].href', 'Link protocol is not allowed');
                    }
                }
            }
            $type = Js::get($node, 'type');
            $data = Js::get($node, 'data');
            if (($type === 'content.figure' || $type === 'content.media') && is_string(Js::get($data, 'src')) && ! self::validUrl(Js::get($data, 'src'))) {
                $this->push('unsafe_url', $path.'.data.src', 'Image source protocol is not allowed');
            }
            if ($type === 'content.file' && is_string(Js::get($data, 'href')) && ! self::validUrl(Js::get($data, 'href'))) {
                $this->push('unsafe_url', $path.'.data.href', 'File address protocol is not allowed');
            }
            $parent = $this->registry->itemParent($type);
            if ($parent !== null && $parentType !== $parent) {
                $this->push('content_parent_invalid', $path, $type.' must be a direct child of '.$parent);
            }
            if ($type === 'content.outlet') {
                $outlets++;
                if ($outlets > 1) {
                    $this->push('outlet_duplicate', $path, 'A layout holds the page content in one place');
                }
            }
            foreach (Js::entries(Js::get($node, 'slots')) as $slotName => $children) {
                if (is_array($children)) {
                    foreach ($children as $index => $child) {
                        $visit($child, $path.'.slots.'.$slotName.'['.$index.']', $type);
                    }
                }
            }
        };
        $visit($root, '$.root', null);
    }

    /**
     * Every inline content of a node, with its path: the link rule applies to all of them.
     *
     * @return list<array{0: list<mixed>, 1: string}>
     */
    private static function inlineFields(stdClass $node, string $path): array
    {
        $data = Js::get($node, 'data');
        $at = static function (string $key) use ($data, $path): array {
            $value = Js::get($data, $key);

            return is_array($value) ? [[$value, $path.'.data.'.$key]] : [];
        };

        switch (Js::get($node, 'type')) {
            case 'content.heading':
            case 'content.paragraph':
            case 'content.list-item':
                return $at('content');
            case 'content.alert':
            case 'content.step':
                return $at('title');
            case 'content.details':
                return $at('summary');
            case 'content.figure':
                return $at('caption');
            case 'content.table':
                $fields = $at('caption');
                $rows = Js::get($data, 'rows');
                foreach (is_array($rows) ? $rows : [] as $rowIndex => $row) {
                    foreach (is_array($row) ? $row : [] as $cellIndex => $cell) {
                        $fields[] = [$cell, $path.'.data.rows['.$rowIndex.']['.$cellIndex.']'];
                    }
                }

                // A cell that is not a list holds no inline content to check.
                return array_values(array_filter($fields, static fn (array $field): bool => is_array($field[0])));
            default:
                return [];
        }
    }

    /** JavaScript truthiness of a JSON value. */
    public static function truthy(mixed $value): bool
    {
        return ! ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }
}
