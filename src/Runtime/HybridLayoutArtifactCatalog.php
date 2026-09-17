<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;

final readonly class HybridLayoutArtifactCatalog implements LayoutArtifactCatalog
{
    public function __construct(private LayoutArtifactCatalog $overrides, private LayoutArtifactCatalog $system) {}

    public function read(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision
    {
        return $this->overrides->read($scopeRef, $artifactId, $actor) ?? $this->system->read($scopeRef, $artifactId, $actor);
    }

    public function readRevision(string $scopeRef, string $artifactId, int $revision, string $actor): ?LayoutArtifactRevision
    {
        return $this->overrides->readRevision($scopeRef, $artifactId, $revision, $actor) ?? $this->system->readRevision($scopeRef, $artifactId, $revision, $actor);
    }

    public function published(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision
    {
        return $this->overrides->published($scopeRef, $artifactId, $actor) ?? $this->system->published($scopeRef, $artifactId, $actor);
    }

    public function history(string $scopeRef, string $artifactId, string $actor): array
    {
        return $this->unique([...$this->overrides->history($scopeRef, $artifactId, $actor), ...$this->system->history($scopeRef, $artifactId, $actor)]);
    }

    public function search(string $scopeRef, string $actor, ?string $kind = null, ?string $parentId = null, ?string $childId = null): array
    {
        $overrides = $this->overrides->search($scopeRef, $actor, $kind, $parentId, $childId);
        $system = array_values(array_filter(
            $this->system->search($scopeRef, $actor, $kind, $parentId, $childId),
            fn (LayoutArtifactRevision $artifact): bool => $this->overrides->read($scopeRef, $artifact->artifactId, $actor) === null,
        ));

        return $this->unique([...$overrides, ...$system]);
    }

    public function parents(string $scopeRef, string $artifactId, string $actor): array
    {
        $system = array_values(array_filter(
            $this->system->parents($scopeRef, $artifactId, $actor),
            fn (array $row): bool => $this->overrides->read($scopeRef, $row['parent_artifact_id'], $actor) === null,
        ));

        return $this->uniqueRows([...$this->overrides->parents($scopeRef, $artifactId, $actor), ...$system]);
    }

    public function children(string $scopeRef, string $artifactId, string $actor): array
    {
        $override = $this->overrides->read($scopeRef, $artifactId, $actor);
        return $override !== null
            ? $this->overrides->children($scopeRef, $artifactId, $actor)
            : $this->system->children($scopeRef, $artifactId, $actor);
    }

    /** @param list<LayoutArtifactRevision> $revisions @return list<LayoutArtifactRevision> */
    private function unique(array $revisions): array
    {
        $result = [];
        foreach ($revisions as $revision) {
            $key = $revision->scopeRef."\0".$revision->artifactId."\0".$revision->revision;
            $result[$key] ??= $revision;
        }
        return array_values($result);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function uniqueRows(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[hash('sha256', serialize($row))] ??= $row;
        }
        return array_values($result);
    }
}
