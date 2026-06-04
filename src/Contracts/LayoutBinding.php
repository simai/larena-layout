<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\Enums\LayoutBindingScope;

final readonly class LayoutBinding
{
    /**
     * @param list<string> $sourceTrace
     */
    public function __construct(
        public LayoutBindingScope $scope,
        public string $scopeReference,
        public string $layoutKey,
        public array $sourceTrace = [],
    ) {
    }

    public function isValid(): bool
    {
        return trim($this->scopeReference) !== ''
            && LayoutDescriptor::isStableKey($this->layoutKey)
            && $this->sourceTrace !== [];
    }
}
