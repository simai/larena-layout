<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\Enums\LayoutVersionStatus;

final readonly class LayoutVersion
{
    public function __construct(
        public string $versionId,
        public LayoutDescriptor $descriptor,
        public LayoutVersionStatus $status,
    ) {
    }

    public function isValid(): bool
    {
        return trim($this->versionId) !== ''
            && $this->descriptor->isValid();
    }
}
