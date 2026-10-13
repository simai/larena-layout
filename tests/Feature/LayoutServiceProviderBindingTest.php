<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Arr;
use Larena\Layout\Contracts\CompiledPageSnapshotStore;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Contracts\PageDescriptorStore;
use Larena\Layout\Persistence\FileCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Providers\LayoutServiceProvider;

require __DIR__.'/../bootstrap.php';

$snapshotRoot = sys_get_temp_dir().'/larena-layout-provider-'.bin2hex(random_bytes(4));
$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

$compose = static function (array $layoutConfig) use ($capsule): Container {
    $app = new Container();
    $app->instance('config', new class (['larena-layout' => $layoutConfig]) {
        /** @param array<string, mixed> $items */
        public function __construct(private array $items) {}
        public function get(string $key, mixed $default = null): mixed { return Arr::get($this->items, $key, $default); }
    });
    $app->instance(DatabaseManager::class, $capsule->getDatabaseManager());
    // A bare container stands in for the application: the provider needs only bindings and config.
    (new ReflectionClass(LayoutServiceProvider::class))->newInstance($app)->register();

    return $app;
};

// Without the host's page policy the stores do not resolve: nobody may change a page by default.
$app = $compose([]);
try {
    $app->make(PageDescriptorStore::class);
    throw new RuntimeException('A page store resolved without a page policy.');
} catch (Illuminate\Contracts\Container\BindingResolutionException) {
}

$policy = new class implements PageDescriptorAuthorizationPolicy {
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void {}
};

// The package composes its stores; the snapshot store follows larena-layout.snapshots.
$app = $compose(['snapshots' => ['store' => 'file', 'root' => $snapshotRoot]]);
$app->instance(PageDescriptorAuthorizationPolicy::class, $policy);
assert($app->make(PageDescriptorStore::class) instanceof PdoPageDescriptorStore);
assert($app->make(CompiledPageSnapshotStore::class) instanceof FileCompiledPageSnapshotStore);
assert(is_dir($snapshotRoot));

$app = $compose(['snapshots' => ['store' => 'database']]);
$app->instance(PageDescriptorAuthorizationPolicy::class, $policy);
assert($app->make(CompiledPageSnapshotStore::class) instanceof PdoCompiledPageSnapshotStore);

@rmdir($snapshotRoot);
echo "Layout service provider binding test passed.\n";
