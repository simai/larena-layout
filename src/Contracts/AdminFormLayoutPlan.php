<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\Enums\LayoutProfileCode;

final readonly class AdminFormLayoutPlan
{
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
            'admin.form',
            '1.0.0',
            LayoutProfileCode::FormPage,
            [
                new AdminLayoutRegion('heading', 1, 1),
                new AdminLayoutRegion('notifications', 0, 4),
                new AdminLayoutRegion('fields', 1, 64),
                new AdminLayoutRegion('actions', 1, 4),
            ],
            true,
            true,
        );
    }

    public function isValid(): bool
    {
        $keys = array_map(static fn (LayoutRegion $region): string => $region->key, $this->regions);

        return count($keys) === count(array_unique($keys))
            && $keys === ['heading', 'notifications', 'fields', 'actions'];
    }
}
