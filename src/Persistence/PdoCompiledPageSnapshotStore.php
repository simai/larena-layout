<?php

declare(strict_types=1);

namespace Larena\Layout\Persistence;

use JsonException;
use Larena\Layout\Contracts\CompiledPageSnapshotStore;
use Larena\Layout\Contracts\CompiledPageSnapshotCatalog;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\ValueObjects\CompiledPageSnapshot;
use PDO;
use Throwable;

final readonly class PdoCompiledPageSnapshotStore implements CompiledPageSnapshotStore, CompiledPageSnapshotCatalog
{
    public function __construct(private PDO $pdo, private PageDescriptorAuthorizationPolicy $authorization)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_compiled_snapshots (scope_ref VARCHAR(128) NOT NULL, page_id VARCHAR(120) NOT NULL, snapshot_digest VARCHAR(71) NOT NULL, source_revision VARCHAR(200) NOT NULL, snapshot_json TEXT NOT NULL, created_by VARCHAR(160) NOT NULL, PRIMARY KEY (scope_ref, page_id, snapshot_digest))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_active_snapshots (scope_ref VARCHAR(128) NOT NULL, page_id VARCHAR(120) NOT NULL, activation_revision INTEGER NOT NULL, snapshot_digest VARCHAR(71) NOT NULL, activated_by VARCHAR(160) NOT NULL, PRIMARY KEY (scope_ref, page_id))');
    }

    public function uninstall(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS larena_layout_active_snapshots');
        $this->pdo->exec('DROP TABLE IF EXISTS larena_layout_compiled_snapshots');
    }

    public function activate(string $scopeRef, string $pageId, string $sourceRevision, array $compiled, ?int $expectedActivationRevision, string $actor): CompiledPageSnapshot
    {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        $payload = $this->snapshotPayload($scopeRef, $pageId, $sourceRevision, $compiled);
        $digest = 'sha256:'.hash('sha256', $this->encode($payload));
        $snapshot = $payload + ['snapshot_digest' => $digest];

        return $this->transaction(function () use ($scopeRef, $pageId, $expectedActivationRevision, $actor, $digest, $snapshot, $sourceRevision): CompiledPageSnapshot {
            $current = $this->head($scopeRef, $pageId);
            $this->assertExpectedRevision($current, $expectedActivationRevision);
            $encoded = $this->encode($snapshot);
            $existing = $this->snapshotRow($scopeRef, $pageId, $digest);
            if ($existing === null) {
                $statement = $this->pdo->prepare('INSERT INTO larena_layout_compiled_snapshots (scope_ref, page_id, snapshot_digest, source_revision, snapshot_json, created_by) VALUES (:scope, :page, :digest, :source, :snapshot, :actor)');
                $statement->execute(['scope' => $scopeRef, 'page' => $pageId, 'digest' => $digest, 'source' => $sourceRevision, 'snapshot' => $encoded, 'actor' => $actor]);
            } elseif (!hash_equals((string) $existing['snapshot_json'], $encoded)) {
                throw new LayoutRejected('layout_snapshot_digest_collision');
            }

            $revision = ($current['activation_revision'] ?? 0) + 1;
            if ($current === null) {
                $statement = $this->pdo->prepare('INSERT INTO larena_layout_active_snapshots (scope_ref, page_id, activation_revision, snapshot_digest, activated_by) VALUES (:scope, :page, :revision, :digest, :actor)');
                $statement->execute(['scope' => $scopeRef, 'page' => $pageId, 'revision' => $revision, 'digest' => $digest, 'actor' => $actor]);
            } else {
                $statement = $this->pdo->prepare('UPDATE larena_layout_active_snapshots SET activation_revision = :revision, snapshot_digest = :digest, activated_by = :actor WHERE scope_ref = :scope AND page_id = :page AND activation_revision = :expected');
                $statement->execute(['revision' => $revision, 'digest' => $digest, 'actor' => $actor, 'scope' => $scopeRef, 'page' => $pageId, 'expected' => $current['activation_revision']]);
                if ($statement->rowCount() !== 1) {
                    throw new LayoutRejected('layout_snapshot_activation_conflict');
                }
            }

            return new CompiledPageSnapshot($scopeRef, $pageId, $revision, $digest, $snapshot, $actor);
        });
    }

    /** @phpstan-impure */
    public function active(string $scopeRef, string $pageId, string $actor): ?CompiledPageSnapshot
    {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $head = $this->head($scopeRef, $pageId);
            if ($head === null) {
                return null;
            }
            return $this->hydrate($scopeRef, $pageId, $head);
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_snapshot_persistence_failed');
        }
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
            $this->assertDigest($afterDigest);
        }
        return $this->transaction(function () use ($scopeRef, $pageId, $limit, $afterDigest): array {
            $head = $this->head($scopeRef, $pageId);
            if ($head !== null) {
                $this->hydrate($scopeRef, $pageId, $head);
            }
            $statement = $this->pdo->prepare('SELECT snapshot_digest, source_revision, snapshot_json FROM larena_layout_compiled_snapshots WHERE scope_ref = :scope AND page_id = :page AND snapshot_digest > :after ORDER BY snapshot_digest ASC LIMIT :limit');
            $statement->bindValue(':scope', $scopeRef);
            $statement->bindValue(':page', $pageId);
            $statement->bindValue(':after', $afterDigest ?? '');
            $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
            $statement->execute();
            $summaries = [];
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $digest = (string) $row['snapshot_digest'];
                $snapshot = $this->decode((string) $row['snapshot_json'], 'layout_snapshot_integrity_failed');
                $this->assertSnapshot($snapshot, $scopeRef, $pageId, $digest);
                if (! is_string($snapshot['source_revision'] ?? null) || $snapshot['source_revision'] !== $row['source_revision']) {
                    throw new LayoutRejected('layout_snapshot_integrity_failed');
                }
                $summaries[] = ['snapshot_digest' => $digest, 'source_revision' => $snapshot['source_revision'], 'active' => ($head['snapshot_digest'] ?? null) === $digest];
            }
            $hasMore = count($summaries) > $limit;
            if ($hasMore) {
                array_pop($summaries);
            }
            return ['snapshots' => $summaries, 'next_cursor' => $hasMore ? $summaries[count($summaries) - 1]['snapshot_digest'] : null];
        });
    }

    public function rollback(string $scopeRef, string $pageId, string $targetSnapshotDigest, int $expectedActivationRevision, string $actor): CompiledPageSnapshot
    {
        $this->assertIdentity($scopeRef, $pageId, $actor);
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        $this->assertDigest($targetSnapshotDigest);
        if ($expectedActivationRevision < 1) {
            throw new LayoutRejected('layout_snapshot_activation_revision_invalid');
        }

        return $this->transaction(function () use ($scopeRef, $pageId, $targetSnapshotDigest, $expectedActivationRevision, $actor): CompiledPageSnapshot {
            $current = $this->head($scopeRef, $pageId);
            $this->assertExpectedRevision($current, $expectedActivationRevision);
            $row = $this->snapshotRow($scopeRef, $pageId, $targetSnapshotDigest);
            if ($row === null) {
                throw new LayoutRejected('layout_snapshot_unknown');
            }
            $snapshot = $this->decode((string) $row['snapshot_json'], 'layout_snapshot_integrity_failed');
            $this->assertSnapshot($snapshot, $scopeRef, $pageId, $targetSnapshotDigest);
            $revision = $expectedActivationRevision + 1;
            $statement = $this->pdo->prepare('UPDATE larena_layout_active_snapshots SET activation_revision = :revision, snapshot_digest = :digest, activated_by = :actor WHERE scope_ref = :scope AND page_id = :page AND activation_revision = :expected');
            $statement->execute(['revision' => $revision, 'digest' => $targetSnapshotDigest, 'actor' => $actor, 'scope' => $scopeRef, 'page' => $pageId, 'expected' => $expectedActivationRevision]);
            if ($statement->rowCount() !== 1) {
                throw new LayoutRejected('layout_snapshot_activation_conflict');
            }
            return new CompiledPageSnapshot($scopeRef, $pageId, $revision, $targetSnapshotDigest, $snapshot, $actor);
        });
    }

    /** @param array<string,mixed> $compiled @return array<string,mixed> */
    private function snapshotPayload(string $scopeRef, string $pageId, string $sourceRevision, array $compiled): array
    {
        if ($sourceRevision === '' || strlen($sourceRevision) > 200
            || !is_array($compiled['document'] ?? null) || !is_string($compiled['html'] ?? null)
            || !is_array($compiled['dependencyReceipt'] ?? null)
            || !is_string($compiled['dependencyReceipt']['documentDigest'] ?? null)
            || ($compiled['diagnostics'] ?? null) !== []) {
            throw new LayoutRejected('layout_snapshot_compiled_result_invalid');
        }
        return ['schema' => 'larena.layout.compiled_page_snapshot.v1', 'scope_ref' => $scopeRef, 'page_id' => $pageId,
            'source_revision' => $sourceRevision, 'document' => $compiled['document'], 'html' => $compiled['html'],
            'dependency_receipt' => $compiled['dependencyReceipt']];
    }

    /** @return array<string,mixed>|null */
    private function head(string $scopeRef, string $pageId): ?array
    {
        $statement = $this->pdo->prepare('SELECT activation_revision, snapshot_digest, activated_by FROM larena_layout_active_snapshots WHERE scope_ref = :scope AND page_id = :page');
        $statement->execute(['scope' => $scopeRef, 'page' => $pageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? ['activation_revision' => (int) $row['activation_revision'], 'snapshot_digest' => (string) $row['snapshot_digest'], 'activated_by' => (string) $row['activated_by']] : null;
    }

    /** @return array<string,mixed>|null */
    private function snapshotRow(string $scopeRef, string $pageId, string $digest): ?array
    {
        $statement = $this->pdo->prepare('SELECT snapshot_json, created_by FROM larena_layout_compiled_snapshots WHERE scope_ref = :scope AND page_id = :page AND snapshot_digest = :digest');
        $statement->execute(['scope' => $scopeRef, 'page' => $pageId, 'digest' => $digest]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $head */
    private function hydrate(string $scopeRef, string $pageId, array $head): CompiledPageSnapshot
    {
        $row = $this->snapshotRow($scopeRef, $pageId, (string) $head['snapshot_digest']);
        if ($row === null) {
            throw new LayoutRejected('layout_snapshot_missing');
        }
        $snapshot = $this->decode((string) $row['snapshot_json'], 'layout_snapshot_integrity_failed');
        $this->assertSnapshot($snapshot, $scopeRef, $pageId, (string) $head['snapshot_digest']);
        return new CompiledPageSnapshot($scopeRef, $pageId, (int) $head['activation_revision'], (string) $head['snapshot_digest'], $snapshot, (string) $head['activated_by']);
    }

    /** @param array<string,mixed>|null $current */
    private function assertExpectedRevision(?array $current, ?int $expected): void
    {
        $actual = $current['activation_revision'] ?? null;
        if (($actual === null && $expected !== null) || ($actual !== null && $expected !== $actual)) {
            throw new LayoutRejected('layout_snapshot_activation_conflict');
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(array $snapshot, string $scopeRef, string $pageId, string $digest): void
    {
        $stored = $snapshot['snapshot_digest'] ?? null;
        unset($snapshot['snapshot_digest']);
        if ($stored !== $digest || ($snapshot['schema'] ?? null) !== 'larena.layout.compiled_page_snapshot.v1'
            || ($snapshot['scope_ref'] ?? null) !== $scopeRef || ($snapshot['page_id'] ?? null) !== $pageId
            || !hash_equals($digest, 'sha256:'.hash('sha256', $this->encode($snapshot)))) {
            throw new LayoutRejected('layout_snapshot_integrity_failed');
        }
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

    private function assertDigest(string $digest): void
    {
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new LayoutRejected('layout_snapshot_digest_invalid');
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $json, string $reason): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LayoutRejected($reason);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new LayoutRejected($reason);
        }
        return $value;
    }

    private function encode(mixed $value): string
    {
        return json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }
        return $value;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        $owner = !$this->pdo->inTransaction();
        try {
            if ($owner) {
                $this->pdo->beginTransaction();
            }
            $result = $callback();
            if ($owner) {
                $this->pdo->commit();
            }
            return $result;
        } catch (LayoutRejected $exception) {
            if ($owner) {
                $this->rollbackOwnedTransaction();
            }
            throw $exception;
        } catch (Throwable) {
            if ($owner) {
                $this->rollbackOwnedTransaction();
            }
            throw new LayoutRejected('layout_snapshot_persistence_failed');
        }
    }

    private function rollbackOwnedTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
