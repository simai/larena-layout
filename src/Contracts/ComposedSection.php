<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class ComposedSection
{
    /**
     * @param array<string, string> $atomicHtmlById
     * @param list<string> $explain
     */
    public function __construct(
        public SectionInstance $instance,
        public array $atomicHtmlById,
        public string $html,
        public array $explain,
    ) {
    }

    public function isValid(): bool
    {
        return $this->instance->isValid()
            && $this->atomicHtmlById !== []
            && trim($this->html) !== ''
            && $this->explain !== [];
    }
}
