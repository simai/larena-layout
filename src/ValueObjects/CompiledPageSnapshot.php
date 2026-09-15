<?php

declare(strict_types=1);

namespace Larena\Layout\ValueObjects;

final readonly class CompiledPageSnapshot
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $scopeRef,
        public string $pageId,
        public int $activationRevision,
        public string $snapshotDigest,
        public array $snapshot,
        public string $activatedBy,
    ) {}
}
