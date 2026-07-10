<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class AdminCollectionLayoutPlan
{
    /** @param list<LayoutRegion> $regions */
    public function __construct(public array $regions)
    {
    }

    public static function standard(): self
    {
        return new self([
            new LayoutRegion('heading', [], true),
            new LayoutRegion('toolbar'),
            new LayoutRegion('content', [], true),
            new LayoutRegion('pagination'),
        ]);
    }

    public function isValid(): bool
    {
        $keys = array_map(static fn (LayoutRegion $region): string => $region->key, $this->regions);
        return count($keys) === count(array_unique($keys))
            && $keys === ['heading', 'toolbar', 'content', 'pagination'];
    }
}
