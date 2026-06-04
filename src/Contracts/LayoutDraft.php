<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class LayoutDraft
{
    public function __construct(
        public LayoutDescriptor $descriptor,
        public string $actorReference,
        public bool $validated = false,
    ) {
    }

    public function canPublish(): bool
    {
        return $this->descriptor->isValid()
            && trim($this->actorReference) !== ''
            && $this->validated;
    }
}
