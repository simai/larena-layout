<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class LayoutRegion
{
    /**
     * @param list<string> $allowedSectionTypes
     */
    public function __construct(
        public string $key,
        public array $allowedSectionTypes = [],
        public bool $required = false,
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->key);
    }
}
