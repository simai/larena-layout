<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class LayoutDescriptor
{
    /**
     * @param list<LayoutRegion> $regions
     * @param list<string> $assetRequirements
     */
    public function __construct(
        public string $layoutKey,
        public LayoutProfile $profile,
        public array $regions,
        public array $assetRequirements = [],
    ) {
    }

    public static function isStableKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)*$/', $key) === 1;
    }

    public function isValid(): bool
    {
        if (!self::isStableKey($this->layoutKey) || !$this->profile->isValid() || $this->regions === []) {
            return false;
        }

        $regionKeys = [];
        foreach ($this->regions as $region) {
            if (!$region->isValid() || in_array($region->key, $regionKeys, true)) {
                return false;
            }
            $regionKeys[] = $region->key;
        }

        return true;
    }
}
