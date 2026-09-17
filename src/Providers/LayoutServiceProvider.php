<?php

declare(strict_types=1);

namespace Larena\Layout\Providers;

use Illuminate\Support\ServiceProvider;

final class LayoutServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}

