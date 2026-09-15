<?php

declare(strict_types=1);

use Larena\Layout\Persistence\PdoLayoutArtifactStore;
use Larena\Layout\Runtime\FrameworkCanonicalJson;
use Larena\Layout\Runtime\FrameworkNodeRecipeResolver;
use Larena\Layout\Runtime\LayoutArtifactResolver;

function runFrameworkNodeRecipeResolverTest(): void
{
    $pdo = new PDO('sqlite::memory:');
    $policy = new ResolverScopePolicy();
    $store = new PdoLayoutArtifactStore($pdo, $policy);
    $store->install();
    $block = layoutArtifactFixture('block.text.php', 'block', 'content.paragraph');
    $block['parameters'] = ['data' => ['content' => [['type' => 'text', 'value' => 'Привет 😀']]], 'props' => []];
    $store->create($block, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'block.text.php', 1, 1, 'actor:alpha');
    $section = layoutArtifactFixture('section.php', 'section', 'layout.section');
    $section['placements'] = [layoutPlacementFixture('text', 'block.text.php', 1)];
    $store->create($section, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'section.php', 1, 1, 'actor:alpha');
    $page = layoutArtifactFixture('page.php', 'page', 'layout.page');
    $page['placements'] = [layoutPlacementFixture('main', 'section.php', 1)];
    $store->create($page, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'page.php', 1, 1, 'actor:alpha');
    $recipe = (new LayoutArtifactResolver($store, resolverManifests()))->recipe('scope:tenant-alpha', 'page.php', null, 'actor:alpha', 'ru')['recipe'];
    $resolved = (new FrameworkNodeRecipeResolver(resolverManifests()))->resolve($recipe, 'scope:tenant-alpha', [
        'contractDigest' => 'sha256:contract',
        'registryDigest' => 'sha256:registry',
        'rendererDigest' => 'sha256:php-renderer',
    ]);
    assert(($resolved['diagnostics'] ?? null) === []);
    assert(($resolved['document']['schema'] ?? null) === 'simai.composition.document.v1');
    assert(($resolved['document']['root']['slots']['default'][0]['slots']['default'][0]['data']['content'][0]['value'] ?? null) === 'Привет 😀');
    assert(preg_match('/^n-[a-f0-9]{64}$/D', $resolved['document']['root']['id']) === 1);
    $canonical = new FrameworkCanonicalJson();
    assert($resolved['dependencyReceipt']['recipeDigest'] === $canonical->digest($recipe));
    assert($resolved['dependencyReceipt']['documentDigest'] === $canonical->digest($resolved['document']));
    assert($resolved['dependencyReceipt']['executionContract']['canonicalization'] === FrameworkCanonicalJson::PROFILE);
    echo "FrameworkNodeRecipeResolverTest passed.\n";
}

runFrameworkNodeRecipeResolverTest();
