<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\ValueObjects\PageBindingResult;

interface PageBindingOwnerResolver
{
    /** @param array<string, mixed> $binding */
    public function resolve(array $binding, string $actor, string $scopeRef): PageBindingResult;
}
