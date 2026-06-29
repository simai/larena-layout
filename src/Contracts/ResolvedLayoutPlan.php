<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class ResolvedLayoutPlan
{
    /**
     * @param list<string> $explainTrace
     * @param array<string, mixed> $cacheKeyPayload
     * @param array<string, mixed> $pageJson
     * @param array<string, mixed> $layoutSettings
     * @param array<string, mixed> $renderBlueprint
     */
    public function __construct(
        public PageDescriptor $page,
        public LayoutDescriptor $layout,
        public array $explainTrace,
        public array $cacheKeyPayload,
        public bool $canonicalTruth = false,
        public array $pageJson = [],
        public array $layoutSettings = [],
        public array $renderBlueprint = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->page->isValid()
            && $this->layout->isValid()
            && $this->explainTrace !== []
            && $this->cacheKeyPayload !== []
            && !$this->canonicalTruth;
    }
}
