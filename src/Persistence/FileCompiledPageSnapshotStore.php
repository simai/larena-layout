<?php

declare(strict_types=1);

namespace Larena\Layout\Persistence;

use JsonException;
use Larena\Layout\Contracts\CompiledPageSnapshotStore;
use Larena\Layout\Contracts\CompiledPageSnapshotCatalog;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\ValueObjects\CompiledPageSnapshot;

final readonly class FileCompiledPageSnapshotStore implements CompiledPageSnapshotStore, CompiledPageSnapshotCatalog
{
    private string $root;

    public function __construct(string $root, private PageDescriptorAuthorizationPolicy $authorization)
    {
        $real = realpath($root);
        if (! is_string($real) || ! is_dir($real) || is_link($root)) {
            throw new LayoutRejected('layout_snapshot_root_invalid');
        }
        $this->root = rtrim($real, DIRECTORY_SEPARATOR);
    }

    public function activate(
        string $scopeRef,
        string $pageId,
        string $sourceRevision,
        array $compiled,
        ?int $expectedActivationRevision,
        string $actor,
    ): CompiledPageSnapshot {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        $payload = $this->snapshotPayload($scopeRef, $pageId, $sourceRevision, $compiled);

        return $this->locked($scopeRef, $pageId, function (string $directory) use ($payload, $expectedActivationRevision, $actor): CompiledPageSnapshot {
            $current = $this->head($directory);
            $this->assertExpectedRevision($current, $expectedActivationRevision);
            $digest = 'sha256:'.hash('sha256', $this->encode($payload));
            $snapshot = $payload + ['snapshot_digest' => $digest];
            $this->putImmutable($directory.'/snapshots/'.$this->digestHex($digest).'.json', $this->encode($snapshot));

            return $this->activateSnapshot($directory, $snapshot, ($current['activation_revision'] ?? 0) + 1, $actor);
        });
    }

    public function active(string $scopeRef, string $pageId, string $actor): ?CompiledPageSnapshot
    {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);

        return $this->locked($scopeRef, $pageId, function (string $directory) use ($scopeRef, $pageId): ?CompiledPageSnapshot {
            $head = $this->head($directory);
            if ($head === null) {
                return null;
            }

            return $this->hydrate($directory, $scopeRef, $pageId, $head);
        }, false);
    }

    /**
     * @phpstan-impure
     * @return array{snapshots:list<array{snapshot_digest:string,source_revision:string,active:bool}>,next_cursor:?string}
     */
    public function history(string $scopeRef, string $pageId, string $actor, int $limit = 20, ?string $afterDigest = null): array
    {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        if ($limit < 1 || $limit > 100) {
            throw new LayoutRejected('layout_snapshot_history_limit_invalid');
        }
        if ($afterDigest !== null) {
            $this->digestHex($afterDigest);
        }
        return $this->locked($scopeRef, $pageId, function (string $directory) use ($scopeRef, $pageId, $limit, $afterDigest): array {
            $head = $this->head($directory);
            if ($head !== null) {
                $this->hydrate($directory, $scopeRef, $pageId, $head);
            }
            $paths = glob($directory.'/snapshots/*.json');
            if ($paths === false) {
                throw new LayoutRejected('layout_snapshot_storage_failed');
            }
            sort($paths, SORT_STRING);
            $summaries = [];
            foreach ($paths as $path) {
                $digest = 'sha256:'.basename($path, '.json');
                $this->digestHex($digest);
                if ($afterDigest !== null && strcmp($digest, $afterDigest) <= 0) {
                    continue;
                }
                $snapshot = $this->decodeFile($path, 'layout_snapshot_integrity_failed');
                $this->assertSnapshot($snapshot, $scopeRef, $pageId, $digest);
                if (! is_string($snapshot['source_revision'] ?? null)) {
                    throw new LayoutRejected('layout_snapshot_integrity_failed');
                }
                $summaries[] = ['snapshot_digest' => $digest, 'source_revision' => $snapshot['source_revision'], 'active' => ($head['snapshot_digest'] ?? null) === $digest];
                if (count($summaries) > $limit) {
                    break;
                }
            }
            $hasMore = count($summaries) > $limit;
            if ($hasMore) {
                array_pop($summaries);
            }
            return ['snapshots' => $summaries, 'next_cursor' => $hasMore ? $summaries[count($summaries) - 1]['snapshot_digest'] : null];
        }, false);
    }

    public function rollback(
        string $scopeRef,
        string $pageId,
        string $targetSnapshotDigest,
        int $expectedActivationRevision,
        string $actor,
    ): CompiledPageSnapshot {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        if ($expectedActivationRevision < 1) {
            throw new LayoutRejected('layout_snapshot_activation_revision_invalid');
        }
        $hex = $this->digestHex($targetSnapshotDigest);

        return $this->locked($scopeRef, $pageId, function (string $directory) use ($scopeRef, $pageId, $targetSnapshotDigest, $expectedActivationRevision, $actor, $hex): CompiledPageSnapshot {
            $current = $this->head($directory);
            $this->assertExpectedRevision($current, $expectedActivationRevision);
            $snapshot = $this->decodeFile($directory.'/snapshots/'.$hex.'.json', 'layout_snapshot_unknown');
            $this->assertSnapshot($snapshot, $scopeRef, $pageId, $targetSnapshotDigest);

            return $this->activateSnapshot($directory, $snapshot, $expectedActivationRevision + 1, $actor);
        });
    }

    /** @param array<string, mixed> $compiled @return array<string, mixed> */
    private function snapshotPayload(string $scopeRef, string $pageId, string $sourceRevision, array $compiled): array
    {
        if ($sourceRevision === '' || strlen($sourceRevision) > 200
            || ! is_array($compiled['document'] ?? null)
            || ! is_string($compiled['html'] ?? null)
            || ! is_array($compiled['dependencyReceipt'] ?? null)
            || ! is_string($compiled['dependencyReceipt']['documentDigest'] ?? null)
            || ($compiled['diagnostics'] ?? null) !== []
        ) {
            throw new LayoutRejected('layout_snapshot_compiled_result_invalid');
        }

        return [
            'schema' => 'larena.layout.compiled_page_snapshot.v1',
            'scope_ref' => $scopeRef,
            'page_id' => $pageId,
            'source_revision' => $sourceRevision,
            'document' => $compiled['document'],
            'html' => $compiled['html'],
            'dependency_receipt' => $compiled['dependencyReceipt'],
        ];
    }

    /** @param array<string, mixed>|null $current */
    private function assertExpectedRevision(?array $current, ?int $expected): void
    {
        $actual = $current['activation_revision'] ?? null;
        if (($actual === null && $expected !== null) || ($actual !== null && $expected !== $actual)) {
            throw new LayoutRejected('layout_snapshot_activation_conflict');
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function activateSnapshot(string $directory, array $snapshot, int $revision, string $actor): CompiledPageSnapshot
    {
        $head = [
            'schema' => 'larena.layout.compiled_page_activation.v1',
            'activation_revision' => $revision,
            'snapshot_digest' => $snapshot['snapshot_digest'],
            'activated_by' => $actor,
        ];
        $this->replace($directory.'/active.json', $this->encode($head));

        return new CompiledPageSnapshot(
            (string) $snapshot['scope_ref'],
            (string) $snapshot['page_id'],
            $revision,
            (string) $snapshot['snapshot_digest'],
            $snapshot,
            $actor,
        );
    }

    /** @param array<string, mixed> $head */
    private function hydrate(string $directory, string $scopeRef, string $pageId, array $head): CompiledPageSnapshot
    {
        if (($head['schema'] ?? null) !== 'larena.layout.compiled_page_activation.v1'
            || ! is_int($head['activation_revision'] ?? null)
            || ! is_string($head['snapshot_digest'] ?? null)
            || ! is_string($head['activated_by'] ?? null)
        ) {
            throw new LayoutRejected('layout_snapshot_head_invalid');
        }
        $snapshot = $this->decodeFile($directory.'/snapshots/'.$this->digestHex($head['snapshot_digest']).'.json', 'layout_snapshot_missing');
        $this->assertSnapshot($snapshot, $scopeRef, $pageId, $head['snapshot_digest']);

        return new CompiledPageSnapshot($scopeRef, $pageId, $head['activation_revision'], $head['snapshot_digest'], $snapshot, $head['activated_by']);
    }

    /** @param array<string, mixed> $snapshot */
    private function assertSnapshot(array $snapshot, string $scopeRef, string $pageId, string $digest): void
    {
        $stored = $snapshot['snapshot_digest'] ?? null;
        unset($snapshot['snapshot_digest']);
        if ($stored !== $digest || ($snapshot['schema'] ?? null) !== 'larena.layout.compiled_page_snapshot.v1'
            || ($snapshot['scope_ref'] ?? null) !== $scopeRef || ($snapshot['page_id'] ?? null) !== $pageId
            || ! hash_equals($digest, 'sha256:'.hash('sha256', $this->encode($snapshot)))) {
            throw new LayoutRejected('layout_snapshot_integrity_failed');
        }
    }

    /** @return array<string, mixed>|null */
    private function head(string $directory): ?array
    {
        $path = $directory.'/active.json';
        if (! is_file($path)) {
            return null;
        }
        $head = $this->decodeFile($path, 'layout_snapshot_head_invalid');
        if (($head['schema'] ?? null) !== 'larena.layout.compiled_page_activation.v1'
            || ! is_int($head['activation_revision'] ?? null)
            || $head['activation_revision'] < 1
            || ! is_string($head['snapshot_digest'] ?? null)
            || ! is_string($head['activated_by'] ?? null)
        ) {
            throw new LayoutRejected('layout_snapshot_head_invalid');
        }

        return $head;
    }

    /** @return array<string, mixed> */
    private function decodeFile(string $path, string $reason): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new LayoutRejected($reason);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LayoutRejected($reason);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new LayoutRejected($reason);
        }

        return $value;
    }

    /** @param callable(string):mixed $callback */
    private function locked(string $scopeRef, string $pageId, callable $callback, bool $exclusive = true): mixed
    {
        $directory = $this->pageDirectory($scopeRef, $pageId);
        $handle = fopen($directory.'/.lock', 'c+b');
        $stat = @lstat($directory.'/.lock');
        $regular = is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1;
        if ($handle === false || ! $regular || is_link($directory.'/.lock') || ! flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new LayoutRejected('layout_snapshot_lock_failed');
        }
        try {
            return $callback($directory);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function pageDirectory(string $scopeRef, string $pageId): string
    {
        $directory = $this->root.'/'.hash('sha256', $scopeRef).'/'.hash('sha256', $pageId);
        foreach ([dirname($directory), $directory, $directory.'/snapshots'] as $path) {
            if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
                throw new LayoutRejected('layout_snapshot_storage_failed');
            }
            if (is_link($path)) {
                throw new LayoutRejected('layout_snapshot_storage_unsafe');
            }
        }

        return $directory;
    }

    private function putImmutable(string $path, string $contents): void
    {
        if (is_file($path)) {
            if (is_link($path) || ! hash_equals((string) file_get_contents($path), $contents)) {
                throw new LayoutRejected('layout_snapshot_digest_collision');
            }
            return;
        }
        $this->writeTemporary($path, $contents, false);
    }

    private function replace(string $path, string $contents): void
    {
        $this->writeTemporary($path, $contents, true);
    }

    private function writeTemporary(string $path, string $contents, bool $replace): void
    {
        $temporary = dirname($path).'/.tmp-'.bin2hex(random_bytes(12));
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new LayoutRejected('layout_snapshot_storage_failed');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                throw new LayoutRejected('layout_snapshot_storage_failed');
            }
            if (function_exists('fsync')) {
                fsync($handle);
            }
        } finally {
            fclose($handle);
        }
        $published = $replace ? @rename($temporary, $path) : @link($temporary, $path);
        @unlink($temporary);
        if (! $published) {
            throw new LayoutRejected('layout_snapshot_storage_failed');
        }
    }

    private function digestHex(string $digest): string
    {
        if (preg_match('/^sha256:([a-f0-9]{64})$/D', $digest, $match) !== 1) {
            throw new LayoutRejected('layout_snapshot_digest_invalid');
        }

        return $match[1];
    }

    private function assertIdentity(string $scopeRef, string $pageId, string $actor): void
    {
        foreach ([$scopeRef, $pageId] as $identity) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $identity) !== 1) {
                throw new LayoutRejected('layout_snapshot_identity_invalid');
            }
        }
        if ($actor === '' || strlen($actor) > 160) {
            throw new LayoutRejected('layout_snapshot_actor_invalid');
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }

        return $value;
    }
}
