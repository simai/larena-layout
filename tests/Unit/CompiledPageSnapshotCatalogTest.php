<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\FileCompiledPageSnapshotStore;
use Larena\Layout\Persistence\PdoCompiledPageSnapshotStore;

(static function (): void {
    $root = sys_get_temp_dir().'/larena-history-'.bin2hex(random_bytes(12));
    mkdir($root, 0700);
    $policy = new class implements PageDescriptorAuthorizationPolicy {
        public function assertAllowed(string $actor, string $operation, string $scopeRef): void
        {
            if ($actor !== 'admin:1' || $scopeRef !== 'site:demo') {
                throw new LayoutRejected('layout_snapshot_access_denied');
            }
        }
    };
    try {
        foreach (['pdo', 'file'] as $backend) {
            $pdo = new PDO('sqlite::memory:');
            $store = $backend === 'pdo' ? new PdoCompiledPageSnapshotStore($pdo, $policy) : new FileCompiledPageSnapshotStore($root, $policy);
            if ($store instanceof PdoCompiledPageSnapshotStore) {
                $store->install();
            }
            assert($store->history('site:demo', 'page.home', 'admin:1')['snapshots'] === []);
            $compiled = static fn (string $text): array => ['document' => ['text' => $text], 'html' => '<main>'.$text.'</main>',
                'dependencyReceipt' => ['documentDigest' => 'sha256:'.hash('sha256', $text)], 'diagnostics' => []];
            $first = $store->activate('site:demo', 'page.home', 'r1', $compiled('one'), null, 'admin:1');
            $second = $store->activate('site:demo', 'page.home', 'r2', $compiled('two'), 1, 'admin:1');
            $page1 = $store->history('site:demo', 'page.home', 'admin:1', 1);
            assert(count($page1['snapshots']) === 1 && $page1['next_cursor'] !== null);
            $page2 = $store->history('site:demo', 'page.home', 'admin:1', 1, $page1['next_cursor']);
            assert(count($page2['snapshots']) === 1 && $page2['next_cursor'] === null);
            assert(strcmp($page1['snapshots'][0]['snapshot_digest'], $page2['snapshots'][0]['snapshot_digest']) < 0);
            $all = $store->history('site:demo', 'page.home', 'admin:1');
            assert(count(array_filter($all['snapshots'], static fn (array $row): bool => $row['active'])) === 1);
            foreach ($all['snapshots'] as $summary) {
                $wireSummary = json_decode(json_encode($summary, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($wireSummary)) {
                    throw new RuntimeException('Invalid catalog wire summary.');
                }
                assert(array_keys($wireSummary) === ['snapshot_digest', 'source_revision', 'active']);
                assert($summary['active'] === ($summary['snapshot_digest'] === $second->snapshotDigest));
            }
            assert($store->history('site:demo', 'page.other', 'admin:1')['snapshots'] === []);
            foreach ([['reader:1', 'site:demo', 20, null], ['admin:1', 'site:foreign', 20, null],
                ['admin:1', 'site:demo', 0, null], ['admin:1', 'site:demo', 101, null], ['admin:1', 'site:demo', 20, '../bad']] as [$actor, $scope, $limit, $cursor]) {
                try {
                    $store->history($scope, 'page.home', $actor, $limit, $cursor);
                    throw new RuntimeException('Invalid or unauthorized history was accepted.');
                } catch (LayoutRejected) {
                }
            }
            $store->rollback('site:demo', 'page.home', $first->snapshotDigest, 2, 'admin:1');
            $restarted = $backend === 'pdo' ? new PdoCompiledPageSnapshotStore($pdo, $policy) : new FileCompiledPageSnapshotStore($root, $policy);
            $history = $restarted->history('site:demo', 'page.home', 'admin:1');
            assert(count($history['snapshots']) === 2);
            foreach ($history['snapshots'] as $row) {
                assert($row['active'] === ($row['snapshot_digest'] === $first->snapshotDigest));
            }
            if ($backend === 'pdo') {
                $statement = $pdo->prepare('UPDATE larena_layout_compiled_snapshots SET snapshot_json = :bad WHERE snapshot_digest = :digest');
                $statement->execute(['bad' => '{}', 'digest' => $second->snapshotDigest]);
            } else {
                $file = $root.'/'.hash('sha256', 'site:demo').'/'.hash('sha256', 'page.home').'/snapshots/'.substr($second->snapshotDigest, 7).'.json';
                file_put_contents($file, '{}');
            }
            try {
                $store->history('site:demo', 'page.home', 'admin:1');
                throw new RuntimeException('Tampered historical snapshot was exposed.');
            } catch (LayoutRejected $exception) {
                assert($exception->reasonCode === 'layout_snapshot_integrity_failed');
            }
            assert($store->active('site:demo', 'page.home', 'admin:1')?->snapshotDigest === $first->snapshotDigest);
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
})();

echo "CompiledPageSnapshotCatalogTest passed.\n";
