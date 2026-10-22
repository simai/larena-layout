<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

/**
 * The list blocks of a site page's assembly: one place that puts a `larena.list` block with
 * its `storage_list` binding on the page `site.{key}`, reads it back and takes it away.
 *
 * Putting a block keeps everything else on the page: a block with the same id is replaced
 * where it stands (its other props, such as `title_field`, stay), a new one joins the main
 * section, and a page without an assembly gets one. Only arrays are built here; the store
 * normalizes and checks them when they are saved.
 */
final class StorageListPageBlocks
{
    public const PAGE_PREFIX = 'site.';

    public const BLOCK_ID = 'block.list';

    public const COMPONENT = 'larena.list';

    private const REGION = 'region.main';

    private const SECTION = 'section.main';

    /** The page assembly of the site page `key`. */
    public static function pageId(string $key): string
    {
        return self::PAGE_PREFIX.$key;
    }

    /**
     * The list block of a page assembly: its title, target and query; null when it has none.
     *
     * @param array<string, mixed>|null $descriptor
     * @return array{title: ?string, target: string, query: array<string, mixed>}|null
     */
    public static function read(?array $descriptor, string $blockId = self::BLOCK_ID): ?array
    {
        foreach (self::blocks($descriptor) as $block) {
            if (($block['id'] ?? null) !== $blockId || ($block['component'] ?? null) !== self::COMPONENT) {
                continue;
            }
            foreach (is_array($block['bindings'] ?? null) ? $block['bindings'] : [] as $binding) {
                if (is_array($binding) && ($binding['kind'] ?? null) === StorageListBindingQuery::KIND) {
                    $props = is_array($block['props'] ?? null) ? $block['props'] : [];

                    return [
                        'title' => is_string($props['title'] ?? null) && $props['title'] !== '' ? $props['title'] : null,
                        'target' => (string) ($binding['target'] ?? ''),
                        'query' => is_array($binding['query'] ?? null) ? $binding['query'] : [],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * The page assembly with the list block put in.
     *
     * @param array<string, mixed>|null $descriptor the page's current assembly, or null for a new one
     * @param array<string, mixed> $query the binding's query (StorageListBindingQuery)
     * @return array<string, mixed>
     */
    public static function put(?array $descriptor, string $scopeRef, string $pageId, string $target, array $query, ?string $title, string $blockId = self::BLOCK_ID): array
    {
        $descriptor ??= [
            'schema' => PageAssemblyDescriptorNormalizer::SCHEMA,
            'schema_version' => PageAssemblyDescriptorNormalizer::SCHEMA_VERSION,
            'site_id' => 'site.main',
            'page_id' => $pageId,
            'scope_ref' => $scopeRef,
            'layout_id' => 'layout.site-page',
            'regions' => [],
        ];
        $block = static fn (array $props, int $sort): array => [
            'id' => $blockId, 'component' => self::COMPONENT, 'view' => 'default', 'preset' => null, 'modifiers' => [],
            'props' => array_diff_key($props, ['title' => true]) + ($title !== null && $title !== '' ? ['title' => $title] : []),
            'sort' => $sort,
            'bindings' => [[
                'id' => self::bindingId($blockId), 'kind' => StorageListBindingQuery::KIND, 'target' => $target, 'selector' => '$', 'query' => $query,
            ]],
        ];
        $regions = is_array($descriptor['regions'] ?? null) ? $descriptor['regions'] : [];
        // The block where it stands.
        foreach ($regions as $r => $region) {
            foreach (is_array($region['sections'] ?? null) ? $region['sections'] : [] as $s => $section) {
                foreach (is_array($section['blocks'] ?? null) ? $section['blocks'] : [] as $b => $existing) {
                    if (is_array($existing) && ($existing['id'] ?? null) === $blockId) {
                        $regions[$r]['sections'][$s]['blocks'][$b] = $block(is_array($existing['props'] ?? null) ? $existing['props'] : [],
                            is_int($existing['sort'] ?? null) ? $existing['sort'] : 100);
                        $descriptor['regions'] = $regions;

                        return $descriptor;
                    }
                }
            }
        }
        // A new block at the end of the main section, made when the page has none.
        foreach ($regions as $r => $region) {
            foreach (is_array($region['sections'] ?? null) ? $region['sections'] : [] as $s => $section) {
                if (($section['id'] ?? null) === self::SECTION) {
                    $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
                    $regions[$r]['sections'][$s]['blocks'][] = $block([], self::nextSort($blocks));
                    $descriptor['regions'] = $regions;

                    return $descriptor;
                }
            }
        }
        $section = ['id' => self::SECTION, 'component' => 'layout.section', 'view' => 'default', 'preset' => null,
            'modifiers' => [], 'props' => [], 'sort' => 100, 'blocks' => [$block([], 100)]];
        foreach ($regions as $r => $region) {
            if (($region['id'] ?? null) === self::REGION) {
                $sections = is_array($region['sections'] ?? null) ? $region['sections'] : [];
                $section['sort'] = self::nextSort($sections);
                $regions[$r]['sections'][] = $section;
                $descriptor['regions'] = $regions;

                return $descriptor;
            }
        }
        $regions[] = ['id' => self::REGION, 'sort' => self::nextSort($regions), 'sections' => [$section]];
        $descriptor['regions'] = $regions;

        return $descriptor;
    }

    /**
     * The page assembly without the list block; the rest of the page stays as it was.
     *
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public static function remove(array $descriptor, string $blockId = self::BLOCK_ID): array
    {
        $regions = is_array($descriptor['regions'] ?? null) ? $descriptor['regions'] : [];
        foreach ($regions as $r => $region) {
            foreach (is_array($region['sections'] ?? null) ? $region['sections'] : [] as $s => $section) {
                $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];
                $kept = array_values(array_filter($blocks, static fn (mixed $block): bool => !is_array($block) || ($block['id'] ?? null) !== $blockId));
                if (count($kept) !== count($blocks)) {
                    $regions[$r]['sections'][$s]['blocks'] = $kept;
                }
            }
        }
        $descriptor['regions'] = $regions;

        return $descriptor;
    }

    /** The binding id that goes with a block id: `block.list` binds as `binding.list`. */
    private static function bindingId(string $blockId): string
    {
        return str_starts_with($blockId, 'block.') ? 'binding.'.substr($blockId, strlen('block.')) : $blockId.'.binding';
    }

    /**
     * @param array<array-key, mixed> $nodes
     */
    private static function nextSort(array $nodes): int
    {
        $sorts = array_map(static fn (mixed $node): int => is_array($node) && is_int($node['sort'] ?? null) ? $node['sort'] : 0, $nodes);

        return ($sorts === [] ? 0 : max($sorts)) + 100;
    }

    /**
     * @param array<string, mixed>|null $descriptor
     * @return list<array<string, mixed>>
     */
    private static function blocks(?array $descriptor): array
    {
        $blocks = [];
        foreach (is_array($descriptor['regions'] ?? null) ? $descriptor['regions'] : [] as $region) {
            foreach (is_array($region['sections'] ?? null) ? $region['sections'] : [] as $section) {
                foreach (is_array($section['blocks'] ?? null) ? $section['blocks'] : [] as $block) {
                    if (is_array($block)) {
                        $blocks[] = $block;
                    }
                }
            }
        }

        return $blocks;
    }
}
