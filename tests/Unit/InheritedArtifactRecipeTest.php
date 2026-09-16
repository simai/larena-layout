<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/RegionInheritanceResolverTest.php';

use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LayoutArtifactResolver;

// Reuse the real SQLite catalog and authorized owner request prepared by the preceding test.
assert(isset($store, $request, $guard, $ref));
$manifests = ['layout.page' => ['kinds' => ['page'], 'views' => ['default'], 'variants' => [null],
    'slots' => ['default' => ['min' => 0, 'max' => 8, 'kinds' => ['block']]]],
    'content.paragraph' => ['kinds' => ['block'], 'views' => ['default'], 'variants' => [null], 'slots' => []]];
$treeResolver = new LayoutArtifactResolver($store, $manifests);
$storedBefore = $store->readRevision('scope:probe', 'shell.default', 1, 'actor:allowed');
$inherited = $treeResolver->inheritedRecipe($request, 'actor:allowed', $guard, ['main' => 'default']);
assert($inherited['recipe']['root']['node']['slots']['default'][0]['id'] === 'shell.default:first');
assert($inherited['recipe']['root']['node']['slots']['default'][0]['node']['type'] === 'content.paragraph');
assert($inherited['inheritanceReceipt']['origins']['main']['level'] === 'site');
assert(count($inherited['dependencies']) === 2);
assert($store->readRevision('scope:probe', 'shell.default', 1, 'actor:allowed') == $storedBefore);
$empty = $request;
$empty['layers'][2]['regions']['main'] = ['mode' => 'replace', 'placements' => []];
assert($treeResolver->inheritedRecipe($empty, 'actor:allowed', $guard, ['main' => 'default'])['recipe']['root']['node']['slots']['default'] === []);
foreach ([['main' => 'unknown'], ['main' => 'default', 'aside' => 'default']] as $map) {
    try { $treeResolver->inheritedRecipe($request, 'actor:allowed', $guard, $map); throw new RuntimeException('Invalid slot mapping accepted.'); }
    catch (LayoutRejected $exception) { assert(in_array($exception->reasonCode, ['layout_inheritance_region_slot_unknown', 'layout_inheritance_region_mapping_ambiguous'], true)); }
}
$strict = $manifests; $strict['layout.page']['slots']['default']['kinds'] = ['section'];
try { (new LayoutArtifactResolver($store, $strict))->inheritedRecipe($request, 'actor:allowed', $guard, ['main' => 'default']); throw new RuntimeException('Slot kind bypassed.'); }
catch (LayoutRejected $exception) { assert($exception->reasonCode === 'layout_artifact_slot_kind_rejected'); }
$minimum = $manifests; $minimum['layout.page']['slots']['default']['min'] = 1;
try { (new LayoutArtifactResolver($store, $minimum))->inheritedRecipe($empty, 'actor:allowed', $guard, ['main' => 'default']); throw new RuntimeException('Required slot cleared.'); }
catch (LayoutRejected $exception) { assert($exception->reasonCode === 'layout_artifact_slot_min_not_met'); }
echo "InheritedArtifactRecipeTest passed: existing Recipe tree, immutable catalog, empty slot and four manifest refusals.\n";

$nodeBinary = getenv('SIMAI_NODE_BINARY');
$frameworkRoot = getenv('SIMAI_UI_ROOT');
if (is_string($nodeBinary) && $nodeBinary !== '' && is_string($frameworkRoot) && $frameworkRoot !== '') {
    $block = $store->readRevision('scope:probe', 'block.shared', 1, 'actor:allowed')->artifact;
    $block['bindings'] = [['binding_id' => 'title', 'kind' => 'storage_record', 'target' => 'probe|record.probe',
        'selector' => 'values.title', 'expected_revision' => 1]];
    $store->update($block, 1, 'actor:allowed');
    $section = $block;
    $section['artifact_id'] = 'section.content'; $section['kind'] = 'section'; $section['component'] = 'layout.section'; $section['bindings'] = [];
    $section['placements'] = [['instance_id' => 'shared', 'artifact_ref' => 'block.shared', 'expected_revision' => 2,
        'slot' => 'default', 'sort' => 10, 'enabled' => true, 'parameters' => []]];
    $store->create($section, 'actor:allowed');
    $composedRequest = $request;
    $composedRequest['layers'][0]['regions']['main']['placements'] = [['placement_id' => 'content', 'artifact' => ['artifact_id' => 'section.content', 'revision' => 1]]];
    $compositionManifests = $manifests;
    $compositionManifests['layout.page']['slots']['default']['kinds'] = ['section'];
    $compositionManifests['layout.section'] = ['kinds' => ['section'], 'views' => ['default'], 'variants' => [null],
        'slots' => ['default' => ['min' => 1, 'max' => 8, 'kinds' => ['block']]]];
    $compositionTree = (new LayoutArtifactResolver($store, $compositionManifests))->inheritedRecipe($composedRequest, 'actor:allowed', $guard, ['main' => 'default']);
    $contentOwner = new class implements \Larena\Layout\Contracts\PageBindingOwnerResolver {
        public function resolve(array $binding, string $actor, string $scopeRef): \Larena\Layout\ValueObjects\PageBindingResult
        {
            if ($actor !== 'actor:allowed' || $scopeRef !== 'scope:probe') throw new LayoutRejected('owner_denied');
            return \Larena\Layout\ValueObjects\PageBindingResult::storageRecord(['record_id' => 'record.probe', 'revision' => 1,
                'schema_id' => 'probe', 'values' => ['title' => 'Наследование · source-owned text 👋']]);
        }
    };
    $bound = (new \Larena\Layout\Runtime\LayoutArtifactInputResolver($contentOwner, ['content.paragraph' => ['title' => [
        'kind' => 'storage_record', 'plane' => 'data', 'field' => 'content', 'format' => 'inline_text', 'schema' => ['type' => 'array'],
    ]]]))->resolve($compositionTree['recipe'], 'actor:allowed', 'scope:probe');
    $contractLock = json_decode((string) file_get_contents($frameworkRoot.'/distr/core/contracts/composition-recipe-v1/contract.lock.json'), true, 512, JSON_THROW_ON_ERROR);
    $runtimeDigest = 'sha256:'.hash_file('sha256', $frameworkRoot.'/distr/core/js/composition/index.mjs');
    $compiled = \Larena\Layout\Runtime\FrameworkRecipeCompiler::fromFrameworkDistribution($nodeBinary, $frameworkRoot)->compile($bound + ['trustedContext' => ['scope' => 'scope:probe'], 'executionContract' => [
        'contractDigest' => $contractLock['contractDigest'], 'registryDigest' => 'sha256:'.hash_file('sha256', $frameworkRoot.'/distr/core/contracts/composition-types.v1.json'), 'rendererDigest' => $runtimeDigest,
    ]]);
    assert(str_contains($compiled['html'], 'Наследование · source-owned text 👋'));
    assert(str_contains($compiled['html'], '<section'));
    assert(count($compositionTree['dependencies']) === 3);
    assert($store->readRevision('scope:probe', 'shell.default', 1, 'actor:allowed') == $storedBefore);
    echo "InheritedArtifactRecipeTest exact Framework compile passed: inherited page → section → externally bound paragraph → server HTML.\n";
} else {
    echo "InheritedArtifactRecipeTest exact Framework compile skipped: distribution is not configured.\n";
}
