<?php

declare(strict_types=1);

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Persistence\PackageLayoutArtifactCatalog;
use Larena\Layout\Persistence\PdoLayoutArtifactStore;
use Larena\Layout\Runtime\HybridLayoutArtifactCatalog;

function runPackageLayoutArtifactCatalogTest(): void
{
    $root = sys_get_temp_dir().'/larena-layout-package-catalog-'.bin2hex(random_bytes(5));
    mkdir($root.'/artifacts', 0755, true);
    $block = layoutArtifactFixture('block.system', 'block', 'content.paragraph');
    $block['scope_ref'] = 'scope:system';
    $page = layoutArtifactFixture('page.system', 'page', 'layout.page');
    $page['scope_ref'] = 'scope:system';
    $page['placements'] = [layoutPlacementFixture('body', 'block.system', 1)];
    file_put_contents($root.'/artifacts/block.json', json_encode($block, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/artifacts/page.json', json_encode($page, JSON_THROW_ON_ERROR));
    file_put_contents($root.'/catalog.json', json_encode([
        'schema' => 'larena.layout.package_artifact_catalog.v1',
        'owner' => 'larena/test',
        'artifacts' => [
            ['path' => 'artifacts/block.json', 'revision' => 1, 'published' => true],
            ['path' => 'artifacts/page.json', 'revision' => 1, 'published' => true],
        ],
    ], JSON_THROW_ON_ERROR));
    $policy = new PackageCatalogPolicy();
    $system = new PackageLayoutArtifactCatalog($root.'/catalog.json', $policy);
    assert($system->published('scope:system', 'page.system', 'guest:login')?->revision === 1);
    assert($system->children('scope:system', 'page.system', 'guest:login')[0]['child_artifact_id'] === 'block.system');
    assert($system->parents('scope:system', 'block.system', 'guest:login')[0]['parent_artifact_id'] === 'page.system');

    $pdo = new PDO('sqlite::memory:');
    $overrides = new PdoLayoutArtifactStore($pdo, $policy, new Larena\Layout\Runtime\LayoutArtifactNormalizer(), $system);
    $overrides->install();
    $override = $page;
    $override['parameters'] = ['props' => ['variant' => 'custom']];
    $created = $overrides->create($override, 'guest:login');
    $hybridDraft = new HybridLayoutArtifactCatalog($overrides, $system);
    assert($hybridDraft->readRevision('scope:system', 'page.system', 1, 'guest:login')?->semanticHash === $system->published('scope:system', 'page.system', 'guest:login')->semanticHash, 'An unpublished override must not shadow an immutable system revision.');
    assert($created->revision === 2, 'The first override reserves the existing system revision namespace.');
    $overrides->publish('scope:system', 'page.system', $created->revision, $created->revision, 'guest:login');
    $hybrid = new HybridLayoutArtifactCatalog($overrides, $system);
    assert($hybrid->published('scope:system', 'page.system', 'guest:login')?->artifact['parameters']['props']['variant'] === 'custom');
    assert($hybrid->published('scope:system', 'block.system', 'guest:login')?->artifactId === 'block.system');
    assert(count($hybrid->search('scope:system', 'guest:login')) === 2, 'An override must replace the system head in effective search results.');
    assert(count($hybrid->parents('scope:system', 'block.system', 'guest:login')) === 1, 'An effective parent must not be duplicated by its system source.');

    $override['placements'] = [];
    $overrides->update($override, $created->revision, 'guest:login');
    $updatedHybrid = new HybridLayoutArtifactCatalog($overrides, $system);
    assert($updatedHybrid->parents('scope:system', 'block.system', 'guest:login') === [], 'Removed system relationships must not leak through an override.');
    assert($updatedHybrid->children('scope:system', 'page.system', 'guest:login') === [], 'Effective children must come from the override only.');

    // Existing ambiguous data is retained; exact reads fail instead of guessing its source.
    $legacy = new PdoLayoutArtifactStore(new PDO('sqlite::memory:'), $policy);
    $legacy->install();
    $legacy->create($override, 'guest:login');
    $legacy->publish('scope:system', 'page.system', 1, 1, 'guest:login');
    $legacyHybrid = new HybridLayoutArtifactCatalog($legacy, $system);
    foreach ([
        static fn () => $legacyHybrid->readRevision('scope:system', 'page.system', 1, 'guest:login'),
        static fn () => $legacyHybrid->published('scope:system', 'page.system', 'guest:login'),
        static fn () => $legacyHybrid->history('scope:system', 'page.system', 'guest:login'),
    ] as $read) {
        try {
            $read();
            throw new RuntimeException('Ambiguous immutable revision was accepted.');
        } catch (Larena\Layout\Exceptions\LayoutRejected $exception) {
            assert($exception->reasonCode === 'layout_artifact_revision_source_conflict');
        }
    }
    assert($legacy->read('scope:system', 'page.system', 'guest:login')?->revision === 1, 'Conflict detection must not rewrite legacy data.');
    assert(count($hybridDraft->history('scope:system', 'page.system', 'guest:login')) === 3, 'System and override versions must both remain visible.');

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
    echo "PackageLayoutArtifactCatalogTest passed.\n";
}

final class PackageCatalogPolicy implements PageDescriptorAuthorizationPolicy
{
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        assert($actor === 'guest:login');
        assert($scopeRef === 'scope:system');
    }
}

runPackageLayoutArtifactCatalogTest();
