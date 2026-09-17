<?php

declare(strict_types=1);

namespace Larena\Layout\Persistence;

use JsonException;
use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\Contracts\LayoutArtifactStore;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LayoutArtifactNormalizer;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;
use PDO;
use Throwable;

final readonly class PdoLayoutArtifactStore implements LayoutArtifactStore
{
    public function __construct(
        private PDO $pdo,
        private PageDescriptorAuthorizationPolicy $authorization,
        private LayoutArtifactNormalizer $normalizer = new LayoutArtifactNormalizer(),
        private ?LayoutArtifactCatalog $fallback = null,
    ) {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_artifacts (scope_ref VARCHAR(128) NOT NULL, artifact_id VARCHAR(120) NOT NULL, kind VARCHAR(16) NOT NULL, current_revision INTEGER NOT NULL, published_revision INTEGER NULL, current_json TEXT NOT NULL, semantic_hash CHAR(64) NOT NULL, updated_by VARCHAR(160) NOT NULL, PRIMARY KEY (scope_ref, artifact_id))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_artifact_versions (scope_ref VARCHAR(128) NOT NULL, artifact_id VARCHAR(120) NOT NULL, revision INTEGER NOT NULL, kind VARCHAR(16) NOT NULL, document_json TEXT NOT NULL, semantic_hash CHAR(64) NOT NULL, changed_by VARCHAR(160) NOT NULL, PRIMARY KEY (scope_ref, artifact_id, revision))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS larena_layout_artifact_links (scope_ref VARCHAR(128) NOT NULL, parent_artifact_id VARCHAR(120) NOT NULL, parent_revision INTEGER NOT NULL, instance_id VARCHAR(120) NOT NULL, child_artifact_id VARCHAR(120) NOT NULL, child_revision INTEGER NOT NULL, slot_name VARCHAR(80) NOT NULL, sort_order INTEGER NOT NULL, enabled INTEGER NOT NULL, PRIMARY KEY (scope_ref, parent_artifact_id, parent_revision, instance_id))');
    }

    public function uninstall(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS larena_layout_artifact_links');
        $this->pdo->exec('DROP TABLE IF EXISTS larena_layout_artifact_versions');
        $this->pdo->exec('DROP TABLE IF EXISTS larena_layout_artifacts');
    }

    public function create(array $artifact, string $actor): LayoutArtifactRevision
    {
        return $this->persist($artifact, null, $actor);
    }

    public function update(array $artifact, int $expectedRevision, string $actor): LayoutArtifactRevision
    {
        if ($expectedRevision < 1) {
            throw new LayoutRejected('layout_artifact_revision_invalid');
        }
        return $this->persist($artifact, $expectedRevision, $actor);
    }

    public function read(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT kind, current_revision, published_revision, current_json, semantic_hash FROM larena_layout_artifacts WHERE scope_ref = :scope AND artifact_id = :artifact');
            $statement->execute(['scope' => $scopeRef, 'artifact' => $artifactId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->hydrateHead($scopeRef, $artifactId, $row) : null;
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function readRevision(string $scopeRef, string $artifactId, int $revision, string $actor): ?LayoutArtifactRevision
    {
        if ($revision < 1) {
            throw new LayoutRejected('layout_artifact_revision_invalid');
        }
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT v.kind, v.revision, v.document_json, v.semantic_hash, h.published_revision FROM larena_layout_artifact_versions v JOIN larena_layout_artifacts h ON h.scope_ref = v.scope_ref AND h.artifact_id = v.artifact_id WHERE v.scope_ref = :scope AND v.artifact_id = :artifact AND v.revision = :revision');
            $statement->execute(['scope' => $scopeRef, 'artifact' => $artifactId, 'revision' => $revision]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->hydrateVersion($scopeRef, $artifactId, $row) : null;
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function published(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT v.kind, v.revision, v.document_json, v.semantic_hash, h.published_revision FROM larena_layout_artifacts h JOIN larena_layout_artifact_versions v ON v.scope_ref = h.scope_ref AND v.artifact_id = h.artifact_id AND v.revision = h.published_revision WHERE h.scope_ref = :scope AND h.artifact_id = :artifact');
            $statement->execute(['scope' => $scopeRef, 'artifact' => $artifactId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $this->hydrateVersion($scopeRef, $artifactId, $row) : null;
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function history(string $scopeRef, string $artifactId, string $actor): array
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT v.kind, v.revision, v.document_json, v.semantic_hash, h.published_revision FROM larena_layout_artifact_versions v JOIN larena_layout_artifacts h ON h.scope_ref = v.scope_ref AND h.artifact_id = v.artifact_id WHERE v.scope_ref = :scope AND v.artifact_id = :artifact ORDER BY v.revision DESC');
            $statement->execute(['scope' => $scopeRef, 'artifact' => $artifactId]);
            $result = [];
            while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                $result[] = $this->hydrateVersion($scopeRef, $artifactId, $row);
            }
            return $result;
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function search(string $scopeRef, string $actor, ?string $kind = null, ?string $parentId = null, ?string $childId = null): array
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        if ($kind !== null && !in_array($kind, ['page', 'section', 'block'], true)) {
            throw new LayoutRejected('layout_artifact_kind_invalid');
        }
        try {
            $where = ['h.scope_ref = :scope'];
            $values = ['scope' => $scopeRef];
            if ($kind !== null) {
                $where[] = 'h.kind = :kind';
                $values['kind'] = $kind;
            }
            if ($parentId !== null) {
                $where[] = 'EXISTS (SELECT 1 FROM larena_layout_artifact_links l WHERE l.scope_ref = h.scope_ref AND l.parent_artifact_id = :parent AND l.parent_revision = (SELECT current_revision FROM larena_layout_artifacts p WHERE p.scope_ref = h.scope_ref AND p.artifact_id = l.parent_artifact_id) AND l.child_artifact_id = h.artifact_id)';
                $values['parent'] = $parentId;
            }
            if ($childId !== null) {
                $where[] = 'EXISTS (SELECT 1 FROM larena_layout_artifact_links l WHERE l.scope_ref = h.scope_ref AND l.parent_artifact_id = h.artifact_id AND l.parent_revision = h.current_revision AND l.child_artifact_id = :child)';
                $values['child'] = $childId;
            }
            $statement = $this->pdo->prepare('SELECT h.artifact_id, h.kind, h.current_revision, h.published_revision, h.current_json, h.semantic_hash FROM larena_layout_artifacts h WHERE ' . implode(' AND ', $where) . ' ORDER BY h.kind, h.artifact_id');
            $statement->execute($values);
            $result = [];
            while (is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                $result[] = $this->hydrateHead($scopeRef, (string) $row['artifact_id'], $row);
            }
            return $result;
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function parents(string $scopeRef, string $artifactId, string $actor): array
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT l.parent_artifact_id, l.parent_revision, l.instance_id, l.slot_name, l.sort_order, l.enabled, l.child_revision FROM larena_layout_artifact_links l JOIN larena_layout_artifacts h ON h.scope_ref = l.scope_ref AND h.artifact_id = l.parent_artifact_id AND h.current_revision = l.parent_revision WHERE l.scope_ref = :scope AND l.child_artifact_id = :artifact ORDER BY l.parent_artifact_id, l.sort_order, l.instance_id');
            $statement->execute(['scope' => $scopeRef, 'artifact' => $artifactId]);
            return array_map(static fn (array $row): array => [
                'parent_artifact_id' => (string) $row['parent_artifact_id'], 'parent_revision' => (int) $row['parent_revision'],
                'instance_id' => (string) $row['instance_id'], 'slot' => (string) $row['slot_name'], 'sort' => (int) $row['sort_order'],
                'enabled' => (bool) $row['enabled'], 'child_revision' => (int) $row['child_revision'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function children(string $scopeRef, string $artifactId, string $actor): array
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
        try {
            $statement = $this->pdo->prepare('SELECT l.child_artifact_id, l.child_revision, l.instance_id, l.slot_name, l.sort_order, l.enabled FROM larena_layout_artifact_links l JOIN larena_layout_artifacts h ON h.scope_ref = l.scope_ref AND h.artifact_id = l.parent_artifact_id AND h.current_revision = l.parent_revision WHERE l.scope_ref = :scope AND l.parent_artifact_id = :artifact ORDER BY l.sort_order, l.instance_id');
            $statement->execute(['scope' => $scopeRef, 'artifact' => $artifactId]);
            return array_map(static fn (array $row): array => [
                'child_artifact_id' => (string) $row['child_artifact_id'], 'child_revision' => (int) $row['child_revision'],
                'instance_id' => (string) $row['instance_id'], 'slot' => (string) $row['slot_name'], 'sort' => (int) $row['sort_order'],
                'enabled' => (bool) $row['enabled'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    public function publish(string $scopeRef, string $artifactId, int $revision, int $expectedCurrentRevision, string $actor): LayoutArtifactRevision
    {
        if ($revision < 1 || $expectedCurrentRevision < 1) {
            throw new LayoutRejected('layout_artifact_revision_invalid');
        }
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        $ownsTransaction = false;
        try {
            $ownsTransaction = $this->begin();
            $version = $this->versionRow($scopeRef, $artifactId, $revision);
            if ($version === null) {
                throw new LayoutRejected('layout_artifact_revision_unknown');
            }
            $statement = $this->pdo->prepare('UPDATE larena_layout_artifacts SET published_revision = :revision, updated_by = :actor WHERE scope_ref = :scope AND artifact_id = :artifact AND current_revision = :expected');
            $statement->execute(['revision' => $revision, 'actor' => $actor, 'scope' => $scopeRef, 'artifact' => $artifactId, 'expected' => $expectedCurrentRevision]);
            if ($statement->rowCount() !== 1) {
                $head = $this->pdo->prepare('SELECT current_revision, published_revision FROM larena_layout_artifacts WHERE scope_ref = :scope AND artifact_id = :artifact');
                $head->execute(['scope' => $scopeRef, 'artifact' => $artifactId]);
                $state = $head->fetch(PDO::FETCH_ASSOC);
                if (!is_array($state) || (int) $state['current_revision'] !== $expectedCurrentRevision || (int) ($state['published_revision'] ?? 0) !== $revision) {
                    throw new LayoutRejected('layout_artifact_revision_conflict');
                }
            }
            $this->commit($ownsTransaction);
            $version['published_revision'] = $revision;
            return $this->hydrateVersion($scopeRef, $artifactId, $version);
        } catch (LayoutRejected $exception) {
            $this->rollback($ownsTransaction);
            throw $exception;
        } catch (Throwable) {
            $this->rollback($ownsTransaction);
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    /**
     * Execute one product publication unit on the artifact connection.
     * Store operations called inside the callback join this transaction.
     *
     * @phpstan-impure
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $ownsTransaction = false;
        try {
            $ownsTransaction = $this->begin();
            $result = $operation();
            $this->commit($ownsTransaction);

            return $result;
        } catch (Throwable $exception) {
            $this->rollback($ownsTransaction);
            throw $exception;
        }
    }

    public function restore(string $scopeRef, string $artifactId, int $targetRevision, int $expectedCurrentRevision, string $actor): LayoutArtifactRevision
    {
        if ($targetRevision < 1 || $expectedCurrentRevision < 1) {
            throw new LayoutRejected('layout_artifact_revision_invalid');
        }
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::UPDATE, $scopeRef);
        try {
            $row = $this->versionRow($scopeRef, $artifactId, $targetRevision);
            if ($row === null) {
                throw new LayoutRejected('layout_artifact_revision_unknown');
            }
            $artifact = $this->decode((string) $row['document_json']);
            return $this->persist($artifact, $expectedCurrentRevision, $actor);
        } catch (LayoutRejected $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    /** @param array<string, mixed> $artifact */
    private function persist(array $artifact, ?int $expectedRevision, string $actor): LayoutArtifactRevision
    {
        $artifact = $this->normalizer->normalize($artifact);
        $scope = (string) $artifact['scope_ref'];
        $id = (string) $artifact['artifact_id'];
        $this->authorization->assertAllowed($actor, $expectedRevision === null ? PageDescriptorAuthorizationPolicy::CREATE : PageDescriptorAuthorizationPolicy::UPDATE, $scope);
        $json = $this->normalizer->encode($artifact);
        $hash = $this->normalizer->hash($artifact);
        $ownsTransaction = false;
        try {
            $ownsTransaction = $this->begin();
            $query = $this->pdo->prepare('SELECT current_revision FROM larena_layout_artifacts WHERE scope_ref = :scope AND artifact_id = :artifact');
            $query->execute(['scope' => $scope, 'artifact' => $id]);
            $current = $query->fetchColumn();
            if ($expectedRevision === null && $current !== false) {
                throw new LayoutRejected('layout_artifact_already_exists');
            }
            if ($expectedRevision !== null && ($current === false || (int) $current !== $expectedRevision)) {
                throw new LayoutRejected('layout_artifact_revision_conflict');
            }
            $revision = $expectedRevision === null ? 1 : $expectedRevision + 1;
            foreach ($this->fallback?->history($scope, $id, $actor) ?? [] as $systemRevision) {
                $revision = max($revision, $systemRevision->revision + 1);
            }
            $resolvedPlacements = $this->resolvePlacements($scope, $artifact['placements'], $actor);
            if ($expectedRevision === null) {
                $head = $this->pdo->prepare('INSERT INTO larena_layout_artifacts (scope_ref, artifact_id, kind, current_revision, published_revision, current_json, semantic_hash, updated_by) VALUES (:scope, :artifact, :kind, :revision, NULL, :json, :hash, :actor)');
            } else {
                $head = $this->pdo->prepare('UPDATE larena_layout_artifacts SET kind = :kind, current_revision = :revision, current_json = :json, semantic_hash = :hash, updated_by = :actor WHERE scope_ref = :scope AND artifact_id = :artifact AND current_revision = :expected');
            }
            $values = ['scope' => $scope, 'artifact' => $id, 'kind' => $artifact['kind'], 'revision' => $revision, 'json' => $json, 'hash' => $hash, 'actor' => $actor];
            if ($expectedRevision !== null) {
                $values['expected'] = $expectedRevision;
            }
            $head->execute($values);
            if ($head->rowCount() !== 1) {
                throw new LayoutRejected('layout_artifact_revision_conflict');
            }
            $version = $this->pdo->prepare('INSERT INTO larena_layout_artifact_versions (scope_ref, artifact_id, revision, kind, document_json, semantic_hash, changed_by) VALUES (:scope, :artifact, :revision, :kind, :json, :hash, :actor)');
            $version->execute(['scope' => $scope, 'artifact' => $id, 'revision' => $revision, 'kind' => $artifact['kind'], 'json' => $json, 'hash' => $hash, 'actor' => $actor]);
            $insert = $this->pdo->prepare('INSERT INTO larena_layout_artifact_links (scope_ref, parent_artifact_id, parent_revision, instance_id, child_artifact_id, child_revision, slot_name, sort_order, enabled) VALUES (:scope, :parent, :parent_revision, :instance, :child, :child_revision, :slot, :sort, :enabled)');
            foreach ($resolvedPlacements as $placement) {
                $insert->execute(['scope' => $scope, 'parent' => $id, 'parent_revision' => $revision, 'instance' => $placement['instance_id'], 'child' => $placement['artifact_ref'], 'child_revision' => $placement['child_revision'], 'slot' => $placement['slot'], 'sort' => $placement['sort'], 'enabled' => $placement['enabled'] ? 1 : 0]);
            }
            $this->assertAcyclic($scope, $id, $revision, [], 0, $actor);
            $this->commit($ownsTransaction);
            return new LayoutArtifactRevision($id, $scope, (string) $artifact['kind'], $revision, $artifact, $hash, false);
        } catch (LayoutRejected $exception) {
            $this->rollback($ownsTransaction);
            throw $exception;
        } catch (Throwable) {
            $this->rollback($ownsTransaction);
            throw new LayoutRejected('layout_artifact_persistence_failed');
        }
    }

    /** @param list<array<string, mixed>> $placements @return list<array<string, mixed>> */
    private function resolvePlacements(string $scope, array $placements, string $actor): array
    {
        $resolved = [];
        foreach ($placements as $placement) {
            $revision = $placement['expected_revision'];
            if ($revision === null) {
                $query = $this->pdo->prepare('SELECT published_revision FROM larena_layout_artifacts WHERE scope_ref = :scope AND artifact_id = :artifact');
                $query->execute(['scope' => $scope, 'artifact' => $placement['artifact_ref']]);
                $value = $query->fetchColumn();
                if ($value === false || $value === null) {
                    $fallback = $this->fallback?->published($scope, (string) $placement['artifact_ref'], $actor);
                    if ($fallback === null) {
                        throw new LayoutRejected('layout_artifact_child_not_published');
                    }
                    $revision = $fallback->revision;
                } else {
                    $revision = (int) $value;
                }
            }
            if ($this->versionRow($scope, (string) $placement['artifact_ref'], (int) $revision) === null
                && $this->fallback?->readRevision($scope, (string) $placement['artifact_ref'], (int) $revision, $actor) === null) {
                throw new LayoutRejected('layout_artifact_child_revision_unknown');
            }
            $placement['child_revision'] = $revision;
            $resolved[] = $placement;
        }
        return $resolved;
    }

    /** @param array<string, true> $path */
    private function assertAcyclic(string $scope, string $artifactId, int $revision, array $path, int $depth, string $actor): void
    {
        if ($depth > 32) {
            throw new LayoutRejected('layout_artifact_graph_depth_limit_exceeded');
        }
        $key = $artifactId . '@' . $revision;
        if (isset($path[$key])) {
            throw new LayoutRejected('layout_artifact_cycle_detected');
        }
        $path[$key] = true;
        $children = [];
        if ($this->versionRow($scope, $artifactId, $revision) !== null) {
            $statement = $this->pdo->prepare('SELECT child_artifact_id, child_revision FROM larena_layout_artifact_links WHERE scope_ref = :scope AND parent_artifact_id = :artifact AND parent_revision = :revision AND enabled = 1 ORDER BY sort_order, instance_id');
            $statement->execute(['scope' => $scope, 'artifact' => $artifactId, 'revision' => $revision]);
            while (is_array($child = $statement->fetch(PDO::FETCH_ASSOC))) {
                $children[] = ['artifact_id' => (string) $child['child_artifact_id'], 'revision' => (int) $child['child_revision']];
            }
        } else {
            $fallback = $this->fallback?->readRevision($scope, $artifactId, $revision, $actor);
            if ($fallback === null) {
                throw new LayoutRejected('layout_artifact_child_revision_unknown');
            }
            foreach ($fallback->artifact['placements'] as $placement) {
                if (!$placement['enabled']) {
                    continue;
                }
                $childRevision = $placement['expected_revision'];
                if ($childRevision === null) {
                    $query = $this->pdo->prepare('SELECT published_revision FROM larena_layout_artifacts WHERE scope_ref = :scope AND artifact_id = :artifact');
                    $query->execute(['scope' => $scope, 'artifact' => $placement['artifact_ref']]);
                    $value = $query->fetchColumn();
                    $childRevision = $value === false || $value === null
                        ? $this->fallback->published($scope, (string) $placement['artifact_ref'], $actor)?->revision
                        : (int) $value;
                }
                if (!is_int($childRevision)) {
                    throw new LayoutRejected('layout_artifact_child_not_published');
                }
                $children[] = ['artifact_id' => (string) $placement['artifact_ref'], 'revision' => $childRevision];
            }
        }
        foreach ($children as $child) {
            $this->assertAcyclic($scope, $child['artifact_id'], $child['revision'], $path, $depth + 1, $actor);
        }
    }

    /** @return array<string, mixed>|null */
    private function versionRow(string $scope, string $artifact, int $revision): ?array
    {
        $statement = $this->pdo->prepare('SELECT kind, revision, document_json, semantic_hash FROM larena_layout_artifact_versions WHERE scope_ref = :scope AND artifact_id = :artifact AND revision = :revision');
        $statement->execute(['scope' => $scope, 'artifact' => $artifact, 'revision' => $revision]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrateHead(string $scope, string $artifact, array $row): LayoutArtifactRevision
    {
        $document = $this->decode((string) $row['current_json']);
        $hash = $this->normalizer->hash($document);
        if (!hash_equals($hash, (string) $row['semantic_hash']) || $document['scope_ref'] !== $scope || $document['artifact_id'] !== $artifact || $document['kind'] !== $row['kind']) {
            throw new LayoutRejected('layout_artifact_persisted_integrity_failed');
        }
        $revision = (int) $row['current_revision'];
        return new LayoutArtifactRevision($artifact, $scope, (string) $row['kind'], $revision, $document, $hash, (int) ($row['published_revision'] ?? 0) === $revision);
    }

    /** @param array<string, mixed> $row */
    private function hydrateVersion(string $scope, string $artifact, array $row): LayoutArtifactRevision
    {
        $document = $this->decode((string) $row['document_json']);
        $hash = $this->normalizer->hash($document);
        if (!hash_equals($hash, (string) $row['semantic_hash']) || $document['scope_ref'] !== $scope || $document['artifact_id'] !== $artifact || $document['kind'] !== $row['kind']) {
            throw new LayoutRejected('layout_artifact_persisted_integrity_failed');
        }
        $revision = (int) $row['revision'];
        return new LayoutArtifactRevision($artifact, $scope, (string) $row['kind'], $revision, $document, $hash, (int) ($row['published_revision'] ?? 0) === $revision);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LayoutRejected('layout_artifact_persisted_json_invalid');
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new LayoutRejected('layout_artifact_persisted_json_invalid');
        }
        return $this->normalizer->normalize($value);
    }

    private function begin(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }
        $this->pdo->beginTransaction();

        return true;
    }

    private function commit(bool $ownsTransaction): void
    {
        if ($ownsTransaction && $this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function rollback(bool $ownsTransaction): void
    {
        if ($ownsTransaction && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
