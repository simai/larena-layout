<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../Unit/LayoutArtifactNormalizerTest.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoLayoutArtifactStore;

function runLayoutArtifactPersistenceTest(): void
{
    $path = tempnam(sys_get_temp_dir(), 'larena-layout-artifacts-');
    assert(is_string($path));
    $pdo = new PDO('sqlite:' . $path);
    $policy = new LayoutArtifactScopePolicy(['actor:alpha' => ['scope:tenant-alpha']]);
    $store = new PdoLayoutArtifactStore($pdo, $policy);
    $store->install();

    $block = layoutArtifactFixture('block.shared', 'block', 'content.notice');
    $blockRevision = $store->create($block, 'actor:alpha');
    assert($blockRevision->revision === 1);
    assert($store->publish('scope:tenant-alpha', 'block.shared', 1, 1, 'actor:alpha')->published);

    foreach (['section.first', 'section.second'] as $index => $sectionId) {
        $section = layoutArtifactFixture($sectionId, 'section', 'layout.section');
        $section['placements'] = [layoutPlacementFixture('notice.' . $index, 'block.shared', 1)];
        $store->create($section, 'actor:alpha');
        $store->publish('scope:tenant-alpha', $sectionId, 1, 1, 'actor:alpha');
    }

    $page = layoutArtifactFixture('page.home', 'page', 'layout.page');
    $page['placements'] = [
        layoutPlacementFixture('section.one', 'section.first', 1, 'default', 100),
        layoutPlacementFixture('section.two', 'section.second', null, 'default', 200),
    ];
    $createdPage = $store->create($page, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'page.home', 1, 1, 'actor:alpha');
    assert($store->publish('scope:tenant-alpha', 'page.home', 1, 1, 'actor:alpha')->published);
    assert($createdPage->revision === 1);

    $parents = $store->parents('scope:tenant-alpha', 'block.shared', 'actor:alpha');
    assert(array_column($parents, 'parent_artifact_id') === ['section.first', 'section.second']);
    assert(count($store->children('scope:tenant-alpha', 'page.home', 'actor:alpha')) === 2);
    assert(array_map(static fn ($revision): string => $revision->artifactId, $store->search('scope:tenant-alpha', 'actor:alpha', 'block')) === ['block.shared']);
    assert(array_map(static fn ($revision): string => $revision->artifactId, $store->search('scope:tenant-alpha', 'actor:alpha', null, 'section.first')) === ['block.shared']);
    assert(array_map(static fn ($revision): string => $revision->artifactId, $store->search('scope:tenant-alpha', 'actor:alpha', null, null, 'block.shared')) === ['section.first', 'section.second']);

    $updatedBlock = $block;
    $updatedBlock['parameters'] = ['tone' => 'warning'];
    assert($store->update($updatedBlock, 1, 'actor:alpha')->revision === 2);
    assert($store->published('scope:tenant-alpha', 'block.shared', 'actor:alpha')?->revision === 1);
    assert(array_map(static fn ($revision): int => $revision->revision, $store->history('scope:tenant-alpha', 'block.shared', 'actor:alpha')) === [2, 1]);
    assert($store->restore('scope:tenant-alpha', 'block.shared', 1, 2, 'actor:alpha')->revision === 3);

    $publishedBeforeTransaction = publishedArtifactRevision($pdo, 'scope:tenant-alpha', 'block.shared');
    try {
        $store->transactional(function () use ($store): void {
            $store->publish('scope:tenant-alpha', 'block.shared', 3, 3, 'actor:alpha');
            throw new RuntimeException('force_product_publication_rollback');
        });
    } catch (RuntimeException $exception) {
        assert($exception->getMessage() === 'force_product_publication_rollback');
    }
    assert(publishedArtifactRevision($pdo, 'scope:tenant-alpha', 'block.shared') === $publishedBeforeTransaction);

    $store->transactional(function () use ($store): void {
        $store->publish('scope:tenant-alpha', 'block.shared', 3, 3, 'actor:alpha');
    });
    assert(publishedArtifactRevision($pdo, 'scope:tenant-alpha', 'block.shared') === 3);

    $before = artifactDatabaseState($pdo);
    rejectLayoutPersistence(static fn () => $store->update($block, 2, 'actor:alpha'), 'layout_artifact_revision_conflict');
    assert(artifactDatabaseState($pdo) === $before);
    $unknownChild = layoutArtifactFixture('section.invalid', 'section', 'layout.section');
    $unknownChild['placements'] = [layoutPlacementFixture('unknown', 'block.unknown', 1)];
    rejectLayoutPersistence(static fn () => $store->create($unknownChild, 'actor:alpha'), 'layout_artifact_child_revision_unknown');

    $reopened = new PdoLayoutArtifactStore(new PDO('sqlite:' . $path), $policy);
    assert($reopened->published('scope:tenant-alpha', 'page.home', 'actor:alpha')?->semanticHash === $createdPage->semanticHash);
    rejectLayoutPersistence(static fn () => $reopened->read('scope:tenant-alpha', 'page.home', 'actor:foreign'), 'layout_scope_denied');

    $store->uninstall();
    $afterUninstall = countArtifactTables($pdo);
    assert($afterUninstall === 0);
    $store->install();
    $afterReinstall = countArtifactTables($pdo);
    assert($afterReinstall === 3);
    @unlink($path);
    echo "LayoutArtifactPersistenceTest passed.\n";
}

