<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Schema;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Persistence\PdoCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoLayoutArtifactStore;
use Larena\Layout\Providers\LayoutServiceProvider;

require __DIR__.'/../bootstrap.php';

$composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
assert(($composer['extra']['laravel']['providers'] ?? null) === [LayoutServiceProvider::class]);

$database = tempnam(sys_get_temp_dir(), 'larena-layout-migration-');
assert(is_string($database));

try {
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
    Schema::swap($capsule->getConnection()->getSchemaBuilder());

    $pdo = new PDO('sqlite:'.$database);
    $policy = new class implements PageDescriptorAuthorizationPolicy {
        public function assertAllowed(string $actor, string $operation, string $scopeRef): void {}
    };
    (new PdoLayoutArtifactStore($pdo, $policy))->install();
    (new PdoCompiledPageSnapshotStore($pdo, $policy))->install();

    $migration = require __DIR__.'/../../database/migrations/2026_09_17_000001_create_larena_layout_artifact_snapshot_tables.php';
    $tables = [
        'larena_layout_artifacts',
        'larena_layout_artifact_versions',
        'larena_layout_artifact_links',
        'larena_layout_compiled_snapshots',
        'larena_layout_active_snapshots',
    ];

    // The package migration adopts tables created by the previous explicit
    // install helpers without attempting destructive replacement.
    $migration->up();
    foreach ($tables as $table) {
        assert(Schema::hasTable($table));
    }

    // Repeated migration up remains idempotent after adoption.
    $migration->up();
    foreach ($tables as $table) {
        assert(Schema::hasTable($table));
    }

    $migration->down();
    foreach ($tables as $table) {
        assert(! Schema::hasTable($table));
    }

    $migration->up();
    foreach ($tables as $table) {
        assert(Schema::hasTable($table));
    }
} finally {
    Schema::clearResolvedInstance('db.schema');
    if (is_file($database)) {
        unlink($database);
    }
}

echo "Layout service provider migration test passed.\n";
