<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Exceptions\LayoutRejected;

/**
 * A site page's assembly (`site.<slug>`, the page assembly StorageListPageBlocks writes) as the Property content@1
 * value of the page record's `blocks` field (owner decision q-4ede0beabe: a page's content lives in its record), for
 * the migration that moves the blocks into the record. Pure: it reads the array it is given and writes nothing.
 *
 * Input: the stored page assembly (PageAssemblyDescriptorNormalizer's shape), or null for a page without one.
 * It reads the content region CONTENT_REGION (`region.main`) only: its sections in order (sort, then id) and each
 * section's blocks in order; the section itself (`layout.section`) is a wrapper and carries no content. Other
 * regions are the layout's, not the page's content, and are left out.
 *
 * Output: a list of `{type, version, params}`, one per block, in page order; an empty list without the region:
 * - type: the block's component; version: BLOCK_VERSION;
 * - params: the block's props (canonical key order); a `larena.list` block adds its `storage_list` binding's
 *   `target` and normalized `query` (StorageListBindingQuery::normalize()), so PageContentBlockValidator accepts the
 *   result exactly when the assembly was valid.
 *
 * Refuses (LayoutRejected, `<code>:<block id>`) what content@1 cannot carry instead of dropping it:
 * - `layout_content_block_variant_unsupported`: a view other than `default`, a preset, or modifiers;
 * - `layout_content_block_binding_unsupported`: a `larena.list` without exactly one `storage_list` binding, or any
 *   other block with bindings (a pinned content_document/storage_record/... revision);
 * - `layout_content_block_param_conflict`: a list block whose props already hold `target` or `query`;
 * - `layout_content_block_invalid`: a block that is not an object with a component.
 * The block ids are not kept: content@1 blocks are addressed by their position. It needs no Property: the
 * migration may run it wherever Layout is installed.
 */
final class PageAssemblyContentBlocks
{
    public const CONTENT_REGION = 'region.main';

    /** The version of every block it writes: the one block version Layout knows. */
    public const BLOCK_VERSION = 1;

    /** The params of a list block that are its `storage_list` binding in a page assembly; the others are its props. */
    public const LIST_BINDING_PARAMS = ['target', 'query'];

    /**
     * @param array<string, mixed>|null $assembly
     * @return list<array{type: string, version: int, params: array<string, mixed>}>
     */
    public static function fromAssembly(?array $assembly): array
    {
        $blocks = [];
        foreach (is_array($assembly['regions'] ?? null) ? $assembly['regions'] : [] as $region) {
            if (!is_array($region) || ($region['id'] ?? null) !== self::CONTENT_REGION) {
                continue;
            }
            foreach (self::ordered($region['sections'] ?? null) as $section) {
                foreach (self::ordered($section['blocks'] ?? null) as $block) {
                    $blocks[] = self::block($block);
                }
            }
        }

        return $blocks;
    }

    /**
     * @param array<array-key, mixed> $block
     * @return array{type: string, version: int, params: array<string, mixed>}
     */
    private static function block(array $block): array
    {
        $id = is_string($block['id'] ?? null) ? $block['id'] : '';
        $type = $block['component'] ?? null;
        $props = $block['props'] ?? [];
        if (!is_string($type) || $type === '' || !is_array($props) || ($props !== [] && array_is_list($props))) {
            throw new LayoutRejected('layout_content_block_invalid:' . $id);
        }
        if (($block['view'] ?? 'default') !== 'default' || ($block['preset'] ?? null) !== null || ($block['modifiers'] ?? []) !== []) {
            throw new LayoutRejected('layout_content_block_variant_unsupported:' . $id);
        }
        $bindings = is_array($block['bindings'] ?? null) ? $block['bindings'] : [];
        /** @var array<string, mixed> $params */
        $params = $props;
        if ($type === StorageListPageBlocks::COMPONENT) {
            $binding = $bindings[0] ?? null;
            if (count($bindings) !== 1 || !is_array($binding) || ($binding['kind'] ?? null) !== StorageListBindingQuery::KIND) {
                throw new LayoutRejected('layout_content_block_binding_unsupported:' . $id);
            }
            if (array_intersect_key($props, array_flip(self::LIST_BINDING_PARAMS)) !== []) {
                throw new LayoutRejected('layout_content_block_param_conflict:' . $id);
            }
            $params['target'] = $binding['target'] ?? null;
            $params['query'] = StorageListBindingQuery::normalize($binding['query'] ?? []);
        } elseif ($bindings !== []) {
            throw new LayoutRejected('layout_content_block_binding_unsupported:' . $id);
        }
        ksort($params, SORT_STRING);

        return ['type' => $type, 'version' => self::BLOCK_VERSION, 'params' => $params];
    }

    /**
     * Sections or blocks in page order: by sort, then id, as the assembly normalizer orders them.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function ordered(mixed $nodes): array
    {
        $ordered = array_values(array_filter(is_array($nodes) ? $nodes : [], is_array(...)));
        usort($ordered, static fn (array $left, array $right): int => [is_int($left['sort'] ?? null) ? $left['sort'] : 0, (string) ($left['id'] ?? '')]
            <=> [is_int($right['sort'] ?? null) ? $right['sort'] : 0, (string) ($right['id'] ?? '')]);

        return $ordered;
    }
}
