<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class BlockWidgetCall
{
    /**
     * @param array<string, mixed> $runtimeBinding
     */
    public function __construct(
        public string $widgetKey,
        public string $slotKey,
        public array $runtimeBinding = [],
        public bool $usesDataviewDescriptor = false,
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->widgetKey)
            && LayoutDescriptor::isStableKey($this->slotKey);
    }
}
