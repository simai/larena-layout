<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\AdminCollectionLayoutPlan;
use Larena\Layout\Contracts\AdminFormLayoutPlan;
use Larena\Layout\Contracts\AdminLayoutRecipe;

final class AdminLayoutRecipeRegistry
{
    /** @var array<string, AdminLayoutRecipe> */
    private array $recipes = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(AdminCollectionLayoutPlan::recipe());
        $registry->register(AdminFormLayoutPlan::recipe());

        return $registry;
    }

    public function register(AdminLayoutRecipe $recipe): void
    {
        if (!$recipe->validate()) {
            throw new InvalidArgumentException('layout_admin_recipe_invalid:' . $recipe->recipeKey);
        }
        if (isset($this->recipes[$recipe->recipeKey])) {
            throw new InvalidArgumentException('layout_admin_recipe_collision:' . $recipe->recipeKey);
        }

        $this->recipes[$recipe->recipeKey] = $recipe;
    }

    public function recipe(string $key): AdminLayoutRecipe
    {
        return $this->recipes[$key]
            ?? throw new InvalidArgumentException('layout_admin_recipe_unknown:' . $key);
    }

    /** @return array<string, AdminLayoutRecipe> */
    public function recipes(): array
    {
        $recipes = $this->recipes;
        ksort($recipes);

        return $recipes;
    }
}
