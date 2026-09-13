<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\PageBlockInstance;
use Larena\Layout\Contracts\PageComposition;
use Larena\Layout\Contracts\PageContentBinding;

final readonly class FrameworkCompositionProjector
{
    /**
     * @param list<array<string,mixed>> $resolvedBlocks
     * @param array<string,string> $bindingOwners Exact content_type => owner package map.
     * @param null|callable(PageBlockInstance,array<string,mixed>,array<string,mixed>):array<string,mixed> $projectBlock
     * @return array<string,mixed>
     */
    public function project(
        PageComposition $composition,
        array $resolvedBlocks,
        string $documentId,
        string $locale,
        array $bindingOwners,
        ?callable $projectBlock = null,
    ): array {
        if (!$composition->isValid()) {
            throw new InvalidArgumentException('layout_framework_composition_source_invalid');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/', $documentId) !== 1) {
            throw new InvalidArgumentException('layout_framework_composition_document_id_invalid');
        }
        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/', $locale) !== 1) {
            throw new InvalidArgumentException('layout_framework_composition_locale_invalid');
        }

        $resolvedById = [];
        foreach ($resolvedBlocks as $index => $resolved) {
            if (!is_string($resolved['instance_id'] ?? null)) {
                throw new InvalidArgumentException('layout_framework_composition_resolved_block_invalid:' . $index);
            }
            $id = $resolved['instance_id'];
            if (isset($resolvedById[$id])) {
                throw new InvalidArgumentException('layout_framework_composition_resolved_block_duplicate:' . $id);
            }
            $resolvedById[$id] = $resolved;
        }

        $sections = [];
        foreach ($composition->sections as $section) {
            $nodes = [];
            foreach ($section->blocks as $block) {
                $resolved = $resolvedById[$block->instanceId] ?? null;
                if (!is_array($resolved)) {
                    throw new InvalidArgumentException('layout_framework_composition_resolved_block_missing:' . $block->instanceId);
                }
                $node = $this->blockNode($block, $resolved, $bindingOwners);
                if ($projectBlock !== null) {
                    $node = $projectBlock($block, $resolved, $node);
                    if (($node['id'] ?? null) !== $block->instanceId
                        || !is_string($node['type'] ?? null)
                        || !is_array($node['extensions']['larena:page-block'] ?? null)) {
                        throw new InvalidArgumentException('layout_framework_composition_block_projection_invalid:' . $block->instanceId);
                    }
                }
                $nodes[] = $node;
            }
            $sections[] = [
                'id' => $section->instanceId,
                'type' => 'layout.section',
                'slots' => ['default' => $nodes],
                'extensions' => [
                    'larena:page-section' => [
                        'section_id' => $section->sectionId,
                        'region_id' => $section->regionId,
                        'sort' => $section->sort,
                        'enabled' => $section->enabled,
                        'parameters' => $section->parameters,
                    ],
                ],
            ];
        }

        return [
            'schema' => 'simai.composition.document.v1',
            'id' => $documentId,
            'profile' => 'ui-layout',
            'locale' => $locale,
            'root' => [
                'id' => 'page:' . $documentId,
                'type' => 'layout.page',
                'slots' => ['default' => $sections],
            ],
            'extensions' => [
                'larena:page-composition' => [
                    'source_schema' => $composition->schema,
                    'layout_id' => $composition->layoutId,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $resolved @param array<string,string> $bindingOwners @return array<string,mixed> */
    private function blockNode(PageBlockInstance $block, array $resolved, array $bindingOwners): array
    {
        $settings = is_array($resolved['settings'] ?? null) ? $resolved['settings'] : null;
        if ($settings === null) {
            throw new InvalidArgumentException('layout_framework_composition_resolved_settings_invalid:' . $block->instanceId);
        }
        $props = $settings + ['instance_id' => $block->instanceId, 'smart_view' => $block->smartView];
        if (is_string($resolved['image_url'] ?? null)) {
            $props['image_url'] = $resolved['image_url'];
        }

        $bindings = [];
        foreach ($block->contentBindings as $binding) {
            $bindings[] = $this->binding($binding, $bindingOwners);
        }

        return [
            'id' => $block->instanceId,
            'type' => $block->smartView,
            'props' => $props,
            'presentation' => ['view' => 'default'],
            'bindings' => $bindings,
            'extensions' => [
                'larena:page-block' => [
                    'block_id' => $block->type,
                    'enabled' => $block->enabled,
                    'sort' => $block->sort,
                    'content_bindings' => array_map(
                        static fn (PageContentBinding $binding): array => $binding->toArray(),
                        $block->contentBindings,
                    ),
                    'asset_refs' => array_map(static fn ($asset): array => $asset->toArray(), $block->assetRefs),
                ],
            ],
        ];
    }

    /** @param array<string,string> $bindingOwners @return array<string,string> */
    private function binding(PageContentBinding $binding, array $bindingOwners): array
    {
        $owner = $bindingOwners[$binding->contentType] ?? null;
        if (!is_string($owner) || preg_match('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/', $owner) !== 1) {
            throw new InvalidArgumentException('layout_framework_composition_binding_owner_missing:' . $binding->contentType);
        }
        $result = ['owner' => $owner, 'ref' => $binding->contentRef, 'target' => $binding->bindingId];
        if ($binding->expectedRevision !== null) {
            $result['revision'] = (string) $binding->expectedRevision;
        }
        return $result;
    }
}
