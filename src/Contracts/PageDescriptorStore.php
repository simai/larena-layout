<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\ValueObjects\PageDescriptorRevision;

interface PageDescriptorStore
{
    /** @param array<string, mixed> $descriptor */
    public function create(array $descriptor, string $actor): PageDescriptorRevision;

    /** @param array<string, mixed> $descriptor */
    public function update(array $descriptor, int $expectedRevision, string $actor): PageDescriptorRevision;

    public function read(string $scopeRef, string $pageId, string $actor): ?PageDescriptorRevision;

    /** @return list<PageDescriptorRevision> */
    public function history(string $scopeRef, string $pageId, string $actor): array;

    public function rollback(
        string $scopeRef,
        string $pageId,
        int $targetRevision,
        int $expectedRevision,
        string $actor,
    ): PageDescriptorRevision;
}
