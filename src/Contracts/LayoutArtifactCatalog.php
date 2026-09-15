<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\ValueObjects\LayoutArtifactRevision;

interface LayoutArtifactCatalog
{
    public function read(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision;

    public function readRevision(string $scopeRef, string $artifactId, int $revision, string $actor): ?LayoutArtifactRevision;

    public function published(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision;

    /** @return list<LayoutArtifactRevision> */
    public function history(string $scopeRef, string $artifactId, string $actor): array;

    /** @return list<LayoutArtifactRevision> */
    public function search(string $scopeRef, string $actor, ?string $kind = null, ?string $parentId = null, ?string $childId = null): array;

    /** @return list<array{parent_artifact_id:string,parent_revision:int,instance_id:string,slot:string,sort:int,enabled:bool,child_revision:int}> */
    public function parents(string $scopeRef, string $artifactId, string $actor): array;

    /** @return list<array{child_artifact_id:string,child_revision:int,instance_id:string,slot:string,sort:int,enabled:bool}> */
    public function children(string $scopeRef, string $artifactId, string $actor): array;
}
