<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class SectionInstance
{
    /**
     * @param list<AtomicBlockPlacement> $composition
     * @param array<string, mixed> $settings
     * @param array<string, string> $dataSources
     */
    public function __construct(
        public string $instanceId,
        public string $sectionKey,
        public string $regionKey,
        public int $sort,
        public bool $enabled,
        public array $composition,
        public array $settings = [],
        public array $dataSources = [],
    ) {
    }

    public function isValid(): bool
    {
        if (!LayoutDescriptor::isStableKey($this->instanceId)
            || !LayoutDescriptor::isStableKey($this->sectionKey)
            || !LayoutDescriptor::isStableKey($this->regionKey)) {
            return false;
        }

        $atomicIds = [];
        foreach ($this->composition as $placement) {
            if (!$placement->isValid() || in_array($placement->instanceId, $atomicIds, true)) {
                return false;
            }

            $atomicIds[] = $placement->instanceId;
        }

        foreach ($this->dataSources as $sourceKey => $fixtureKey) {
            if (!LayoutDescriptor::isStableKey((string) $sourceKey) || trim($fixtureKey) === '') {
                return false;
            }
        }

        return $this->composition !== [];
    }
}
