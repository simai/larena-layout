<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageDescriptor
{
    /**
     * @param list<SectionCall> $sections
     * @param array<string, mixed> $regionContent
     */
    public function __construct(
        public string $pageKey,
        public string $routeKey,
        public LayoutBinding $layoutBinding,
        public LayoutProfile $profile,
        public array $sections = [],
        public string $title = '',
        public array $regionContent = [],
    ) {
    }

    public function isValid(): bool
    {
        foreach ($this->sections as $section) {
            if (!$section->isValid()) {
                return false;
            }
        }

        return LayoutDescriptor::isStableKey($this->pageKey)
            && trim($this->routeKey) !== ''
            && $this->layoutBinding->isValid()
            && $this->profile->isValid();
    }
}
