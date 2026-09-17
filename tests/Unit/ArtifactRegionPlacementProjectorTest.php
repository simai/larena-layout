<?php

declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoLayoutArtifactStore;
use Larena\Layout\Runtime\ArtifactRegionPlacementProjector;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;

$projectionPolicy = new class implements PageDescriptorAuthorizationPolicy {
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if ($actor !== 'actor:projection' || !in_array($scopeRef, ['scope:first', 'scope:second'], true)) throw new LayoutRejected('projection_test_denied');
    }
};
$projectionStore = new PdoLayoutArtifactStore(new PDO('sqlite::memory:'), $projectionPolicy);
$projectionStore->install();
$projector = new ArtifactRegionPlacementProjector($projectionStore, $projectionStore);
foreach (['scope:first', 'scope:second'] as $scope) {
    $base = ['schema'=>'larena.layout.artifact.v1', 'artifact_id'=>'block.shared', 'scope_ref'=>$scope,
        'kind'=>'block', 'component'=>'content.paragraph', 'presentation'=>['view'=>'default','variant'=>null,'modifiers'=>[]],
        'parameters'=>[], 'placements'=>[], 'bindings'=>[], 'asset_refs'=>[], 'extensions'=>[]];
    $child = $projectionStore->create($base, 'actor:projection');
    $projectionStore->publish($scope, 'block.shared', 1, 1, 'actor:projection');
    $parent = $base; $parent['artifact_id']='page.generic'; $parent['kind']='page'; $parent['component']='layout.page';
    $placement = ['instance_id'=>'first','artifact_ref'=>'block.shared','expected_revision'=>1,'slot'=>'body','sort'=>20,'enabled'=>true,'parameters'=>[]];
    $parent['placements']=[$placement, array_replace($placement,['instance_id'=>'footer','slot'=>'bottom','sort'=>10,'expected_revision'=>null]),
        array_replace($placement,['instance_id'=>'second','sort'=>30]), array_replace($placement,['instance_id'=>'disabled','slot'=>'unmapped','enabled'=>false,'sort'=>40])];
    $source = $projectionStore->create($parent,'actor:projection');
    $before = $source->artifact;
    $result = $projector->project($source,'actor:projection',['body'=>'main','bottom'=>'footer','empty'=>'aside']);
    assert(array_column($result['main'],'placement_id')===['first','second']);
    assert($result['main'][0]['artifact']===['artifact_id'=>'block.shared','revision'=>1]);
    assert($result['footer'][0]['placement_id']==='footer');
    assert($result['aside']===[]);
    assert($projectionStore->readRevision($scope,'page.generic',1,'actor:projection')->artifact===$before);
    $changed = $before; $changed['placements'][0]['instance_id']='forged';
    $forged = new LayoutArtifactRevision($source->artifactId,$scope,$source->kind,$source->revision,$changed,$source->semanticHash);
    foreach ([[$forged,'actor:projection',['body'=>'main','bottom'=>'footer']],[$source,'actor:denied',['body'=>'main','bottom'=>'footer']],
        [$source,'actor:projection',['body'=>'main']],[$source,'actor:projection',['body'=>'main','bottom'=>'main']]] as [$candidate,$actor,$map]) {
        try { $projector->project($candidate,$actor,$map); throw new RuntimeException('Invalid or unauthorized region projection accepted'); }
        catch (LayoutRejected) {}
    }
    $unsupported = $before; $unsupported['artifact_id']='page.parameters'; $unsupported['placements'][0]['parameters']=['title'=>'must-not-be-lost'];
    $unsupportedSource = $projectionStore->create($unsupported,'actor:projection');
    try { $projector->project($unsupportedSource,'actor:projection',['body'=>'main','bottom'=>'footer']); throw new RuntimeException('Placement parameters silently discarded'); }
    catch (LayoutRejected $exception) { assert($exception->reasonCode==='layout_region_projection_placement_unsupported'); }
}
echo "ArtifactRegionPlacementProjectorTest passed: two scopes, named regions, reuse, pinned/published references, empty and disabled slots, source immutability and refusals.\n";
