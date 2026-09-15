<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\ValueObjects\LayoutArtifactRevision;

interface LayoutArtifactStore extends LayoutArtifactCatalog
{
    public function install(): void;

    public function uninstall(): void;

    /** @param array<string, mixed> $artifact */
    public function create(array $artifact, string $actor): LayoutArtifactRevision;

    /** @param array<string, mixed> $artifact */
    public function update(array $artifact, int $expectedRevision, string $actor): LayoutArtifactRevision;

    public function publish(string $scopeRef, string $artifactId, int $revision, int $expectedCurrentRevision, string $actor): LayoutArtifactRevision;

    public function restore(string $scopeRef, string $artifactId, int $targetRevision, int $expectedCurrentRevision, string $actor): LayoutArtifactRevision;
}
