<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use JsonException;
use Larena\Layout\Contracts\NormalizedRenderPlan;
use Larena\Layout\Contracts\SiteDescriptor;
use Larena\Layout\Exceptions\LayoutRejected;

final readonly class MinimalCmsRenderPlanRuntime
{
    public function __construct(private PageDescriptorNormalizer $normalizer = new PageDescriptorNormalizer())
    {
    }

    /** @param array<string, mixed> $page */
    public function plan(SiteDescriptor $site, array $page): NormalizedRenderPlan
    {
        $this->assertSite($site);
        $normalized = $this->normalizer->normalize($page);
        $pageId = (string) $normalized['page_id'];
        if (!in_array($pageId, $site->pageIds, true)) {
            throw new LayoutRejected('layout_render_plan_page_unknown');
        }
        if ($normalized['scope_ref'] !== $site->scopeRef) {
            throw new LayoutRejected('layout_render_plan_scope_mismatch');
        }
        foreach ($normalized['regions'] as $region) {
            if (!is_array($region) || !in_array($region['id'] ?? null, $site->regionIds, true)) {
                throw new LayoutRejected('layout_render_plan_region_unknown');
            }
        }

        $payload = [
            'site_id' => $site->siteId,
            'page_id' => $pageId,
            'scope_ref' => $site->scopeRef,
            'layout_id' => (string) $normalized['layout_id'],
            'regions' => $normalized['regions'],
        ];

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new LayoutRejected('layout_render_plan_json_invalid');
        }

        return new NormalizedRenderPlan(
            $site->siteId,
            $pageId,
            $site->scopeRef,
            (string) $normalized['layout_id'],
            $normalized['regions'],
            hash('sha256', $encoded),
        );
    }

    private function assertSite(SiteDescriptor $site): void
    {
        if (!$this->stableId($site->siteId)
            || preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/', $site->scopeRef) !== 1
            || $site->pageIds === []
            || $site->regionIds === []
            || count($site->pageIds) !== count(array_unique($site->pageIds))
            || count($site->regionIds) !== count(array_unique($site->regionIds))) {
            throw new LayoutRejected('layout_render_plan_site_invalid');
        }
        foreach ([...$site->pageIds, ...$site->regionIds] as $id) {
            if (!$this->stableId($id)) {
                throw new LayoutRejected('layout_render_plan_site_invalid');
            }
        }
    }

    private function stableId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/', $value) === 1;
    }
}
