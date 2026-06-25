<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class AtomicBlockPlacement
{
    public function __construct(
        public string $instanceId,
        public string $blockKey,
        public string $slotKey,
        public int $sort = 100,
        public string $dataMode = 'provider',
        public ?string $sourceKey = null,
        public ?string $runtimeFrom = null,
        public ?string $runtimeKey = null,
    ) {
    }

    public function isValid(): bool
    {
        if (!LayoutDescriptor::isStableKey($this->instanceId)
            || !LayoutDescriptor::isStableKey($this->blockKey)
            || !LayoutDescriptor::isStableKey($this->slotKey)) {
            return false;
        }

        if (!in_array($this->dataMode, ['provider', 'runtime'], true)) {
            return false;
        }

        if ($this->dataMode === 'provider') {
            return $this->sourceKey === null || LayoutDescriptor::isStableKey($this->sourceKey);
        }

        return $this->runtimeFrom !== null
            && $this->runtimeKey !== null
            && LayoutDescriptor::isStableKey($this->runtimeFrom)
            && LayoutDescriptor::isStableKey($this->runtimeKey);
    }
}
