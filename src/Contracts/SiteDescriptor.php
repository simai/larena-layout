<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class SiteDescriptor
{
    /**
     * @param list<string> $pageIds
     * @param list<string> $regionIds
     */
    public function __construct(
        public string $siteId,
        public string $scopeRef,
        public array $pageIds,
        public array $regionIds,
    ) {
    }
}
