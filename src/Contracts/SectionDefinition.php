<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class SectionDefinition
{
    /**
     * @param list<string> $allowedRegions
     * @param list<string> $allowedAtomicBlocks
     * @param list<AtomicBlockPlacement> $defaultComposition
     * @param array<string, mixed> $settingsSchema
     */
    public function __construct(
        public string $sectionKey,
        public string $ownerPackage,
        public array $allowedRegions,
        public array $allowedAtomicBlocks,
        public array $defaultComposition,
        public string $dataSourceKey,
        public array $settingsSchema = [],
    ) {
    }

    public function isValid(): bool
    {
        if (!LayoutDescriptor::isStableKey($this->sectionKey)
            || !str_starts_with($this->ownerPackage, 'larena/')
            || !LayoutDescriptor::isStableKey($this->dataSourceKey)
            || $this->allowedRegions === []
            || $this->allowedAtomicBlocks === []
            || $this->defaultComposition === []) {
            return false;
        }

        foreach ($this->allowedRegions as $region) {
            if (!LayoutDescriptor::isStableKey($region)) {
                return false;
            }
        }

        foreach ($this->allowedAtomicBlocks as $blockKey) {
            if (!LayoutDescriptor::isStableKey($blockKey)) {
                return false;
            }
        }

        foreach ($this->defaultComposition as $placement) {
            if (!$placement->isValid() || !in_array($placement->blockKey, $this->allowedAtomicBlocks, true)) {
                return false;
            }
        }

        return true;
    }
}
