<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class ContentModel
{
    /**
     * @param array<string, mixed> $values
     * @param list<string> $explain
     */
    public function __construct(
        public string $sourceKey,
        public array $values,
        public array $explain,
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->sourceKey)
            && $this->values !== []
            && $this->explain !== [];
    }
}
