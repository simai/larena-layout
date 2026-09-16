<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Closure;
use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\Exceptions\LayoutRejected;

/** Resolves derived authorized inputs; does not persist, publish or render them. */
final class RegionInheritanceResolver
{
    /**
     * The guard MUST verify scope, actor, contextual site/section/page membership,
     * source revision and equality with the owner's canonical layer.
     * @param Closure(array<string,mixed>,string,string):bool $sourceGuard
     * @param list<string> $registeredRegions
     */
    public function __construct(
        private readonly LayoutArtifactCatalog $catalog,
        private readonly Closure $sourceGuard,
        private readonly array $registeredRegions,
    ) {
    }

    /**
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function resolve(array $request, string $actor): array
    {
        $this->keys($request, ['schema', 'scope_ref', 'layers']);
        $scope = $request['scope_ref'] ?? null;
        $layers = $request['layers'] ?? null;
        if (($request['schema'] ?? null) !== 'larena.layout.region_inheritance_request.v1'
            || !is_string($scope) || !preg_match('/^scope:[a-z][a-z0-9_.:-]{1,119}$/D', $scope)
            || !is_array($layers) || !array_is_list($layers) || count($layers) !== 3 || $actor === '') {
            throw new LayoutRejected('layout_inheritance_request_invalid');
        }
        $shell = null;
        $regions = [];
        $origins = [];
        $dependencies = [];
        $sources = [];
        foreach (['site', 'section', 'page'] as $index => $level) {
            $layer = $layers[$index];
            if (!is_array($layer)) throw new LayoutRejected('layout_inheritance_layer_invalid');
            $this->keys($layer, ['level', 'source_id', 'revision', 'shell', 'regions']);
            if (($layer['level'] ?? null) !== $level || !$this->id($layer['source_id'] ?? null)
                || !$this->revision($layer['revision'] ?? null)
                || !is_array($layer['shell'] ?? null) || !is_array($layer['regions'] ?? null)
                || count($layer['regions']) > 100) {
                throw new LayoutRejected('layout_inheritance_layer_invalid');
            }
            if (!(($this->sourceGuard)($layer, $actor, $scope))) {
                throw new LayoutRejected('layout_inheritance_source_denied_or_stale');
            }
            $origin = ['level' => $level, 'source_id' => $layer['source_id'], 'revision' => $layer['revision']];
            $sources[] = $origin;
            $mode = $this->mode($layer['shell'], 'artifact');
            if ($mode === 'replace') {
                $shell = $this->artifact($layer['shell']['artifact'], $scope, $actor, $dependencies);
                $origins['shell'] = $origin;
            }
            foreach ($layer['regions'] as $region => $change) {
                if (!is_string($region) || !$this->id($region) || !in_array($region, $this->registeredRegions, true)
                    || !is_array($change)) throw new LayoutRejected('layout_inheritance_region_unknown');
                if ($this->mode($change, 'placements') === 'inherit') continue;
                $placements = $change['placements'];
                if (!is_array($placements) || !array_is_list($placements) || count($placements) > 1000) {
                    throw new LayoutRejected('layout_inheritance_placements_invalid');
                }
                $resolved = [];
                foreach ($placements as $placement) {
                    if (!is_array($placement)) throw new LayoutRejected('layout_inheritance_placement_invalid');
                    $this->keys($placement, ['placement_id', 'artifact']);
                    if (!$this->id($placement['placement_id'] ?? null)) throw new LayoutRejected('layout_inheritance_placement_invalid');
                    $resolved[] = ['placement_id' => $placement['placement_id'], 'artifact' => $this->artifact($placement['artifact'] ?? null, $scope, $actor, $dependencies)];
                }
                $regions[$region] = $resolved;
                $origins[$region] = $origin;
            }
        }
        if ($shell === null) throw new LayoutRejected('layout_inheritance_shell_missing');
        $ids = [];
        foreach ($regions as $placements) foreach ($placements as $placement) {
            $id = $placement['placement_id'];
            if (isset($ids[$id])) throw new LayoutRejected('layout_inheritance_placement_duplicate');
            $ids[$id] = true;
        }
        ksort($dependencies);
        return ['shell' => $shell, 'regions' => $regions, 'receipt' => [
            'scope_ref' => $scope, 'sources' => $sources, 'origins' => $origins,
            'artifacts' => array_values($dependencies),
        ]];
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed
     */
    private function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== [] || array_diff($allowed, array_keys($value)) !== []) {
            throw new LayoutRejected('layout_inheritance_fields_invalid');
        }
    }

    /** @param array<string,mixed> $change */
    private function mode(array $change, string $replacement): string
    {
        $mode = $change['mode'] ?? null;
        if (!in_array($mode, ['inherit', 'replace'], true)) throw new LayoutRejected('layout_inheritance_mode_invalid');
        $this->keys($change, $mode === 'replace' ? ['mode', $replacement] : ['mode']);
        return $mode;
    }

    private function id(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_.:-]{0,119}$/D', $value) === 1;
    }

    private function revision(mixed $value): bool
    {
        return is_int($value) && $value >= 1;
    }

    /**
     * @param array<string,array<string,mixed>> $dependencies
     * @return array{artifact_id:string,revision:int}
     */
    private function artifact(mixed $ref, string $scope, string $actor, array &$dependencies): array
    {
        if (!is_array($ref)) throw new LayoutRejected('layout_inheritance_artifact_invalid');
        $this->keys($ref, ['artifact_id', 'revision']);
        if (!$this->id($ref['artifact_id']) || !$this->revision($ref['revision'])) throw new LayoutRejected('layout_inheritance_artifact_invalid');
        $artifact = $this->catalog->readRevision($scope, $ref['artifact_id'], $ref['revision'], $actor);
        if ($artifact === null || $artifact->scopeRef !== $scope || $artifact->artifactId !== $ref['artifact_id'] || $artifact->revision !== $ref['revision']) {
            throw new LayoutRejected('layout_inheritance_artifact_unavailable');
        }
        $dependencies[$artifact->artifactId.'@'.$artifact->revision] = [
            'artifact_id' => $artifact->artifactId, 'revision' => $artifact->revision, 'semantic_hash' => $artifact->semanticHash,
        ];
        return ['artifact_id' => $artifact->artifactId, 'revision' => $artifact->revision];
    }
}
