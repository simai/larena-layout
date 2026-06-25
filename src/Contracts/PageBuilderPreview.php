<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageBuilderPreview
{
    /**
     * @param list<ComposedSection> $sections
     * @param list<string> $explain
     * @param array<string, mixed> $draftBoundary
     */
    public function __construct(
        public PageDescriptor $page,
        public array $sections,
        public string $html,
        public array $draftBoundary,
        public array $explain,
    ) {
    }

    public function isValid(): bool
    {
        if (!$this->page->isValid()
            || $this->sections === []
            || trim($this->html) === ''
            || $this->explain === []
            || ($this->draftBoundary['publish_enabled'] ?? true) !== false) {
            return false;
        }

        foreach ($this->sections as $section) {
            if (!$section->isValid()) {
                return false;
            }
        }

        return true;
    }
}
