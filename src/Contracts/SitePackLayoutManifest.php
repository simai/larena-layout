<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class SitePackLayoutManifest
{
    /**
     * @param list<string> $layoutRefs
     * @param list<string> $resourcePackRefs
     */
    public function __construct(
        public string $profile,
        public array $layoutRefs,
        public array $resourcePackRefs,
        public bool $containsExecutablePhp = false,
    ) {
    }

    public function isPortable(): bool
    {
        return trim($this->profile) !== ''
            && $this->layoutRefs !== []
            && !$this->containsExecutablePhp;
    }
}
