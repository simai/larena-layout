<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use JsonException;
use Larena\Layout\Exceptions\LayoutRejected;

final readonly class PageDescriptorNormalizer
{
    public const SCHEMA = 'larena.layout.page_descriptor';
    public const SCHEMA_VERSION = 1;
    public const MAX_BYTES = 262_144;
    public const MAX_DEPTH = 10;
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
        $this->exactKeys($input, ['layout_id', 'page_id', 'regions', 'schema', 'schema_version', 'scope_ref']);
        if (($input['schema'] ?? null) !== self::SCHEMA || ($input['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw $this->reject('layout_descriptor_schema_invalid');
        }
        $pageId = $this->stableId($input['page_id'] ?? null, 'layout_descriptor_page_id_invalid');
        $layoutId = $this->stableId($input['layout_id'] ?? null, 'layout_descriptor_layout_id_invalid');
        $scopeRef = $input['scope_ref'] ?? null;
        if (!is_string($scopeRef) || preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/', $scopeRef) !== 1) {
            throw $this->reject('layout_descriptor_scope_invalid');
        }
        if (!is_array($input['regions'] ?? null) || !array_is_list($input['regions']) || count($input['regions']) > self::MAX_REGIONS) {
            throw $this->reject('layout_descriptor_regions_invalid');
        }

        $ids = [];
        $sectionCount = 0;
        $blockCount = 0;
        $bindingCount = 0;
        $regions = [];
        foreach ($input['regions'] as $region) {
            if (!is_array($region) || array_is_list($region)) {
                throw $this->reject('layout_descriptor_region_invalid');
            }
            $this->exactKeys($region, ['id', 'sections', 'sort']);
            $regionId = $this->uniqueId($region['id'] ?? null, $ids, 'layout_descriptor_region_id_invalid');
            $sort = $this->sortValue($region['sort'] ?? null);
            if (!is_array($region['sections'] ?? null) || !array_is_list($region['sections'])) {
                throw $this->reject('layout_descriptor_sections_invalid');
            }
            $sections = [];
            foreach ($region['sections'] as $section) {
                $sectionCount++;
                if ($sectionCount > self::MAX_SECTIONS || !is_array($section) || array_is_list($section)) {
                    throw $this->reject('layout_descriptor_section_invalid');
                }
                $this->exactKeys($section, ['blocks', 'component', 'id', 'sort']);
                $sectionId = $this->uniqueId($section['id'] ?? null, $ids, 'layout_descriptor_section_id_invalid');
                $component = $this->stableId($section['component'] ?? null, 'layout_descriptor_section_component_invalid');
                $this->components->assertSection($component);
                if (!is_array($section['blocks'] ?? null) || !array_is_list($section['blocks'])) {
                    throw $this->reject('layout_descriptor_blocks_invalid');
                }
                $blocks = [];
                foreach ($section['blocks'] as $block) {
                    $blockCount++;
                    if ($blockCount > self::MAX_BLOCKS || !is_array($block) || array_is_list($block)) {
                        throw $this->reject('layout_descriptor_block_invalid');
                    }
                    $this->exactKeys($block, ['bindings', 'component', 'id', 'sort']);
                    $blockId = $this->uniqueId($block['id'] ?? null, $ids, 'layout_descriptor_block_id_invalid');
                    $blockComponent = $this->stableId($block['component'] ?? null, 'layout_descriptor_block_component_invalid');
                    $this->components->assertBlock($blockComponent);
                    if (!is_array($block['bindings'] ?? null) || !array_is_list($block['bindings'])) {
                        throw $this->reject('layout_descriptor_bindings_invalid');
                    }
                    $bindings = [];
                    foreach ($block['bindings'] as $binding) {
                        $bindingCount++;
                        if ($bindingCount > self::MAX_BINDINGS || !is_array($binding) || array_is_list($binding)) {
                            throw $this->reject('layout_descriptor_binding_invalid');
                        }
                        $this->exactKeys($binding, ['expected_revision', 'id', 'kind', 'selector', 'target']);
                        $bindingId = $this->uniqueId($binding['id'] ?? null, $ids, 'layout_descriptor_binding_id_invalid');
                        $kind = $binding['kind'] ?? null;
                        if (!is_string($kind) || !in_array($kind, ['content_document', 'storage_record', 'logical_file', 'tree_node'], true)) {
                            throw $this->reject('layout_descriptor_binding_kind_unknown');
                        }
                        $target = $binding['target'] ?? null;
                        if (!is_string($target) || !$this->validTarget($kind, $target)) {
                            throw $this->reject('layout_descriptor_binding_target_invalid');
                        }
                        $selector = $binding['selector'] ?? null;
                        if (!is_string($selector) || preg_match('/^(?:\$|[a-z][a-z0-9_.-]{0,119})$/', $selector) !== 1) {
                            throw $this->reject('layout_descriptor_binding_selector_invalid');
                        }
                        $expected = $binding['expected_revision'] ?? null;
                        if (!is_int($expected) || $expected < 1) {
                            throw $this->reject('layout_descriptor_binding_revision_invalid');
                        }
                        $bindings[] = compact('bindingId', 'kind', 'target', 'selector', 'expected');
                    }
                    $bindings = array_map(static fn (array $binding): array => [
                        'id' => $binding['bindingId'],
                        'kind' => $binding['kind'],
                        'target' => $binding['target'],
                        'selector' => $binding['selector'],
                        'expected_revision' => $binding['expected'],
                    ], $bindings);
                    $blocks[] = ['id' => $blockId, 'component' => $blockComponent, 'sort' => $this->sortValue($block['sort'] ?? null), 'bindings' => $bindings];
                }
                $this->sortNodes($blocks);
                $sections[] = ['id' => $sectionId, 'component' => $component, 'sort' => $this->sortValue($section['sort'] ?? null), 'blocks' => $blocks];
            }
            $this->sortNodes($sections);
            $regions[] = ['id' => $regionId, 'sort' => $sort, 'sections' => $sections];
        }
        $this->sortNodes($regions);
        $normalized = ['schema' => self::SCHEMA, 'schema_version' => self::SCHEMA_VERSION, 'page_id' => $pageId, 'scope_ref' => $scopeRef, 'layout_id' => $layoutId, 'regions' => $regions];
        if (strlen($this->encode($normalized)) > self::MAX_BYTES) {
            throw $this->reject('layout_descriptor_size_limit_exceeded');
        }

        return $this->canonicalize($normalized);
    }

    /** @param array<string, mixed> $descriptor */
    public function hash(array $descriptor): string
    {
        return hash('sha256', $this->encode($this->normalize($descriptor)));
    }

    /** @param array<string, mixed> $descriptor */
    public function encode(array $descriptor): string
    {
        try {
            return json_encode($this->canonicalize($descriptor), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw $this->reject('layout_descriptor_json_invalid');
        }
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

    /** @param array<string, true> $ids */
    private function uniqueId(mixed $value, array &$ids, string $reason): string
    {
        $id = $this->stableId($value, $reason);
        if (isset($ids[$id])) {
            throw $this->reject('layout_descriptor_id_duplicate');
        }
        $ids[$id] = true;
        return $id;
    }

    private function sortValue(mixed $value): int
    {
        if (!is_int($value) || $value < 0 || $value > 1_000_000) {
            throw $this->reject('layout_descriptor_sort_invalid');
        }
        return $value;
    }

    /** @param list<array<string, mixed>> $nodes */
    private function sortNodes(array &$nodes): void
    {
        usort($nodes, static fn (array $left, array $right): int => [$left['sort'], $left['id']] <=> [$right['sort'], $right['id']]);
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $expected
     */
    private function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw $this->reject('layout_descriptor_unknown_key');
        }
    }

    private function assertDepth(mixed $value, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->reject('layout_descriptor_depth_limit_exceeded');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $this->assertDepth($child, $depth + 1);
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

    private function reject(string $reason): LayoutRejected
    {
        return new LayoutRejected($reason);
    }
}