function runLayoutArtifactMySqlPersistenceTest(): void
{
    $dsn = getenv('LARENA_LAYOUT_MYSQL_DSN');
    if (!is_string($dsn) || $dsn === '') {
        return;
    }
    $user = getenv('LARENA_LAYOUT_MYSQL_USER');
    $password = getenv('LARENA_LAYOUT_MYSQL_PASSWORD');
    $pdo = new PDO($dsn, is_string($user) ? $user : '', is_string($password) ? $password : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $policy = new LayoutArtifactScopePolicy(['actor:alpha' => ['scope:tenant-alpha']]);
    $store = new PdoLayoutArtifactStore($pdo, $policy);
    $store->uninstall();
    $store->install();
    $block = layoutArtifactFixture('block.mysql', 'block', 'content.notice');
    assert($store->create($block, 'actor:alpha')->revision === 1);
    assert($store->publish('scope:tenant-alpha', 'block.mysql', 1, 1, 'actor:alpha')->published);
    $block['parameters'] = ['tone' => 'success'];
    assert($store->update($block, 1, 'actor:alpha')->revision === 2);
    assert($store->restore('scope:tenant-alpha', 'block.mysql', 1, 2, 'actor:alpha')->revision === 3);
    $store->uninstall();
    $store->install();
    $store->uninstall();
    echo "LayoutArtifactMySqlPersistenceTest passed.\n";
}

function countArtifactTables(PDO $pdo): int
{
    $statement = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'larena_layout_artifact%'");
    if ($statement === false) {
        throw new RuntimeException('Unable to inspect artifact tables.');
    }
    return (int) $statement->fetchColumn();
}

function publishedArtifactRevision(PDO $pdo, string $scope, string $artifact): ?int
{
    $statement = $pdo->prepare('SELECT published_revision FROM larena_layout_artifacts WHERE scope_ref = :scope AND artifact_id = :artifact');
    $statement->execute(['scope' => $scope, 'artifact' => $artifact]);
    $value = $statement->fetchColumn();

    return $value === false || $value === null ? null : (int) $value;
}

/** @return array<string,mixed> */
function artifactDatabaseState(PDO $pdo): array
{
    return [
        'heads' => $pdo->query('SELECT * FROM larena_layout_artifacts ORDER BY scope_ref, artifact_id')->fetchAll(PDO::FETCH_ASSOC),
        'versions' => $pdo->query('SELECT * FROM larena_layout_artifact_versions ORDER BY scope_ref, artifact_id, revision')->fetchAll(PDO::FETCH_ASSOC),
        'links' => $pdo->query('SELECT * FROM larena_layout_artifact_links ORDER BY scope_ref, parent_artifact_id, parent_revision, instance_id')->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function rejectLayoutPersistence(callable $operation, string $reason): void
{
    try {
        $operation();
        throw new RuntimeException('Rejected persistence operation was accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason, $exception->reasonCode);
        assert($exception->getPrevious() === null);
    }
}

final class LayoutArtifactScopePolicy implements PageDescriptorAuthorizationPolicy
{
    /** @param array<string,list<string>> $scopes */
    public function __construct(private array $scopes)
    {
    }

    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if (!in_array($operation, [self::CREATE, self::UPDATE, self::READ, self::PROJECT], true)
            || !in_array($scopeRef, $this->scopes[$actor] ?? [], true)) {
            throw new LayoutRejected('layout_scope_denied');
        }
    }
}

runLayoutArtifactPersistenceTest();
runLayoutArtifactMySqlPersistenceTest();
