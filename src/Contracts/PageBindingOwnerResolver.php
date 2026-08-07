<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

interface PageBindingOwnerResolver
{
    /** @param array<string, mixed> $binding */
    public function resolve(array $binding, string $actor, string $scopeRef): mixed;
}
