<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../src/Runtime/RegionInheritanceResolver.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoLayoutArtifactStore;
use Larena\Layout\Runtime\RegionInheritanceResolver;

$policy = new class implements PageDescriptorAuthorizationPolicy {
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if ($actor !== 'actor:allowed' || $scopeRef !== 'scope:probe') throw new LayoutRejected('catalog_denied');
    }
};
$store = new PdoLayoutArtifactStore(new PDO('sqlite::memory:'), $policy);
$store->install();
foreach (['shell.default' => 'page', 'block.shared' => 'block'] as $id => $kind) {
    $store->create(['schema' => 'larena.layout.artifact.v1', 'artifact_id' => $id, 'scope_ref' => 'scope:probe',
        'kind' => $kind, 'component' => $kind === 'page' ? 'layout.page' : 'content.paragraph',
        'presentation' => ['view' => 'default', 'variant' => null, 'modifiers' => []],
        'parameters' => [], 'placements' => [], 'bindings' => [], 'asset_refs' => [], 'extensions' => []], 'actor:allowed');
}
$ref = ['artifact_id' => 'block.shared', 'revision' => 1];
$request = ['schema' => 'larena.layout.region_inheritance_request.v1', 'scope_ref' => 'scope:probe', 'layers' => [
    ['level' => 'site', 'source_id' => 'site.probe', 'revision' => 1, 'shell' => ['mode' => 'replace', 'artifact' => ['artifact_id' => 'shell.default', 'revision' => 1]],
        'regions' => ['main' => ['mode' => 'replace', 'placements' => [['placement_id' => 'first', 'artifact' => $ref]]]]],
    ['level' => 'section', 'source_id' => 'section.probe', 'revision' => 1, 'shell' => ['mode' => 'inherit'], 'regions' => []],
    ['level' => 'page', 'source_id' => 'page.probe', 'revision' => 1, 'shell' => ['mode' => 'inherit'], 'regions' => []],
]];
$guard = static fn (array $layer, string $actor, string $scope): bool => $actor === 'actor:allowed' && $scope === 'scope:probe'
    && $layer['source_id'] === $layer['level'].'.probe' && $layer['revision'] === 1;
$resolver = new RegionInheritanceResolver($store, $guard, ['main', 'aside']);
$before = serialize($request);
$result = $resolver->resolve($request, 'actor:allowed');
assert(serialize($request) === $before);
assert($result['regions']['main'][0]['placement_id'] === 'first');
assert($result['receipt']['origins']['main']['level'] === 'site');
assert(count($result['receipt']['sources']) === 3);
$clear = $request;
$clear['layers'][2]['regions']['main'] = ['mode' => 'replace', 'placements' => []];
assert($resolver->resolve($clear, 'actor:allowed')['regions']['main'] === []);
assert($resolver->resolve($clear, 'actor:allowed')['receipt']['origins']['main']['level'] === 'page');
$reuse = $request;
$reuse['layers'][1]['regions']['aside'] = ['mode' => 'replace', 'placements' => [['placement_id' => 'second', 'artifact' => $ref]]];
assert(count($resolver->resolve($reuse, 'actor:allowed')['receipt']['artifacts']) === 2);
$ordered = $request;
$ordered['layers'][2]['regions']['main']['mode'] = 'replace';
$ordered['layers'][2]['regions']['main']['placements'] = [['placement_id' => 'second', 'artifact' => $ref], ['placement_id' => 'first', 'artifact' => $ref]];
assert(array_column($resolver->resolve($ordered, 'actor:allowed')['regions']['main'], 'placement_id') === ['second', 'first']);
$cases = [];
$bad = $request; $bad['layers'][1]['revision'] = 2; $cases[] = [$bad, 'layout_inheritance_source_denied_or_stale'];
$bad = $request; $bad['layers'][2]['regions']['unknown'] = ['mode' => 'inherit']; $cases[] = [$bad, 'layout_inheritance_region_unknown'];
$bad = $request; $bad['layers'][0]['regions']['main']['placements'][0]['artifact']['revision'] = 99; $cases[] = [$bad, 'layout_inheritance_artifact_unavailable'];
$bad = $reuse; $bad['layers'][1]['regions']['aside']['placements'][0]['placement_id'] = 'first'; $cases[] = [$bad, 'layout_inheritance_placement_duplicate'];
$bad = $request; $bad['layers'][2]['shell']['artifact'] = $ref; $cases[] = [$bad, 'layout_inheritance_fields_invalid'];
$bad = $request; $bad['layers'][0]['shell'] = ['mode' => 'inherit']; $cases[] = [$bad, 'layout_inheritance_shell_missing'];
$bad = $request; $bad['layers'][2]['regions']['main'] = ['mode' => 'append']; $cases[] = [$bad, 'layout_inheritance_mode_invalid'];
foreach ($cases as [$bad, $reason]) {
    try { $resolver->resolve($bad, 'actor:allowed'); throw new RuntimeException('Invalid inheritance accepted.'); }
    catch (LayoutRejected $exception) { assert($exception->reasonCode === $reason); }
}
try { $resolver->resolve($request, 'actor:foreign'); throw new RuntimeException('Foreign actor accepted.'); }
catch (LayoutRejected $exception) { assert($exception->reasonCode === 'layout_inheritance_source_denied_or_stale'); }
$catalogOnly = new RegionInheritanceResolver($store, static fn (): bool => true, ['main']);
try { $catalogOnly->resolve($request, 'actor:foreign'); throw new RuntimeException('Catalog ACL bypassed.'); }
catch (LayoutRejected $exception) { assert($exception->reasonCode === 'catalog_denied'); }
echo "RegionInheritanceResolverTest passed: inheritance, clear, reuse, order, provenance, immutable input and nine refusals.\n";
