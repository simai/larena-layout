<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use JsonException;
use Larena\Layout\Exceptions\LayoutRejected;

final readonly class LayoutArtifactNormalizer
{
    public const SCHEMA = 'larena.layout.artifact.v1';
    public const MAX_BYTES = 262_144;
    public const MAX_DEPTH = 12;
    public const MAX_PLACEMENTS = 500;
    public const MAX_BINDINGS = 256;

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function normalize(array $input): array
    {
        $this->exactKeys($input, ['artifact_id', 'asset_refs', 'bindings', 'component', 'extensions', 'kind', 'parameters', 'placements', 'presentation', 'schema', 'scope_ref']);
        if (($input['schema'] ?? null) !== self::SCHEMA) {
            throw $this->reject('layout_artifact_schema_invalid');
        }
        $artifactId = $this->id($input['artifact_id'] ?? null, 'layout_artifact_id_invalid');
        $scopeRef = $input['scope_ref'] ?? null;
        if (!is_string($scopeRef) || preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/D', $scopeRef) !== 1) {
            throw $this->reject('layout_artifact_scope_invalid');
        }
        $kind = $input['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['page', 'section', 'block'], true)) {
            throw $this->reject('layout_artifact_kind_invalid');
        }
        $component = $this->component($input['component'] ?? null);
        $presentation = $this->presentation($input['presentation'] ?? null);
        $parameters = $this->object($input['parameters'] ?? null, 'layout_artifact_parameters_invalid');

        if (!is_array($input['placements'] ?? null) || !array_is_list($input['placements']) || count($input['placements']) > self::MAX_PLACEMENTS) {
            throw $this->reject('layout_artifact_placements_invalid');
        }
        $placementIds = [];
        $placements = [];
        foreach ($input['placements'] as $placement) {
            if (!is_array($placement) || array_is_list($placement)) {
                throw $this->reject('layout_artifact_placement_invalid');
            }
            $this->exactKeys($placement, ['artifact_ref', 'enabled', 'expected_revision', 'instance_id', 'parameters', 'slot', 'sort']);
            $instanceId = $this->id($placement['instance_id'] ?? null, 'layout_artifact_placement_id_invalid');
            if (isset($placementIds[$instanceId])) {
                throw $this->reject('layout_artifact_placement_id_duplicate');
            }
            $placementIds[$instanceId] = true;
            $expectedRevision = $placement['expected_revision'] ?? null;
            if ($expectedRevision !== null && (!is_int($expectedRevision) || $expectedRevision < 1)) {
                throw $this->reject('layout_artifact_placement_revision_invalid');
            }
            $sort = $placement['sort'] ?? null;
            if (!is_int($sort) || $sort < 0 || $sort > 1_000_000 || !is_bool($placement['enabled'] ?? null)) {
                throw $this->reject('layout_artifact_placement_state_invalid');
            }
            $placements[] = [
                'instance_id' => $instanceId,
                'artifact_ref' => $this->id($placement['artifact_ref'] ?? null, 'layout_artifact_placement_ref_invalid'),
                'expected_revision' => $expectedRevision,
                'slot' => $this->slot($placement['slot'] ?? null),
                'sort' => $sort,
                'enabled' => $placement['enabled'],
                'parameters' => $this->object($placement['parameters'] ?? null, 'layout_artifact_placement_parameters_invalid'),
            ];
        }
        usort($placements, static fn (array $left, array $right): int => [$left['sort'], $left['instance_id']] <=> [$right['sort'], $right['instance_id']]);

        if (!is_array($input['bindings'] ?? null) || !array_is_list($input['bindings']) || count($input['bindings']) > self::MAX_BINDINGS) {
            throw $this->reject('layout_artifact_bindings_invalid');
        }
        $bindingIds = [];
        $bindings = [];
        foreach ($input['bindings'] as $binding) {
            if (!is_array($binding) || array_is_list($binding)) {
                throw $this->reject('layout_artifact_binding_invalid');
            }
            $this->exactKeys($binding, ['binding_id', 'expected_revision', 'kind', 'selector', 'target']);
            $bindingId = $this->id($binding['binding_id'] ?? null, 'layout_artifact_binding_id_invalid');
            if (isset($bindingIds[$bindingId])) {
                throw $this->reject('layout_artifact_binding_id_duplicate');
            }
            $bindingIds[$bindingId] = true;
            $bindingKind = $binding['kind'] ?? null;
            if (!is_string($bindingKind) || !in_array($bindingKind, ['content_document', 'storage_record', 'logical_file', 'setting'], true)) {
                throw $this->reject('layout_artifact_binding_kind_invalid');
            }
            $target = $binding['target'] ?? null;
            if (!is_string($target) || $target === '' || strlen($target) > 320) {
                throw $this->reject('layout_artifact_binding_target_invalid');
            }
            $selector = $binding['selector'] ?? null;
            if (!is_string($selector) || preg_match('/^(?:\$|[a-z][a-z0-9_.-]{0,119})$/D', $selector) !== 1) {
                throw $this->reject('layout_artifact_binding_selector_invalid');
            }
            $revision = $binding['expected_revision'] ?? null;
            if ($revision !== null && (!is_int($revision) || $revision < 1)) {
                throw $this->reject('layout_artifact_binding_revision_invalid');
            }
            $this->assertSafeValue($target, 'binding.target', 0);
            $bindings[] = ['binding_id' => $bindingId, 'kind' => $bindingKind, 'target' => $target, 'selector' => $selector, 'expected_revision' => $revision];
        }
        usort($bindings, static fn (array $left, array $right): int => $left['binding_id'] <=> $right['binding_id']);

        if (!is_array($input['asset_refs'] ?? null) || !array_is_list($input['asset_refs']) || count($input['asset_refs']) > 256) {
            throw $this->reject('layout_artifact_assets_invalid');
        }
        $assets = [];
        foreach ($input['asset_refs'] as $asset) {
            if (!is_string($asset) || preg_match('#^[a-z][a-z0-9._/-]{1,199}$#D', $asset) !== 1 || in_array($asset, $assets, true)) {
                throw $this->reject('layout_artifact_asset_invalid');
            }
            $assets[] = $asset;
        }
        sort($assets, SORT_STRING);
        $extensions = $this->object($input['extensions'] ?? null, 'layout_artifact_extensions_invalid');
        foreach (array_keys($extensions) as $extension) {
            if (preg_match('/^[a-z][a-z0-9.-]*:[a-z][a-z0-9._-]*$/D', (string) $extension) !== 1) {
                throw $this->reject('layout_artifact_extension_key_invalid');
            }
        }

        $normalized = $this->canonicalize([
            'schema' => self::SCHEMA,
            'artifact_id' => $artifactId,
            'scope_ref' => $scopeRef,
            'kind' => $kind,
            'component' => $component,
            'presentation' => $presentation,
            'parameters' => $parameters,
            'placements' => $placements,
            'bindings' => $bindings,
            'asset_refs' => $assets,
            'extensions' => $extensions,
        ]);
        $this->assertSafeValue($normalized, 'artifact', 0);
        if (strlen($this->encodeNormalized($normalized)) > self::MAX_BYTES) {
            throw $this->reject('layout_artifact_size_limit_exceeded');
        }
        return $normalized;
    }

    /** @param array<string, mixed> $artifact */
    public function hash(array $artifact): string
    {
        return hash('sha256', $this->encode($artifact));
    }

    /** @param array<string, mixed> $artifact */
    public function encode(array $artifact): string
    {
        return $this->encodeNormalized($this->normalize($artifact));
    }

    /** @return array<string, mixed> */
    private function presentation(mixed $input): array
    {
        if (!is_array($input) || array_is_list($input)) {
            throw $this->reject('layout_artifact_presentation_invalid');
        }
        $this->exactKeys($input, ['modifiers', 'variant', 'view']);
        $variant = $input['variant'] ?? null;
        if ($variant !== null) {
            $variant = $this->variant($variant, 'layout_artifact_variant_invalid');
        }
        if (!is_array($input['modifiers'] ?? null) || !array_is_list($input['modifiers']) || count($input['modifiers']) > 32) {
            throw $this->reject('layout_artifact_modifiers_invalid');
        }
        $modifiers = [];
        foreach ($input['modifiers'] as $modifier) {
            $modifier = $this->variant($modifier, 'layout_artifact_modifier_invalid');
            if (in_array($modifier, $modifiers, true)) {
                throw $this->reject('layout_artifact_modifier_duplicate');
            }
            $modifiers[] = $modifier;
        }
        sort($modifiers, SORT_STRING);
        return ['view' => $this->variant($input['view'] ?? null, 'layout_artifact_view_invalid'), 'variant' => $variant, 'modifiers' => $modifiers];
    }

    private function id(mixed $value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/D', $value) !== 1) {
            throw $this->reject($reason);
        }
        return $value;
    }

    private function component(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]*(?:\.[a-z][a-z0-9-]*)+$/D', $value) !== 1) {
            throw $this->reject('layout_artifact_component_invalid');
        }
        return $value;
    }

    private function slot(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $value) !== 1) {
            throw $this->reject('layout_artifact_slot_invalid');
        }
        return $value;
    }

    private function variant(mixed $value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $value) !== 1) {
            throw $this->reject($reason);
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $reason): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 256) {
            throw $this->reject($reason);
        }
        $this->assertSafeValue($value, $reason, 0);
        return $this->canonicalize($value);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->reject('layout_artifact_unknown_key');
        }
    }

    private function assertSafeValue(mixed $value, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->reject('layout_artifact_depth_limit_exceeded');
        }
        if (is_array($value)) {
            if (count($value) > 500) {
                throw $this->reject('layout_artifact_collection_limit_exceeded');
            }
            foreach ($value as $key => $child) {
                if (!is_int($key)) {
                    $name = strtolower((string) $key);
                    if (preg_match('/(^|[_.-])(html|css|javascript|js|php|script|callback|callable|password|token|secret|credential|csrf|session|absolute_path|template_path)([_.-]|$)/', $name) === 1) {
                        throw $this->reject('layout_artifact_field_forbidden:' . $path . '.' . $name);
                    }
                }
                $this->assertSafeValue($child, $path . '.' . (string) $key, $depth + 1);
            }
            return;
        }
        if (!is_scalar($value) && $value !== null) {
            throw $this->reject('layout_artifact_value_invalid:' . $path);
        }
        if (is_float($value) && (!is_finite($value) || floor($value) !== $value)) {
            throw $this->reject('layout_artifact_number_invalid:' . $path);
        }
        if (is_string($value) && (strlen($value) > 16_384
            || preg_match('/<\?php|<\/?[a-z!][^>]*>|javascript\s*:|data\s*:\s*text\/html/i', $value) === 1
            || str_contains(str_replace('\\', '/', $value), '../')
            || preg_match('#^(?:file://|/Users/|/home/|/private/|[A-Za-z]:/)#', str_replace('\\', '/', $value)) === 1)) {
            throw $this->reject('layout_artifact_value_forbidden:' . $path);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }
        return $value;
    }

    /** @param array<string, mixed> $normalized */
    private function encodeNormalized(array $normalized): string
    {
        try {
            if ($normalized['parameters'] === []) {
                $normalized['parameters'] = (object) [];
            } else {
                foreach (['data', 'props'] as $plane) {
                    if (($normalized['parameters'][$plane] ?? null) === []) {
                        $normalized['parameters'][$plane] = (object) [];
                    }
                }
            }
            if ($normalized['extensions'] === []) {
                $normalized['extensions'] = (object) [];
            }
            foreach ($normalized['placements'] as $index => $placement) {
                if ($placement['parameters'] === []) {
                    $normalized['placements'][$index]['parameters'] = (object) [];
                }
            }
            return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw $this->reject('layout_artifact_json_invalid');
        }
    }

    private function reject(string $reason): LayoutRejected
    {
        return new LayoutRejected($reason);
    }
}
