<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutArtifactNormalizerTest.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoLayoutArtifactStore;
use Larena\Layout\Runtime\FrameworkRecipeCompiler;
use Larena\Layout\Runtime\LayoutArtifactResolver;

function runLayoutArtifactResolverTest(): void
{
    $pdo = new PDO('sqlite::memory:');
    $policy = new ResolverScopePolicy();
    $store = new PdoLayoutArtifactStore($pdo, $policy);
    $store->install();

    $text = layoutArtifactFixture('block.text', 'block', 'content.paragraph');
    $text['parameters'] = ['data' => ['content' => [['type' => 'text', 'value' => 'Hello']]], 'props' => []];
    $store->create($text, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'block.text', 1, 1, 'actor:alpha');

    $columns = layoutArtifactFixture('block.columns', 'block', 'layout.columns');
    $columns['parameters'] = ['props' => ['columns' => 1]];
    $columns['placements'] = [layoutPlacementFixture('column.text', 'block.text', 1, 'columns')];
    $store->create($columns, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'block.columns', 1, 1, 'actor:alpha');

    $section = layoutArtifactFixture('section.main', 'section', 'layout.section');
    $section['placements'] = [layoutPlacementFixture('columns.main', 'block.columns', 1)];
    $store->create($section, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'section.main', 1, 1, 'actor:alpha');

    $page = layoutArtifactFixture('page.home', 'page', 'layout.page');
    $page['placements'] = [
        layoutPlacementFixture('section.main', 'section.main', null, 'default', 100),
        layoutPlacementFixture('section.reused', 'section.main', 1, 'default', 200),
    ];
    $store->create($page, 'actor:alpha');
    $store->publish('scope:tenant-alpha', 'page.home', 1, 1, 'actor:alpha');

    $resolver = new LayoutArtifactResolver($store, resolverManifests());
    $resolved = $resolver->recipe('scope:tenant-alpha', 'page.home', null, 'actor:alpha', 'ru');
    assert($resolved['recipe']['schema'] === 'simai.composition.recipe.v1');
    assert($resolved['recipe']['root']['node']['type'] === 'layout.page');
    assert($resolved['recipe']['root']['node']['slots']['default'][0]['node']['slots']['default'][0]['node']['type'] === 'layout.columns');
    assert($resolved['recipe']['root']['node']['slots']['default'][0]['node']['slots']['default'][0]['node']['slots']['columns'][0]['node']['type'] === 'content.paragraph');
    assert(count($resolved['dependencies']) === 4);
    assert(count($resolved['semantic_hashes']) === 4);

    $node = getenv('SIMAI_NODE_BINARY');
    $frameworkEntry = getenv('SIMAI_UI_SOURCE_ENTRY');
    if (is_string($node) && $node !== '' && is_string($frameworkEntry) && is_file($frameworkEntry)) {
        $compiler = new FrameworkRecipeCompiler($node, $frameworkEntry, 'sha256:' . hash_file('sha256', $frameworkEntry));
        $compiled = $compiler->compile([
            'recipe' => $resolved['recipe'],
            'inputs' => ['schema' => 'simai.composition.inputs.v1', 'scope' => 'scope:tenant-alpha', 'values' => []],
            'trustedContext' => ['scope' => 'scope:tenant-alpha'],
            'executionContract' => [
                'contractDigest' => 'sha256:63daf55cb7d7c39d55f59a5ba3f5d7e511ace3f16d344879cbc877782ff3dcfd',
                'registryDigest' => 'sha256:larena-registry',
                'rendererDigest' => 'sha256:larena-renderer',
            ],
            'sources' => [],
        ]);
        assert(str_contains($compiled['html'], 'Hello'));
        assert(substr_count($compiled['html'], 'Hello') === 2);
        assert(str_contains($compiled['html'], 'sf-composition-columns'));
        $htmlOutput = getenv('LARENA_LAYOUT_HTML_OUTPUT');
        if (is_string($htmlOutput) && $htmlOutput !== '') {
            file_put_contents($htmlOutput, '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Larena Layout Artifact</title><style>body{font-family:system-ui;margin:2rem}.sf-composition-columns{display:grid;grid-template-columns:repeat(1,minmax(0,1fr));gap:1rem}</style>' . $compiled['html'] . '</html>');
        }
    }

    // Instance props belong to the parent placement, not to the reused template.
    $instances = layoutArtifactFixture('section.instances', 'section', 'layout.section');
    $instances['placements'] = [
        layoutPlacementFixture('left', 'block.columns', 1, 'default', 100),
        layoutPlacementFixture('right', 'block.columns', 1, 'default', 200),
    ];
    $instances['placements'][0]['parameters'] = ['columns' => 2];
    $instances['placements'][1]['parameters'] = ['columns' => 4];
    $store->create($instances, 'actor:alpha');
    $instancePage = layoutArtifactFixture('page.instances', 'page', 'layout.page');
    $instancePage['placements'] = [layoutPlacementFixture('body', 'section.instances', 1)];
    $store->create($instancePage, 'actor:alpha');
    $instanceResult = $resolver->recipe('scope:tenant-alpha', 'page.instances', 1, 'actor:alpha');
    $children = $instanceResult['recipe']['root']['node']['slots']['default'][0]['node']['slots']['default'];
    assert($children[0]['node']['props']['columns']['literal'] === 2);
    assert($children[1]['node']['props']['columns']['literal'] === 4);
    assert($children[0]['id'] !== $children[1]['id']);
    assert($store->readRevision('scope:tenant-alpha', 'block.columns', 1, 'actor:alpha')->artifact['parameters']['props']['columns'] === 1);
    assert($store->children('scope:tenant-alpha', 'section.instances', 'actor:alpha')[0]['child_revision'] === 1);
    assert(count(array_filter($instanceResult['dependencies'], static fn (array $d): bool => $d['artifact_id'] === 'block.columns')) === 1);
    $distribution = getenv('SIMAI_UI_ROOT');
    if (is_string($node) && $node !== '' && is_string($distribution) && $distribution !== '') {
        $lock = json_decode((string) file_get_contents($distribution.'/distr/core/contracts/composition-recipe-v1/contract.lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $instanceHtml = FrameworkRecipeCompiler::fromFrameworkDistribution($node, $distribution)->compile([
            'recipe' => $instanceResult['recipe'],
            'inputs' => ['schema' => 'simai.composition.inputs.v1', 'scope' => 'scope:tenant-alpha', 'values' => []],
            'trustedContext' => ['scope' => 'scope:tenant-alpha'],
            'executionContract' => [
                'contractDigest' => $lock['contractDigest'],
                'registryDigest' => 'sha256:'.hash_file('sha256', $distribution.'/distr/core/contracts/composition-types.v1.json'),
                'rendererDigest' => 'sha256:'.hash_file('sha256', $distribution.'/distr/core/js/composition/index.mjs'),
            ],
        ])['html'];
        assert(str_contains($instanceHtml, 'data-composition-columns="2"'));
        assert(str_contains($instanceHtml, 'data-composition-columns="4"'));
        assert(substr_count($instanceHtml, 'Hello') === 2);
        echo "Placed instance override exact Framework HTML proof passed.\n";
    }
    // Updating the shared head does not alter either exact placed revision or its override.
    $columns['parameters']['props']['columns'] = 6;
    $store->update($columns, 1, 'actor:alpha');
    assert($resolver->recipe('scope:tenant-alpha', 'page.instances', 1, 'actor:alpha')['recipe'] === $instanceResult['recipe']);

    $badSection = layoutArtifactFixture('section.bad-slot', 'section', 'layout.section');
    $badSection['placements'] = [layoutPlacementFixture('bad', 'block.text', 1, 'sidebar')];
    $store->create($badSection, 'actor:alpha');
    $badPage = layoutArtifactFixture('page.bad-slot', 'page', 'layout.page');
    $badPage['placements'] = [layoutPlacementFixture('bad.section', 'section.bad-slot', 1)];
    $store->create($badPage, 'actor:alpha');
    rejectLayoutResolver(static fn () => $resolver->recipe('scope:tenant-alpha', 'page.bad-slot', 1, 'actor:alpha'), 'layout_artifact_slot_unknown');

    echo "LayoutArtifactResolverTest passed.\n";
}

