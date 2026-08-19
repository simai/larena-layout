<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use JsonException;
use Larena\Layout\Exceptions\LayoutRejected;

final readonly class PageAssemblyDescriptorNormalizer
{
    public const SCHEMA = 'larena.layout.page_assembly.v1';
    public const MAX_BYTES = 262_144;
    public const MAX_DEPTH = 12;
    public const MAX_REGIONS = 12;
    public const MAX_SECTIONS = 30;
    public const MAX_BLOCKS = 100;
    public const MAX_BINDINGS = 100;

    public function __construct(private PageDescriptorComponentRegistry $components = new PageDescriptorComponentRegistry())
    {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $this->assertDepth($input, 0);
        $this->exactKeys($input, ['layout_id', 'page_id', 'regions', 'schema', 'scope_ref', 'site_id']);
        if (($input['schema'] ?? null) !== self::SCHEMA) {
            throw $this->reject('layout_page_assembly_schema_invalid');
        }

        $siteId = $this->stableId($input['site_id'] ?? null, 'layout_page_assembly_site_id_invalid');
        $pageId = $this->stableId($input['page_id'] ?? null, 'layout_page_assembly_page_id_invalid');
        $layoutId = $this->stableId($input['layout_id'] ?? null, 'layout_page_assembly_layout_id_invalid');
        $scopeRef = $input['scope_ref'] ?? null;
        if (!is_string($scopeRef) || preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/', $scopeRef) !== 1) {
            throw $this->reject('layout_page_assembly_scope_invalid');
        }
        if (!is_array($input['regions'] ?? null) || !array_is_list($input['regions']) || count($input['regions']) > self::MAX_REGIONS) {
            throw $this->reject('layout_page_assembly_regions_invalid');
        }

        $ids = [];
        $sectionCount = 0;
        $blockCount = 0;
        $bindingCount = 0;
        $regions = [];
        foreach ($input['regions'] as $region) {
            if (!is_array($region) || array_is_list($region)) {
                throw $this->reject('layout_page_assembly_region_invalid');
            }
            $this->exactKeys($region, ['id', 'sections', 'sort']);
            $regionId = $this->uniqueId($region['id'] ?? null, $ids, 'layout_page_assembly_region_id_invalid');
            if (!is_array($region['sections'] ?? null) || !array_is_list($region['sections'])) {
                throw $this->reject('layout_page_assembly_sections_invalid');
            }

            $sections = [];
            foreach ($region['sections'] as $section) {
                $sectionCount++;
                if ($sectionCount > self::MAX_SECTIONS || !is_array($section) || array_is_list($section)) {
                    throw $this->reject('layout_page_assembly_section_invalid');
                }
                $this->exactKeys($section, ['blocks', 'component', 'id', 'modifiers', 'preset', 'props', 'sort', 'view']);
                $sectionId = $this->uniqueId($section['id'] ?? null, $ids, 'layout_page_assembly_section_id_invalid');
                $sectionComponent = $this->componentKey($section['component'] ?? null, 'layout_page_assembly_section_component_invalid');
                $this->components->assertSection($sectionComponent);
                $sectionInstance = $this->instance($section, $sectionId, $sectionComponent);
                if (!is_array($section['blocks'] ?? null) || !array_is_list($section['blocks'])) {
                    throw $this->reject('layout_page_assembly_blocks_invalid');
                }

                $blocks = [];
                foreach ($section['blocks'] as $block) {
                    $blockCount++;
                    if ($blockCount > self::MAX_BLOCKS || !is_array($block) || array_is_list($block)) {
                        throw $this->reject('layout_page_assembly_block_invalid');
                    }
                    $this->exactKeys($block, ['bindings', 'component', 'id', 'modifiers', 'preset', 'props', 'sort', 'view']);
                    $blockId = $this->uniqueId($block['id'] ?? null, $ids, 'layout_page_assembly_block_id_invalid');
                    $blockComponent = $this->componentKey($block['component'] ?? null, 'layout_page_assembly_block_component_invalid');
                    $this->components->assertBlock($blockComponent);
                    $blockInstance = $this->instance($block, $blockId, $blockComponent);
                    if (!is_array($block['bindings'] ?? null) || !array_is_list($block['bindings'])) {
                        throw $this->reject('layout_page_assembly_bindings_invalid');
                    }
                    $bindings = [];
                    foreach ($block['bindings'] as $binding) {
                        $bindingCount++;
                        if ($bindingCount > self::MAX_BINDINGS || !is_array($binding) || array_is_list($binding)) {
                            throw $this->reject('layout_page_assembly_binding_invalid');
                        }
                        $bindings[] = $this->binding($binding, $ids);
                    }
                    $blockInstance['bindings'] = $bindings;
                    $blocks[] = $blockInstance;
                }
                $this->sortNodes($blocks);
                $sectionInstance['blocks'] = $blocks;
                $sections[] = $sectionInstance;
            }
            $this->sortNodes($sections);
            $regions[] = [
                'id' => $regionId,
                'sort' => $this->sortValue($region['sort'] ?? null),
                'sections' => $sections,
            ];
        }
        $this->sortNodes($regions);

        $normalized = $this->canonicalize([
            'schema' => self::SCHEMA,
            'site_id' => $siteId,
            'page_id' => $pageId,
            'scope_ref' => $scopeRef,
            'layout_id' => $layoutId,
            'regions' => $regions,
        ]);
        if (strlen($this->encodeNormalized($normalized)) > self::MAX_BYTES) {
            throw $this->reject('layout_page_assembly_size_limit_exceeded');
        }

        return $normalized;
    }

    /** @param array<string, mixed> $descriptor */
    public function hash(array $descriptor): string
    {
        return hash('sha256', $this->encodeNormalized($this->normalize($descriptor)));
    }

    /** @param array<string, mixed> $descriptor */
    public function encode(array $descriptor): string
    {
        return $this->encodeNormalized($this->normalize($descriptor));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function instance(array $input, string $id, string $component): array
    {
        $view = $this->variantKey($input['view'] ?? null, 'layout_page_assembly_view_invalid');
        $preset = $input['preset'] ?? null;
        if ($preset !== null) {
            $preset = $this->variantKey($preset, 'layout_page_assembly_preset_invalid');
        }
        if (!is_array($input['modifiers'] ?? null) || !array_is_list($input['modifiers']) || count($input['modifiers']) > 20) {
            throw $this->reject('layout_page_assembly_modifiers_invalid');
        }
        $modifiers = [];
        foreach ($input['modifiers'] as $modifier) {
            $key = $this->variantKey($modifier, 'layout_page_assembly_modifier_invalid');
            if (in_array($key, $modifiers, true)) {
                throw $this->reject('layout_page_assembly_modifier_duplicate');
            }
            $modifiers[] = $key;
        }
        sort($modifiers, SORT_STRING);
        if (!is_array($input['props'] ?? null) || ($input['props'] !== [] && array_is_list($input['props']))) {
            throw $this->reject('layout_page_assembly_props_invalid');
        }
        $this->assertSafeValue($input['props'], 'props', 0);

        return [
            'id' => $id,
            'component' => $component,
            'view' => $view,
            'preset' => $preset,
            'modifiers' => $modifiers,
            'props' => $this->canonicalize($input['props']),
            'sort' => $this->sortValue($input['sort'] ?? null),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, true> $ids @return array<string, mixed> */
    private function binding(array $input, array &$ids): array
    {
        $this->exactKeys($input, ['expected_revision', 'id', 'kind', 'selector', 'target']);
        $id = $this->uniqueId($input['id'] ?? null, $ids, 'layout_page_assembly_binding_id_invalid');
        $kind = $input['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['content_document', 'storage_record', 'logical_file', 'tree_node'], true)) {
            throw $this->reject('layout_page_assembly_binding_kind_unknown');
        }
        $target = $input['target'] ?? null;
        if (!is_string($target) || !$this->validTarget($kind, $target)) {
            throw $this->reject('layout_page_assembly_binding_target_invalid');
        }
        $selector = $input['selector'] ?? null;
        if (!is_string($selector) || preg_match('/^(?:\$|[a-z][a-z0-9_.-]{0,119})$/', $selector) !== 1) {
            throw $this->reject('layout_page_assembly_binding_selector_invalid');
        }
        $revision = $input['expected_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw $this->reject('layout_page_assembly_binding_revision_invalid');
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'target' => $target,
            'selector' => $selector,
            'expected_revision' => $revision,
        ];
    }

    private function validTarget(string $kind, string $target): bool
    {
        return match ($kind) {
            'logical_file', 'tree_node' => preg_match('/^[a-f0-9]{8}-[a-f0-9-]{27}$/i', $target) === 1,
            'content_document' => preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $target) === 1,
            'storage_record' => preg_match('/^[a-z][a-z0-9_.:-]{1,119}\|[a-z][a-z0-9_.:-]{1,159}$/', $target) === 1,
            default => false,
        };
    }

    private function stableId(mixed $value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $value) !== 1) {
            throw $this->reject($reason);
        }

        return $value;
    }

    private function componentKey(mixed $value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $value) !== 1) {
            throw $this->reject($reason);
        }

        return $value;
    }

    private function variantKey(mixed $value, string $reason): string
    {
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_.-]{0,79}$/', $value) !== 1) {
            throw $this->reject($reason);
        }

        return $value;
    }

    /** @param array<string, true> $ids */
    private function uniqueId(mixed $value, array &$ids, string $reason): string
    {
        $id = $this->stableId($value, $reason);
        if (isset($ids[$id])) {
            throw $this->reject('layout_page_assembly_id_duplicate');
        }
        $ids[$id] = true;

        return $id;
    }

    private function sortValue(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 1_000_000) {
            throw $this->reject('layout_page_assembly_sort_invalid');
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $nodes */
    private function sortNodes(array &$nodes): void
    {
        usort($nodes, static fn (array $left, array $right): int => [$left['sort'], $left['id']] <=> [$right['sort'], $right['id']]);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->reject('layout_page_assembly_unknown_key');
        }
    }

    private function assertDepth(mixed $value, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->reject('layout_page_assembly_depth_limit_exceeded');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertDepth($child, $depth + 1);
            }
        }
    }

    private function assertSafeValue(mixed $value, string $path, int $depth): void
    {
        if ($depth > 8) {
            throw $this->reject('layout_page_assembly_prop_depth_exceeded');
        }
        if (is_array($value)) {
            if (count($value) > 100) {
                throw $this->reject('layout_page_assembly_prop_collection_too_large');
            }
            foreach ($value as $key => $child) {
                if (!is_int($key)) {
                    $name = strtolower((string) $key);
                    if (preg_match('/^[a-z][a-z0-9_.-]{0,119}$/', $name) !== 1
                        || preg_match('/(^|[_.-])(html|css|javascript|js|php|script|callback|callable|class|method|password|token|secret|credential|absolute_path|template_path)([_.-]|$)/', $name) === 1) {
                        throw $this->reject('layout_page_assembly_prop_key_unsafe:' . $path . '.' . $name);
                    }
                }
                $this->assertSafeValue($child, $path . '.' . (string) $key, $depth + 1);
            }
            return;
        }
        if (!is_scalar($value) && $value !== null) {
            throw $this->reject('layout_page_assembly_prop_type_invalid:' . $path);
        }
        if (is_string($value)) {
            if (strlen($value) > 16_384
                || preg_match('/<\?php|<\/?[a-z!][^>]*>|javascript\s*:|data\s*:\s*text\/html/i', $value) === 1
                || str_contains(str_replace('\\', '/', $value), '../')
                || preg_match('#^(?:file://|/Users/|/home/|/private/|[A-Za-z]:/)#', str_replace('\\', '/', $value)) === 1) {
                throw $this->reject('layout_page_assembly_prop_value_unsafe:' . $path);
            }
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
            return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw $this->reject('layout_page_assembly_json_invalid');
        }
    }

    private function reject(string $reason): LayoutRejected
    {
        return new LayoutRejected($reason);
    }
}
