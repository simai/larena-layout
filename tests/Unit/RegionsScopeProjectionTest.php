<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LayoutArtifactResolver;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;

/** In-memory catalog: exact revisions only, no authorization concerns in this projection test. */
$catalog = new class implements LayoutArtifactCatalog {
    /** @var array<string,LayoutArtifactRevision> */
    public array $items = [];
    public function put(array $artifact, string $kind): void
    {
        $this->items[$artifact['artifact_id']] = new LayoutArtifactRevision($artifact['artifact_id'], 'scope:probe', $kind, 1, $artifact, 'sha256:'.hash('sha256', json_encode($artifact)), true);
    }
    public function read(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision { return $this->items[$artifactId] ?? null; }
    public function readRevision(string $scopeRef, string $artifactId, int $revision, string $actor): ?LayoutArtifactRevision { return $this->items[$artifactId] ?? null; }
    public function published(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision { return $this->items[$artifactId] ?? null; }
    public function history(string $scopeRef, string $artifactId, string $actor): array { return []; }
    public function search(string $scopeRef, string $actor, ?string $kind = null, ?string $parentId = null, ?string $childId = null): array { return []; }
    public function parents(string $scopeRef, string $artifactId, string $actor): array { return []; }
    public function children(string $scopeRef, string $artifactId, string $actor): array { return []; }
};
$artifact = static fn (string $id, string $component, array $parameters, array $placements = []): array => [
    'schema' => 'larena.layout.artifact.v1', 'artifact_id' => $id, 'scope_ref' => 'scope:probe', 'kind' => 'block',
    'component' => $component, 'presentation' => ['view' => 'default', 'variant' => null, 'modifiers' => []],
    'parameters' => $parameters, 'placements' => $placements, 'bindings' => [], 'asset_refs' => [], 'extensions' => []];
$placement = static fn (string $id, string $ref, string $slot, int $sort): array => [
    'instance_id' => $id, 'artifact_ref' => $ref, 'expected_revision' => 1, 'slot' => $slot, 'sort' => $sort, 'enabled' => true, 'parameters' => []];

$catalog->put($artifact('block.list-a', 'larena.registered-list', ['props' => ['source_key' => 'storage.probe', 'column_preset' => 'records', 'title' => 'A']]), 'block');
$catalog->put($artifact('block.list-b', 'larena.registered-list', ['props' => ['source_key' => 'storage.probe', 'column_preset' => 'records', 'title' => 'B']]), 'block');
$catalog->put($artifact('block.text', 'content.paragraph', ['data' => ['content' => [['type' => 'text', 'value' => 'Body']]]]), 'block');
$routes = [['id' => 'pick', 'from' => ['endpoint' => 'records', 'port' => 'selection'], 'to' => ['endpoint' => 'selected', 'port' => 'context']]];
$catalog->put($artifact('block.scope', 'layout.scope', ['props' => ['routes' => $routes]],
    [$placement('records', 'block.list-a', 'default', 10), $placement('selected', 'block.list-b', 'default', 20)]), 'block');
$regions = [['name' => 'main', 'landmark' => 'main'], ['name' => 'aside', 'landmark' => 'complementary', 'label' => 'Related', 'placement' => 'end']];
$page = $artifact('page.workspace', 'layout.regions', ['props' => ['regions' => $regions]],
    [$placement('body', 'block.text', 'main', 10), $placement('lists', 'block.scope', 'aside', 10)]);
$page['kind'] = 'page';
$catalog->put($page, 'page');

$manifests = [
    'layout.regions' => ['kinds' => ['page'], 'views' => ['default'], 'variants' => [null], 'slots' => [],
        'region_slot' => ['min' => 0, 'max' => 8, 'kinds' => ['block']]],
    'layout.scope' => ['kinds' => ['block'], 'views' => ['default'], 'variants' => [null], 'slots' => ['default' => ['min' => 1, 'max' => 8, 'kinds' => ['block']]]],
    'larena.registered-list' => ['kinds' => ['block'], 'views' => ['default'], 'variants' => [null], 'slots' => []],
    'content.paragraph' => ['kinds' => ['block'], 'views' => ['default'], 'variants' => [null], 'slots' => []],
];
$resolver = new LayoutArtifactResolver($catalog, $manifests);
$root = $resolver->recipe('scope:probe', 'page.workspace', 1, 'actor:allowed', 'en')['recipe']['root'];

assert($root['node']['type'] === 'layout.regions');
assert(array_keys($root['node']['slots']) === ['regions']);
$declared = $root['node']['slots']['regions'];
assert(array_column(array_column($declared, 'node'), 'type') === ['layout.region', 'layout.region']);
assert($declared[0]['node']['props']['name'] === ['literal' => 'main']);
assert($declared[1]['node']['props']['label'] === ['literal' => 'Related']);
assert($declared[0]['node']['slots']['default'][0]['node']['type'] === 'content.paragraph');
$scope = $declared[1]['node']['slots']['default'][0]['node'];
assert($scope['type'] === 'layout.scope');
assert(!isset($root['node']['props']['regions']));
$lists = $scope['slots']['default'];
assert($lists[0]['node']['extensions']['simai.composition:endpoint'] === ['name' => 'records']);
assert($lists[1]['node']['extensions']['simai.composition:endpoint'] === ['name' => 'selected']);
assert(isset($lists[0]['node']['extensions']['larena:artifact']));

// A child that no route names receives no endpoint.
$catalog->put($artifact('block.scope', 'layout.scope', ['props' => ['routes' => $routes]],
    [$placement('records', 'block.list-a', 'default', 10), $placement('selected', 'block.list-b', 'default', 20),
        $placement('note', 'block.text', 'default', 30)]), 'block');
$lists = $resolver->recipe('scope:probe', 'page.workspace', 1, 'actor:allowed', 'en')['recipe']['root']['node']['slots']['regions'][1]['node']['slots']['default'][0]['node']['slots']['default'];
assert(!isset($lists[2]['node']['extensions']['simai.composition:endpoint']));

// Placements into an undeclared region, duplicate names and malformed declarations are refused.
foreach ([
    [[['name' => 'main', 'landmark' => 'main']], 'layout_artifact_slot_unknown'],
    [[['name' => 'main', 'landmark' => 'main'], ['name' => 'main', 'landmark' => 'region', 'label' => 'x']], 'layout_artifact_region_name_duplicate'],
    [[['name' => 'Main', 'landmark' => 'main']], 'layout_artifact_regions_invalid'],
    [[['name' => 'main', 'landmark' => 'main', 'html' => '<b>']], 'layout_artifact_regions_invalid'],
    [[], 'layout_artifact_regions_invalid'],
] as [$declaration, $code]) {
    $broken = $page;
    $broken['parameters']['props']['regions'] = $declaration;
    $catalog->put($broken, 'page');
    try {
        $resolver->recipe('scope:probe', 'page.workspace', 1, 'actor:allowed', 'en');
        throw new RuntimeException('Invalid region declaration accepted: '.$code);
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $code, $exception->reasonCode.' != '.$code);
    }
}
$catalog->put($page, 'page');
echo "RegionsScopeProjectionTest passed: regions and region props, scope endpoints from routes, five refusals.\n";

// With the pinned Framework runtime, the projected Recipe resolves, validates and renders.
$nodeBinary = getenv('SIMAI_NODE_BINARY');
$frameworkRoot = getenv('SIMAI_UI_ROOT');
$registryModule = getenv('LARENA_COMPOSITION_REGISTRY_MODULE');
if (is_string($nodeBinary) && $nodeBinary !== '' && is_string($frameworkRoot) && $frameworkRoot !== ''
    && is_string($registryModule) && is_file($registryModule)) {
    $tree = $resolver->recipe('scope:probe', 'page.workspace', 1, 'actor:allowed', 'en');
    $lockPath = $frameworkRoot.'/distr/core/contracts/composition-recipe-v1/contract.lock.json';
    $contract = json_decode((string) file_get_contents($lockPath), true, 64, JSON_THROW_ON_ERROR);
    $compiler = \Larena\Layout\Runtime\FrameworkRecipeCompiler::fromFrameworkDistribution($nodeBinary, $frameworkRoot, $registryModule);
    $compiled = $compiler->compile(['recipe' => $tree['recipe'], 'inputs' => ['schema' => 'simai.composition.inputs.v1', 'scope' => 'scope:probe', 'values' => []],
        'sources' => [], 'trustedContext' => ['scope' => 'scope:probe'], 'executionContract' => ['contractDigest' => $contract['contractDigest'],
        'registryDigest' => 'sha256:'.hash_file('sha256', $registryModule), 'rendererDigest' => 'sha256:'.hash_file('sha256', $registryModule)]]);
    $html = (string) $compiled['html'];
    assert(str_contains($html, '<main class="sf-composition-region"'));
    assert(str_contains($html, '<aside class="sf-composition-region"'));
    assert(str_contains($html, 'aria-label="Related"'));
    assert(str_contains($html, '<sf-composition-scope'));
    assert(substr_count($html, 'data-sf-endpoint=') === 2);
    echo "RegionsScopeProjectionTest passed: pinned Framework resolves and renders the projected regions and scope.\n";
}
