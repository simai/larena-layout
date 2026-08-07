<?php

declare(strict_types=1);

namespace Larena\Layout\ValueObjects;

final readonly class PageDescriptorRevision
{
    /** @param array<string, mixed> $descriptor */
    public function __construct(
        public string $pageId,
        public string $scopeRef,
        public int $revision,
        public array $descriptor,
        public string $semanticHash,
    ) {
    }
}
