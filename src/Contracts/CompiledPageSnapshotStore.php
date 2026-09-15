<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\ValueObjects\CompiledPageSnapshot;

interface CompiledPageSnapshotStore
{
    /** @param array<string, mixed> $compiled */
    public function activate(
        string $scopeRef,
        string $pageId,
        string $sourceRevision,
        array $compiled,
        ?int $expectedActivationRevision,
        string $actor,
    ): CompiledPageSnapshot;

    public function active(string $scopeRef, string $pageId, string $actor): ?CompiledPageSnapshot;

    public function rollback(
        string $scopeRef,
        string $pageId,
        string $targetSnapshotDigest,
        int $expectedActivationRevision,
        string $actor,
    ): CompiledPageSnapshot;
}
