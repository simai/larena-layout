<?php

declare(strict_types=1);

namespace Larena\Layout\Persistence;

use JsonException;
use Larena\Layout\Contracts\PageDescriptorStore;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LegacyPageDescriptorAdapter;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageDescriptorNormalizer;
use Larena\Layout\ValueObjects\PageDescriptorRevision;
use PDO;
use Throwable;

final readonly class PdoPageDescriptorStore implements PageDescriptorStore
{
    public function __construct(
        private PDO $pdo,
        private PageDescriptorAuthorizationPolicy $authorization,
        private PageAssemblyDescriptorNormalizer $normalizer = new PageAssemblyDescriptorNormalizer(),
        private PageDescriptorNormalizer $legacyNormalizer = new PageDescriptorNormalizer(),
        private LegacyPageDescriptorAdapter $legacyAdapter = new LegacyPageDescriptorAdapter(),
    )
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_page_descriptors (scope_ref VARCHAR(128) NOT NULL, page_id VARCHAR(120) NOT NULL, current_revision INTEGER NOT NULL, current_json TEXT NOT NULL, semantic_hash CHAR(64) NOT NULL, updated_by VARCHAR(160) NOT NULL, PRIMARY KEY (scope_ref, page_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_page_descriptor_versions (scope_ref VARCHAR(128) NOT NULL, page_id VARCHAR(120) NOT NULL, revision INTEGER NOT NULL, document_json TEXT NOT NULL, semantic_hash CHAR(64) NOT NULL, changed_by VARCHAR(160) NOT NULL, PRIMARY KEY (scope_ref, page_id, revision))');
    }

    public function create(array $descriptor, string $actor): PageDescriptorRevision
    {
        return $this->persist($descriptor, null, $actor);
    }

    public function update(array $descriptor, int $expectedRevision, string $actor): PageDescriptorRevision
    {
        if ($expectedRevision < 1) {
            throw new LayoutRejected('layout_descriptor_revision_invalid');
        }
        return $this->persist($descriptor, $expectedRevision, $actor);
    }

    public function read(string $scopeRef, string $pageId, string $actor): ?PageDescriptorRevision
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT current_revision, current_json, semantic_hash FROM larena_layout_page_descriptors WHERE scope_ref = :scope AND page_id = :page');
            $statement->execute(['scope' => $scopeRef, 'page' => $pageId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                return null;
            }
            return $this->hydrate($scopeRef, $pageId, (int) $row['current_revision'], (string) $row['current_json'], (string) $row['semantic_hash']);
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_descriptor_persistence_failed');
        }
    }

    public function history(string $scopeRef, string $pageId, string $actor): array
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT revision, document_json, semantic_hash FROM larena_layout_page_descriptor_versions WHERE scope_ref = :scope AND page_id = :page ORDER BY revision DESC');
            $statement->execute(['scope' => $scopeRef, 'page' => $pageId]);
            $history = [];
            while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                $history[] = $this->hydrate($scopeRef, $pageId, (int) $row['revision'], (string) $row['document_json'], (string) $row['semantic_hash']);
            }
            return $history;
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_descriptor_persistence_failed');
        }
    }

    public function rollback(string $scopeRef, string $pageId, int $targetRevision, int $expectedRevision, string $actor): PageDescriptorRevision
    {
        if ($targetRevision < 1 || $expectedRevision < 1) {
            throw new LayoutRejected('layout_descriptor_revision_invalid');
        }
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT document_json, semantic_hash FROM larena_layout_page_descriptor_versions WHERE scope_ref = :scope AND page_id = :page AND revision = :revision');
            $statement->execute(['scope' => $scopeRef, 'page' => $pageId, 'revision' => $targetRevision]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new LayoutRejected('layout_descriptor_revision_unknown');
            }
            $target = $this->hydrate($scopeRef, $pageId, $targetRevision, (string) $row['document_json'], (string) $row['semantic_hash']);
            return $this->persist($target->descriptor, $expectedRevision, $actor);
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_descriptor_persistence_failed');
        }
    }

    /** @param array<string, mixed> $descriptor */
    private function persist(array $descriptor, ?int $expectedRevision, string $actor): PageDescriptorRevision
    {
        $descriptor = $this->normalize($descriptor);
        $scope = (string) $descriptor['scope_ref'];
        $this->authorization->assertAllowed(
            $actor,
            $expectedRevision === null ? PageDescriptorAuthorizationPolicy::CREATE : PageDescriptorAuthorizationPolicy::UPDATE,
            $scope,
        );
        $page = (string) $descriptor['page_id'];
        $json = $this->normalizer->encode($descriptor);
        $hash = $this->normalizer->hash($descriptor);
        $nestedTransaction = $this->pdo->inTransaction();
        if ($nestedTransaction) {
            $this->pdo->exec('SAVEPOINT larena_layout_descriptor_write');
        } else {
            $this->pdo->beginTransaction();
        }
        try {
            $query = $this->pdo->prepare('SELECT current_revision FROM larena_layout_page_descriptors WHERE scope_ref = :scope AND page_id = :page');
            $query->execute(['scope' => $scope, 'page' => $page]);
            $current = $query->fetchColumn();
            if ($expectedRevision === null && $current !== false) {
                throw new LayoutRejected('layout_descriptor_already_exists');
            }
            if ($expectedRevision !== null && ($current === false || (int) $current !== $expectedRevision)) {
                throw new LayoutRejected('layout_descriptor_revision_conflict');
            }
            $revision = $expectedRevision === null ? 1 : $expectedRevision + 1;
            if ($expectedRevision === null) {
                $head = $this->pdo->prepare('INSERT INTO larena_layout_page_descriptors (scope_ref, page_id, current_revision, current_json, semantic_hash, updated_by) VALUES (:scope, :page, :revision, :json, :hash, :actor)');
            } else {
                $head = $this->pdo->prepare('UPDATE larena_layout_page_descriptors SET current_revision = :revision, current_json = :json, semantic_hash = :hash, updated_by = :actor WHERE scope_ref = :scope AND page_id = :page AND current_revision = :expected');
            }
            $values = ['scope' => $scope, 'page' => $page, 'revision' => $revision, 'json' => $json, 'hash' => $hash, 'actor' => $actor];
            if ($expectedRevision !== null) {
                $values['expected'] = $expectedRevision;
            }
            $head->execute($values);
            if ($head->rowCount() !== 1) {
                throw new LayoutRejected('layout_descriptor_revision_conflict');
            }
            $version = $this->pdo->prepare('INSERT INTO larena_layout_page_descriptor_versions (scope_ref, page_id, revision, document_json, semantic_hash, changed_by) VALUES (:scope, :page, :revision, :json, :hash, :actor)');
            $version->execute(['scope' => $scope, 'page' => $page, 'revision' => $revision, 'json' => $json, 'hash' => $hash, 'actor' => $actor]);
            if ($nestedTransaction) {
                $this->pdo->exec('RELEASE SAVEPOINT larena_layout_descriptor_write');
            } else {
                $this->pdo->commit();
            }
            return new PageDescriptorRevision($page, $scope, $revision, $descriptor, $hash);
        } catch (LayoutRejected $exception) {
            $this->rollbackWrite($nestedTransaction);
            throw $exception;
        } catch (Throwable) {
            $this->rollbackWrite($nestedTransaction);
            throw new LayoutRejected('layout_descriptor_persistence_failed');
        }
    }

    private function rollbackWrite(bool $nestedTransaction): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }
        if ($nestedTransaction) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT larena_layout_descriptor_write');
            $this->pdo->exec('RELEASE SAVEPOINT larena_layout_descriptor_write');
            return;
        }
        $this->pdo->rollBack();
    }

    private function hydrate(string $scope, string $page, int $revision, string $json, string $hash): PageDescriptorRevision
    {
        try {
            $descriptor = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LayoutRejected('layout_descriptor_persisted_json_invalid');
        }
        if (!is_array($descriptor) || array_is_list($descriptor)) {
            throw new LayoutRejected('layout_descriptor_persisted_json_invalid');
        }
        $legacy = ($descriptor['schema'] ?? null) === PageDescriptorNormalizer::SCHEMA;
        if ($legacy) {
            $legacyDescriptor = $this->legacyNormalizer->normalize($descriptor);
            if (!hash_equals($this->legacyNormalizer->hash($legacyDescriptor), $hash)) {
                throw new LayoutRejected('layout_descriptor_persisted_integrity_failed');
            }
            $descriptor = $this->legacyAdapter->adapt($legacyDescriptor, 'site.legacy');
        } else {
            $descriptor = $this->normalizer->normalize($descriptor);
            if (!hash_equals($this->normalizer->hash($descriptor), $hash)) {
                throw new LayoutRejected('layout_descriptor_persisted_integrity_failed');
            }
        }
        if ($descriptor['scope_ref'] !== $scope || $descriptor['page_id'] !== $page) {
            throw new LayoutRejected('layout_descriptor_persisted_integrity_failed');
        }
        return new PageDescriptorRevision($page, $scope, $revision, $descriptor, $this->normalizer->hash($descriptor));
    }

    /** @param array<string, mixed> $descriptor @return array<string, mixed> */
    private function normalize(array $descriptor): array
    {
        if (($descriptor['schema'] ?? null) === PageDescriptorNormalizer::SCHEMA) {
            return $this->legacyAdapter->adapt($descriptor, 'site.legacy');
        }

        return $this->normalizer->normalize($descriptor);
    }

}
