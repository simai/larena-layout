<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoCompiledPageSnapshotStore;

$path = tempnam(sys_get_temp_dir(), 'larena-layout-snapshot-');
assert(is_string($path));

try {
    $pdo = new PDO('sqlite:'.$path);
    $policy = new class implements PageDescriptorAuthorizationPolicy {
        public function assertAllowed(string $actor, string $operation, string $scopeRef): void
        {
            if ($actor !== 'admin:1' || $scopeRef !== 'site:demo') {
                throw new LayoutRejected('layout_snapshot_access_denied');
            }
        }
    };
    $store = new PdoCompiledPageSnapshotStore($pdo, $policy);
    $store->install();
    $compiled = static fn (string $text): array => [
        'document' => ['type' => 'page', 'text' => $text],
        'html' => '<main>'.htmlspecialchars($text, ENT_QUOTES).'</main>',
        'dependencyReceipt' => ['documentDigest' => 'sha256:'.hash('sha256', $text)],
        'diagnostics' => [],
    ];

    assert($store->active('site:demo', 'page.home', 'admin:1') === null);
    $first = $store->activate('site:demo', 'page.home', 'r1', $compiled('one'), null, 'admin:1');
    assert($first->activationRevision === 1);
    $activeFirst = $store->active('site:demo', 'page.home', 'admin:1');
    if ($activeFirst === null) {
        throw new RuntimeException('The first snapshot was not activated.');
    }
    assert($activeFirst->snapshot['html'] === '<main>one</main>');
    $second = $store->activate('site:demo', 'page.home', 'r2', $compiled('two'), 1, 'admin:1');
    assert($second->activationRevision === 2);

    try {
        $store->activate('site:demo', 'page.home', 'r3', $compiled('stale'), 1, 'admin:1');
        throw new RuntimeException('Stale snapshot activation was accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === 'layout_snapshot_activation_conflict');
    }
    $activeSecond = $store->active('site:demo', 'page.home', 'admin:1');
    if ($activeSecond === null) {
        throw new RuntimeException('The second snapshot was not activated.');
    }
    assert($activeSecond->snapshotDigest === $second->snapshotDigest);

    $pdo->beginTransaction();
    $store->activate('site:demo', 'page.home', 'r3', $compiled('transaction'), 2, 'admin:1');
    $pdo->rollBack();
    $afterOuterRollback = $store->active('site:demo', 'page.home', 'admin:1');
    if ($afterOuterRollback === null) {
        throw new RuntimeException('The active snapshot disappeared after the outer rollback.');
    }
    assert($afterOuterRollback->snapshotDigest === $second->snapshotDigest);

    $rolledBack = $store->rollback('site:demo', 'page.home', $first->snapshotDigest, 2, 'admin:1');
    assert($rolledBack->activationRevision === 3);
    assert($rolledBack->snapshotDigest === $first->snapshotDigest);

    unset($store, $pdo);
    $restarted = new PdoCompiledPageSnapshotStore(new PDO('sqlite:'.$path), $policy);
    $afterRestart = $restarted->active('site:demo', 'page.home', 'admin:1');
    if ($afterRestart === null) {
        throw new RuntimeException('The active snapshot was not persisted across restart.');
    }
    assert($afterRestart->snapshotDigest === $first->snapshotDigest);
    assert($afterRestart->snapshot['schema'] === 'larena.layout.compiled_page_snapshot' && $afterRestart->snapshot['schema_version'] === 1);

    // A snapshot stored before the names lost their version (`….v1`, no schema_version) is still read.
    $canonical = static function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map($canonical, $value);
    };
    $legacy = ['schema' => 'larena.layout.compiled_page_snapshot.v1', 'scope_ref' => 'site:demo', 'page_id' => 'page.legacy',
        'source_revision' => 'r0', 'document' => ['type' => 'page', 'text' => 'old'], 'html' => '<main>old</main>',
        'dependency_receipt' => ['documentDigest' => 'sha256:'.hash('sha256', 'old')]];
    $legacyDigest = 'sha256:'.hash('sha256', json_encode($canonical($legacy), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    $legacyPdo = new PDO('sqlite:'.$path);
    $legacyPdo->prepare('INSERT INTO larena_layout_compiled_snapshots (scope_ref, page_id, snapshot_digest, source_revision, snapshot_json, created_by) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['site:demo', 'page.legacy', $legacyDigest, 'r0', json_encode($legacy + ['snapshot_digest' => $legacyDigest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'admin:1']);
    $legacyPdo->prepare('INSERT INTO larena_layout_active_snapshots (scope_ref, page_id, activation_revision, snapshot_digest, activated_by) VALUES (?, ?, ?, ?, ?)')
        ->execute(['site:demo', 'page.legacy', 1, $legacyDigest, 'admin:1']);
    $legacyActive = $restarted->active('site:demo', 'page.legacy', 'admin:1');
    if ($legacyActive === null || $legacyActive->snapshot['html'] !== '<main>old</main>') {
        throw new RuntimeException('A snapshot stored in the old form was not read.');
    }
    unset($legacyPdo);
    $restarted->uninstall();
} finally {
    if (is_file($path)) {
        unlink($path);
    }
}

echo "PdoCompiledPageSnapshotStoreTest passed.\n";
