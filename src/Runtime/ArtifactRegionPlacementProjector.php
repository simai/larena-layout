<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;

/** Projects existing canonical placements; context membership remains the source owner's duty. */
final readonly class ArtifactRegionPlacementProjector
{
    public function __construct(private LayoutArtifactCatalog $sources, private LayoutArtifactCatalog $children) {}

    /**
     * The trusted slot map belongs to the product, never to a request-selected context.
     * Empty registered slots remain explicit empty regions. Parameter overrides are not
     * representable by the existing region-reference contract and must not be discarded.
     * @param array<string,string> $slotRegions
     * @return array<string,list<array{placement_id:string,artifact:array{artifact_id:string,revision:int}}>>
     */
    public function project(LayoutArtifactRevision $source, string $actor, array $slotRegions): array
    {
        if ($slotRegions === [] || count($slotRegions) > 100 || $actor === '') {
            throw new LayoutRejected('layout_region_projection_registration_invalid');
        }
        $this->validateRegistration($slotRegions);
        $regions = array_fill_keys(array_values($slotRegions), []);
        $canonical = $this->sources->readRevision($source->scopeRef, $source->artifactId, $source->revision, $actor);
        if ($canonical === null || $canonical->artifact !== $source->artifact || $canonical->kind !== $source->kind) {
            throw new LayoutRejected('layout_region_projection_source_unavailable');
        }
        $artifact = (new LayoutArtifactNormalizer())->normalize($canonical->artifact);
        // Preflight every enabled placement before reading any child.
        foreach ($artifact['placements'] as $placement) {
            if (!$placement['enabled']) continue;
            if (!isset($slotRegions[$placement['slot']]) || $placement['parameters'] !== []) {
                throw new LayoutRejected('layout_region_projection_placement_unsupported');
            }
        }
        foreach ($artifact['placements'] as $placement) {
            if (!$placement['enabled']) continue;
            $child = $placement['expected_revision'] === null
                ? $this->children->published($source->scopeRef, $placement['artifact_ref'], $actor)
                : $this->children->readRevision($source->scopeRef, $placement['artifact_ref'], $placement['expected_revision'], $actor);
            if ($child === null) throw new LayoutRejected('layout_region_projection_child_unavailable');
            $regions[$slotRegions[$placement['slot']]][] = [
                'placement_id' => $placement['instance_id'],
                'artifact' => ['artifact_id' => $child->artifactId, 'revision' => $child->revision],
            ];
        }
        return $regions;
    }

    /** Runtime boundary validation, including callers that bypass documented PHP types.
     * @param array<mixed> $slotRegions
     */
    private function validateRegistration(array $slotRegions): void
    {
        $regions = [];
        foreach ($slotRegions as $slot => $region) {
            if (!is_string($slot) || !is_string($region)
                || preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/D', $slot) !== 1
                || preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/D', $region) !== 1
                || array_key_exists($region, $regions)) {
                throw new LayoutRejected('layout_region_projection_registration_invalid');
            }
            $regions[$region] = [];
        }
    }

}
