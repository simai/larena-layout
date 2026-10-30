<?php

declare(strict_types=1);

namespace Larena\Layout\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Larena\Layout\Contracts\CompiledPageSnapshotStore;
use Larena\Layout\Contracts\PageBindingValidator;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Contracts\PageDescriptorStore;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\FileCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Runtime\LegacyPageDescriptorAdapter;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageContentBlockValidator;
use Larena\Layout\Runtime\PageDescriptorNormalizer;
use Larena\Property\Contracts\PropertyContentBlockValidator;
use Larena\Layout\SiteTemplate\LayoutSettingDefinitions;
use Larena\Layout\SiteTemplate\SiteTemplateCatalog;
use Larena\Property\Runtime\PropertyTypeRegistry;
use Larena\Setting\Runtime\SettingDefinitionRegistry;

/**
 * Layout's own composition: its tables, the page descriptor store and the compiled page snapshot store.
 *
 * The host supplies what only it knows: who may change a page (PageDescriptorAuthorizationPolicy; without it
 * the stores do not resolve) and, optionally, the normalizers with its component registry. The snapshot store
 * is chosen by config: larena-layout.snapshots.store is "database" or "file" (the default), and a file store
 * keeps its snapshots under larena-layout.snapshots.root.
 *
 * Where Property is installed, the Property type registry checks the blocks of a content@1 value (a site page's
 * blocks) with Layout's block components (PageContentBlockValidator over the host's PageAssemblyDescriptorNormalizer,
 * asked on each check): the container's registry is extended, so Property knows nothing of Layout.
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
            // The host's save-time check of bindings Layout cannot judge (a storage_list query's fields).
            $app->bound(PageBindingValidator::class) ? $app->make(PageBindingValidator::class) : null,
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
        $this->app->singleton(SiteTemplateCatalog::class);
        // A site's template and areas are settings, where Setting is installed.
        if (class_exists(SettingDefinitionRegistry::class)) {
            $this->app->afterResolving(SettingDefinitionRegistry::class, static function (SettingDefinitionRegistry $registry, Container $app): void {
                if (!$registry->declares(LayoutSettingDefinitions::TEMPLATE)) {
                    LayoutSettingDefinitions::register($registry, $app->make(SiteTemplateCatalog::class),
                        static fn (string $key, string $locale): string => (string) $app->make('translator')->get($key, [], $locale));
                }
            });
        }
        if (interface_exists(PropertyContentBlockValidator::class)) {
            $this->app->extend(PropertyTypeRegistry::class, static fn (PropertyTypeRegistry $registry, Container $app): PropertyTypeRegistry =>
                $registry->withContentBlocks(new PageContentBlockValidator(
                    static fn (): PageAssemblyDescriptorNormalizer => $app->make(PageAssemblyDescriptorNormalizer::class),
                )));
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'larena-layout');
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'larena-layout');
    }
}
