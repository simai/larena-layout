<?php

declare(strict_types=1);

namespace Larena\Layout\ValueObjects;

final readonly class LayoutArtifactRevision
{
    /** @param array<string, mixed> $artifact */
    public function __construct(
        public string $artifactId,
        public string $scopeRef,
        public string $kind,
        public int $revision,
        public array $artifact,
        public string $semanticHash,
        public bool $published = false,
    ) {
    }
}
