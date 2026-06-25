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

        return new ResolvedLayoutPlan(
            $page,
            $layout,
            [
                'layout-runtime:in-memory',
                'binding:' . $page->layoutBinding->scope->value . ':' . $page->layoutBinding->scopeReference,
                'layout:' . $layout->layoutKey,
                'sections:' . count($page->sections),
            ],
            [
                'runtime' => 'larena/layout:in_memory_layout_runtime',
                'page_key' => $page->pageKey,
                'route_key' => $page->routeKey,
                'layout_key' => $layout->layoutKey,
                'section_instance_ids' => $this->sectionInstanceIds($page) ?? [],
                'asset_requirements' => $layout->assetRequirements,
            ],
            false,
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
}
