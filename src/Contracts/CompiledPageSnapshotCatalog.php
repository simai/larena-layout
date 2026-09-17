<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

/** Optional read capability; does not change activation or rollback contracts. */
interface CompiledPageSnapshotCatalog
{
    /**
     * Digest keyset order is deterministic, not publication chronology.
     * No HTML, dependency payload, actor identity or content is returned.
     *
     * @phpstan-impure
     * @return array{snapshots:list<array{snapshot_digest:string,source_revision:string,active:bool}>,next_cursor:?string}
     */
    public function history(string $scopeRef, string $pageId, string $actor, int $limit = 20, ?string $afterDigest = null): array;
}