/** @return array<string,array<string,mixed>> */
function resolverManifests(): array
{
    return [
        'layout.page' => ['kinds' => ['page'], 'views' => ['default'], 'variants' => [null], 'slots' => ['default' => ['min' => 1, 'max' => 20, 'kinds' => ['section']]]],
        'layout.section' => ['kinds' => ['section'], 'views' => ['default'], 'variants' => [null], 'slots' => ['default' => ['min' => 1, 'max' => 20, 'kinds' => ['block']]]],
        'layout.columns' => ['kinds' => ['block'], 'views' => ['default'], 'variants' => [null], 'slots' => ['columns' => ['min' => 1, 'max' => 12, 'kinds' => ['block']]]],
        'content.paragraph' => ['kinds' => ['block'], 'views' => ['default'], 'variants' => [null], 'slots' => []],
    ];
}

function rejectLayoutResolver(callable $operation, string $reason): void
{
    try {
        $operation();
        throw new RuntimeException('Rejected resolution was accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason, $exception->reasonCode);
    }
}

final class ResolverScopePolicy implements PageDescriptorAuthorizationPolicy
{
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if ($actor !== 'actor:alpha' || $scopeRef !== 'scope:tenant-alpha') {
            throw new LayoutRejected('layout_scope_denied');
        }
    }
}

runLayoutArtifactResolverTest();
