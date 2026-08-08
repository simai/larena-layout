<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

interface PageDescriptorAuthorizationPolicy
{
    public const CREATE = 'layout.page.create';
    public const UPDATE = 'layout.page.update';
    public const READ = 'layout.page.read';
    public const PROJECT = 'layout.page.project';

    public function assertAllowed(string $actor, string $operation, string $scopeRef): void;
}
