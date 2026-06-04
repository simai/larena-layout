<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class DataSourceBinding
{
    public function __construct(
        public string $bindingKey,
        public string $ownerPackage,
        public string $sourceDescriptorRef,
        public bool $accessScoped,
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->bindingKey)
            && str_starts_with($this->ownerPackage, 'larena/')
            && trim($this->sourceDescriptorRef) !== ''
            && $this->accessScoped;
    }
}
