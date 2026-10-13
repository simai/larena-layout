<?php

declare(strict_types=1);

namespace Larena\Layout\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Layout\Contracts\CompiledPageSnapshotStore;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Contracts\PageDescriptorStore;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\FileCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Runtime\LegacyPageDescriptorAdapter;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageDescriptorNormalizer;

/**
 * Layout's own composition: its tables, the page descriptor store and the compiled page snapshot store.
 *
 * The host supplies what only it knows: who may change a page (PageDescriptorAuthorizationPolicy; without it
 * the stores do not resolve) and, optionally, the normalizers with its component registry. The snapshot store
 * is chosen by config: larena-layout.snapshots.store is "database" or "file" (the default), and a file store
 * keeps its snapshots under larena-layout.snapshots.root.
 */
final class LayoutServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PdoPageDescriptorStore::class, static fn (Container $app): PdoPageDescriptorStore => new PdoPageDescriptorStore(
            $app->make(DatabaseManager::class)->connection()->getPdo(),
            $app->make(PageDescriptorAuthorizationPolicy::class),
            $app->make(PageAssemblyDescriptorNormalizer::class),
            $app->make(PageDescriptorNormalizer::class),
            new LegacyPageDescriptorAdapter(
                $app->make(PageDescriptorNormalizer::class),
                $app->make(PageAssemblyDescriptorNormalizer::class),
            ),
        ));
        $this->app->scoped(PageDescriptorStore::class, static fn (Container $app): PageDescriptorStore => $app->make(PdoPageDescriptorStore::class));
        $this->app->scoped(CompiledPageSnapshotStore::class, static function (Container $app): CompiledPageSnapshotStore {
            $config = $app->make('config');
            $authorization = $app->make(PageDescriptorAuthorizationPolicy::class);
            if ($config->get('larena-layout.snapshots.store', 'file') === 'database') {
                return new PdoCompiledPageSnapshotStore($app->make(DatabaseManager::class)->connection()->getPdo(), $authorization);
            }
            $root = (string) $config->get('larena-layout.snapshots.root', $app->bound('path.storage') ? $app->make('path.storage').'/app/private/composition-recipe' : '');
            if ($root === '' || (! is_dir($root) && ! @mkdir($root, 0755, true) && ! is_dir($root))) {
                throw new LayoutRejected('layout_snapshot_root_invalid');
            }

            return new FileCompiledPageSnapshotStore($root, $authorization);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
