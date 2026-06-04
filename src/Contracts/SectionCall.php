<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class SectionCall
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public string $sectionKey,
        public string $regionKey,
        public array $params = [],
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->sectionKey)
            && LayoutDescriptor::isStableKey($this->regionKey);
    }
}
