<?php

declare(strict_types=1);

namespace Larena\Layout\ValueObjects;

final readonly class LayoutPlacement
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $instanceId,
        public string $artifactRef,
        public ?int $expectedRevision,
        public string $slot,
        public int $sort,
        public bool $enabled,
        public array $parameters,
    ) {
    }

    /** @param array<string, mixed> $placement */
    public static function fromArray(array $placement): self
    {
        return new self(
            (string) $placement['instance_id'],
            (string) $placement['artifact_ref'],
            is_int($placement['expected_revision']) ? $placement['expected_revision'] : null,
            (string) $placement['slot'],
            (int) $placement['sort'],
            (bool) $placement['enabled'],
            $placement['parameters'],
        );
    }
}
