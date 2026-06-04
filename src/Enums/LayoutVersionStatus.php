<?php

declare(strict_types=1);

namespace Larena\Layout\Enums;

enum LayoutVersionStatus: string
{
    case Draft = 'draft';
    case Previewed = 'previewed';
    case Published = 'published';
    case RolledBack = 'rolled_back';

    public function isLive(): bool
    {
        return $this === self::Published;
    }
}
