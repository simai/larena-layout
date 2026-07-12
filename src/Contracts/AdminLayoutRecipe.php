<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use InvalidArgumentException;
use Larena\Layout\Enums\LayoutProfileCode;

final readonly class AdminLayoutRecipe
{
    /** @param list<AdminLayoutRegion> $regions */
    public function __construct(
        public string $recipeKey,
        public string $version,
        public LayoutProfileCode $profile,
        public array $regions,
        public bool $requiresAccessPolicy,
        public bool $requiresAuditForEffects,
    ) {
    }

    public function validate(): bool
    {
        if (
            !LayoutDescriptor::isStableKey($this->recipeKey)
            || !str_starts_with($this->recipeKey, 'admin.')
            || preg_match('/^\d+\.\d+\.\d+$/', $this->version) !== 1
            || !$this->profile->requiresAccessPolicy()
            || !$this->requiresAccessPolicy
            || !$this->requiresAuditForEffects
            || $this->regions === []
        ) {
            return false;
        }

        $keys = [];
        foreach ($this->regions as $region) {
            if (!$region->validate() || isset($keys[$region->key])) {
                return false;
            }
            $keys[$region->key] = true;
        }

        return true;
    }

    public function isValid(): bool
    {
        return $this->validate();
    }

    public function region(string $key): AdminLayoutRegion
    {
        if (!$this->validate()) {
            throw new InvalidArgumentException('layout_admin_recipe_invalid:' . $this->recipeKey);
        }

        foreach ($this->regions as $region) {
            if ($region->key === $key) {
                return $region;
            }
        }

        throw new InvalidArgumentException('layout_admin_recipe_unknown:region:' . $key);
    }

    /**
     * Layout validates placement identity and cardinality only. Component keys,
     * props, rendering and handlers remain owned by Larena UI and backend packages.
     *
     * @param array<mixed, mixed> $assignments Untrusted region-to-invocation map.
     */
    public function acceptsAssignments(array $assignments): bool
    {
        if (!$this->validate()) {
            return false;
        }

        $knownRegions = [];
        foreach ($this->regions as $region) {
            $knownRegions[$region->key] = $region;
        }

        $invocationIds = [];
        foreach ($assignments as $regionKey => $ids) {
            if (
                !is_string($regionKey)
                || !isset($knownRegions[$regionKey])
                || !is_array($ids)
                || !array_is_list($ids)
            ) {
                return false;
            }

            foreach ($ids as $id) {
                if (!is_string($id) || !LayoutDescriptor::isStableKey($id) || isset($invocationIds[$id])) {
                    return false;
                }
                $invocationIds[$id] = true;
            }
        }

        foreach ($knownRegions as $regionKey => $region) {
            if (!$region->acceptsCount(count($assignments[$regionKey] ?? []))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{
     *   schema: string,
     *   recipe_key: string,
     *   version: string,
     *   owner_package: string,
     *   profile: string,
     *   regions: list<array{key: string, min_items: int, max_items: int|null, required: bool}>,
     *   safety: array{requires_access_policy: bool, requires_audit_for_effects: bool}
     * }
     */
    public function toArray(): array
    {
        if (!$this->validate()) {
            throw new InvalidArgumentException('layout_admin_recipe_invalid:' . $this->recipeKey);
        }

        return [
            'schema' => 'larena.layout.admin_recipe.v1',
            'recipe_key' => $this->recipeKey,
            'version' => $this->version,
            'owner_package' => 'larena/layout',
            'profile' => $this->profile->value,
            'regions' => array_map(
                static fn (AdminLayoutRegion $region): array => $region->toArray(),
                $this->regions,
            ),
            'safety' => [
                'requires_access_policy' => $this->requiresAccessPolicy,
                'requires_audit_for_effects' => $this->requiresAuditForEffects,
            ],
        ];
    }
}
