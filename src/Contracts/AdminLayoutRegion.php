<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use InvalidArgumentException;

final readonly class AdminLayoutRegion
{
    public function __construct(
        public string $key,
        public int $minItems,
        public ?int $maxItems,
    ) {
    }

    public function validate(): bool
    {
        return LayoutDescriptor::isStableKey($this->key)
            && $this->minItems >= 0
            && ($this->maxItems === null || $this->maxItems >= $this->minItems);
    }

    public function isValid(): bool
    {
        return $this->validate();
    }

    public function required(): bool
    {
        return $this->minItems > 0;
    }

    public function acceptsCount(int $count): bool
    {
        return $this->validate()
            && $count >= $this->minItems
            && ($this->maxItems === null || $count <= $this->maxItems);
    }

    /** @return array{key: string, min_items: int, max_items: int|null, required: bool} */
    public function toArray(): array
    {
        if (!$this->validate()) {
            throw new InvalidArgumentException('layout_admin_recipe_invalid:region:' . $this->key);
        }

        return [
            'key' => $this->key,
            'min_items' => $this->minItems,
            'max_items' => $this->maxItems,
            'required' => $this->required(),
        ];
    }
}
