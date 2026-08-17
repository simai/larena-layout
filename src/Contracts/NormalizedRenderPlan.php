<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class NormalizedRenderPlan
{
    /** @param list<array<string, mixed>> $regions */
    public function __construct(
        public string $siteId,
        public string $pageId,
        public string $scopeRef,
        public string $layoutId,
        public array $regions,
        public string $digest,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema' => 'larena.layout.normalized_render_plan.v1',
            'site_id' => $this->siteId,
            'page_id' => $this->pageId,
            'scope_ref' => $this->scopeRef,
            'layout_id' => $this->layoutId,
            'regions' => $this->regions,
            'digest' => $this->digest,
        ];
    }
}
