<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\LayoutDescriptor;
use Larena\Layout\Contracts\LayoutDraft;
use Larena\Layout\Contracts\LayoutRuntime;
use Larena\Layout\Contracts\PageDescriptor;
use Larena\Layout\Contracts\ResolvedLayoutPlan;
use Larena\Layout\Contracts\SitePackLayoutManifest;

final class InMemoryLayoutRuntime implements LayoutRuntime
{
    public function validateLayout(LayoutDescriptor $descriptor): bool
    {
        return $descriptor->isValid();
    }

    public function validatePage(PageDescriptor $descriptor): bool
    {
        if (!$descriptor->isValid()) {
            return false;
        }

        return $this->sectionInstanceIds($descriptor) !== null;
    }

    public function resolvePlan(PageDescriptor $page, LayoutDescriptor $layout): ResolvedLayoutPlan
    {
        if (!$this->validateLayout($layout)) {
            throw new InvalidArgumentException('layout_runtime_invalid_layout');
        }

        if (!$this->validatePage($page)) {
            throw new InvalidArgumentException('layout_runtime_invalid_page');
        }

        if ($page->layoutBinding->layoutKey !== $layout->layoutKey) {
            throw new InvalidArgumentException('layout_runtime_layout_binding_mismatch');
        }

        $regionKeys = array_map(static fn ($region): string => $region->key, $layout->regions);

        foreach ($page->sections as $section) {
            if (!in_array($section->regionKey, $regionKeys, true)) {
                throw new InvalidArgumentException('layout_runtime_unknown_region:' . $section->regionKey);
            }
        }

        foreach (array_keys($page->regionContent) as $regionKey) {
            if (!in_array($regionKey, $regionKeys, true)) {
                throw new InvalidArgumentException('layout_runtime_unknown_region_content:' . (string) $regionKey);
            }
        }

        $sectionInstanceIds = $this->sectionInstanceIds($page) ?? [];
        $pageJson = $this->pageJson($page);
        $layoutSettings = $this->layoutSettings($layout);
        $renderBlueprint = $this->renderBlueprint($page, $layout, $regionKeys, $sectionInstanceIds);

        return new ResolvedLayoutPlan(
            $page,
            $layout,
            [
                'layout-runtime:in-memory',
                'binding:' . $page->layoutBinding->scope->value . ':' . $page->layoutBinding->scopeReference,
                'page-json:' . $page->pageKey,
                'layout-settings:' . $layout->layoutKey,
                'layout:' . $layout->layoutKey,
                'sections:' . count($page->sections),
            ],
            [
                'runtime' => 'larena/layout:in_memory_layout_runtime',
                'page_key' => $page->pageKey,
                'route_key' => $page->routeKey,
                'layout_key' => $layout->layoutKey,
                'page_json_hash' => hash('sha256', json_encode($pageJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'layout_settings_hash' => hash('sha256', json_encode($layoutSettings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'section_instance_ids' => $sectionInstanceIds,
                'asset_requirements' => $layout->assetRequirements,
            ],
            false,
            $pageJson,
            $layoutSettings,
            $renderBlueprint,
        );
    }

    public function previewDraft(LayoutDraft $draft): ResolvedLayoutPlan
    {
        if (!$draft->canPublish()) {
            throw new InvalidArgumentException('layout_runtime_draft_not_validated');
        }

        throw new InvalidArgumentException('layout_runtime_preview_draft_requires_page_descriptor');
    }

    public function exportManifest(SitePackLayoutManifest $manifest): SitePackLayoutManifest
    {
        if (!$manifest->isPortable()) {
            throw new InvalidArgumentException('layout_runtime_manifest_not_portable');
        }

        return $manifest;
    }

    /**
     * @return list<string>|null
     */
    private function sectionInstanceIds(PageDescriptor $descriptor): ?array
    {
        $ids = [];

        foreach ($descriptor->sections as $section) {
            if ($section->instanceId === null || trim($section->instanceId) === '') {
                return null;
            }

            if (in_array($section->instanceId, $ids, true)) {
                return null;
            }

            $ids[] = $section->instanceId;
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageJson(PageDescriptor $page): array
    {
        return [
            'owner_package' => 'larena/layout',
            'route_key' => $page->routeKey,
            'title' => $page->title,
            'layout_key' => $page->layoutBinding->layoutKey,
            'region_content' => $page->regionContent,
            'sections' => array_map(static fn ($section): array => [
                'section_key' => $section->sectionKey,
                'region_key' => $section->regionKey,
                'instance_id' => $section->instanceId,
                'params' => $section->params,
            ], $page->sections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutSettings(LayoutDescriptor $layout): array
    {
        return [
            'owner_package' => 'larena/layout',
            'layout_key' => $layout->layoutKey,
            'page_wrap' => $layout->pageWrap,
            'content_wrap' => $layout->contentWrap,
            'service_areas' => $layout->serviceAreas,
            'breakpoint_placements' => $layout->breakpointPlacements,
            'regions' => array_map(static fn ($region): array => [
                'key' => $region->key,
                'allowed_section_types' => $region->allowedSectionTypes,
                'required' => $region->required,
            ], $layout->regions),
            'asset_requirements' => $layout->assetRequirements,
        ];
    }

    /**
     * @param list<string> $regionKeys
     * @param list<string> $sectionInstanceIds
     * @return array<string, mixed>
     */
    private function renderBlueprint(PageDescriptor $page, LayoutDescriptor $layout, array $regionKeys, array $sectionInstanceIds): array
    {
        $sectionsByRegion = [];
        foreach ($regionKeys as $regionKey) {
            $sectionsByRegion[$regionKey] = [];
        }
        foreach ($page->sections as $section) {
            $sectionsByRegion[$section->regionKey][] = $section->instanceId;
        }

        return [
            'owner_package' => 'larena/layout',
            'source' => 'page_json_plus_layout_settings',
            'route_key' => $page->routeKey,
            'title' => $page->title,
            'layout_key' => $layout->layoutKey,
            'page_wrap' => $layout->pageWrap,
            'content_wrap' => $layout->contentWrap,
            'service_areas' => $layout->serviceAreas,
            'breakpoint_placements' => $layout->breakpointPlacements,
            'regions' => array_map(static fn (string $regionKey): array => [
                'key' => $regionKey,
                'content' => $page->regionContent[$regionKey] ?? [],
                'section_instance_ids' => $sectionsByRegion[$regionKey] ?? [],
            ], $regionKeys),
            'section_instance_ids' => $sectionInstanceIds,
        ];
    }
}
