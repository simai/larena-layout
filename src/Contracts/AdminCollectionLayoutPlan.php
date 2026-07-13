<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\Enums\LayoutProfileCode;

final readonly class AdminCollectionLayoutPlan
{
    /**
     * Canonical Simai Framework utility families and classes used by the
     * collection content region. Consumers must resolve these family IDs from
     * the Framework Contract Registry before emitting the classes.
     *
     * @return array<string, list<string>>
     */
    public static function frameworkUtilityClasses(): array
    {
        return array_merge(...array_values(self::frameworkUtilityRegions()));
    }

    /**
     * Utility placement is part of the recipe contract: collection utilities
     * compose the outer layout, while overflow belongs to the content region
     * that actually owns horizontal scrolling.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function frameworkUtilityRegions(): array
    {
        return [
            'collection' => [
                'utility.display' => ['flex'],
                'utility.flex-direction' => ['flex-col'],
                'utility.gap' => ['gap-1'],
            ],
            'content' => [
                'utility.overflow' => ['overflow-x-auto'],
                'utility.width' => ['w-full'],
            ],
        ];
    }

    /** @param list<LayoutRegion> $regions */
    public function __construct(public array $regions)
    {
    }

    public static function standard(): self
    {
        return new self(array_map(
            static fn (AdminLayoutRegion $region): LayoutRegion => new LayoutRegion(
                $region->key,
                [],
                $region->required(),
            ),
            self::recipe()->regions,
        ));
    }

    public static function recipe(): AdminLayoutRecipe
    {
        return new AdminLayoutRecipe(
            'admin.collection',
            '1.0.0',
            LayoutProfileCode::ListPage,
            [
                new AdminLayoutRegion('heading', 1, 1),
                new AdminLayoutRegion('toolbar', 0, 1),
                new AdminLayoutRegion('content', 1, 1),
                new AdminLayoutRegion('pagination', 0, 1),
            ],
            true,
            true,
        );
    }

    public function isValid(): bool
    {
        $keys = array_map(static fn (LayoutRegion $region): string => $region->key, $this->regions);
        return count($keys) === count(array_unique($keys))
            && $keys === ['heading', 'toolbar', 'content', 'pagination'];
    }
}
