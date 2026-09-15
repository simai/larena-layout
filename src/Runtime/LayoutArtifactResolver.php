<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;
use Larena\Layout\ValueObjects\LayoutPlacement;

final readonly class LayoutArtifactResolver
{
    public const MAX_TREE_DEPTH = 16;
    public const MAX_TREE_NODES = 1000;

    /** @param array<string, mixed> $manifests */
    public function __construct(
        private LayoutArtifactCatalog $catalog,
        private array $manifests,
    ) {
    }

    /** @return array{recipe:array<string,mixed>,dependencies:list<array<string,mixed>>,semantic_hashes:list<string>} */
    public function recipe(string $scopeRef, string $artifactId, ?int $revision, string $actor, string $locale = 'ru'): array
    {
        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{1,8})*$/D', $locale) !== 1) {
            throw new LayoutRejected('layout_artifact_locale_invalid');
        }
        $root = $revision === null
            ? $this->catalog->published($scopeRef, $artifactId, $actor)
            : $this->catalog->readRevision($scopeRef, $artifactId, $revision, $actor);
        if ($root === null) {
            throw new LayoutRejected($revision === null ? 'layout_artifact_not_published' : 'layout_artifact_revision_unknown');
        }
        if ($root->kind !== 'page') {
            throw new LayoutRejected('layout_artifact_root_kind_invalid');
        }

        $dependencies = [];
        $path = [];
        $count = 0;
        $entry = $this->entry($root, $root->artifactId, [], $actor, $dependencies, $path, $count, 0);
        $uniqueDependencies = [];
        foreach ($dependencies as $dependency) {
            $uniqueDependencies[$dependency['scope_ref'] . '|' . $dependency['artifact_id'] . '|' . $dependency['revision']] = $dependency;
        }
        $dependencies = array_values($uniqueDependencies);
        usort($dependencies, static fn (array $left, array $right): int => [$left['artifact_id'], $left['revision']] <=> [$right['artifact_id'], $right['revision']]);
        $semanticHashes = array_map(static fn (array $dependency): string => $dependency['semantic_hash'], $dependencies);

        return [
            'recipe' => [
                'schema' => 'simai.composition.recipe.v1',
                'id' => $root->artifactId,
                'profile' => 'ui-layout',
                'locale' => $locale,
                'root' => $entry,
                'extensions' => [
                    'larena:artifact-root' => [
                        'scope_ref' => $scopeRef,
                        'artifact_id' => $root->artifactId,
                        'revision' => $root->revision,
                    ],
                ],
            ],
            'dependencies' => $dependencies,
            'semantic_hashes' => $semanticHashes,
        ];
    }

    /**
     * @param array<string,mixed> $placementParameters
     * @param list<array<string,mixed>> $dependencies
     * @param array<string,true> $path
     * @return array<string,mixed>
     */
    private function entry(LayoutArtifactRevision $revision, string $instanceId, array $placementParameters, string $actor, array &$dependencies, array $path, int &$count, int $depth): array
    {
        if ($depth > self::MAX_TREE_DEPTH) {
            throw new LayoutRejected('layout_artifact_tree_depth_limit_exceeded');
        }
        $count++;
        if ($count > self::MAX_TREE_NODES) {
            throw new LayoutRejected('layout_artifact_tree_node_limit_exceeded');
        }
        $pathKey = $revision->artifactId . '@' . $revision->revision;
        if (isset($path[$pathKey])) {
            throw new LayoutRejected('layout_artifact_cycle_detected');
        }
        $path[$pathKey] = true;
        $artifact = $revision->artifact;
        $manifest = $this->manifest($artifact);
        $dependencies[] = [
            'scope_ref' => $revision->scopeRef,
            'artifact_id' => $revision->artifactId,
            'revision' => $revision->revision,
            'semantic_hash' => $revision->semanticHash,
        ];

        $slots = [];
        $slotCounts = [];
        foreach ($artifact['placements'] as $placementData) {
            $placement = LayoutPlacement::fromArray($placementData);
            if (!$placement->enabled) {
                continue;
            }
            $slotManifest = $manifest['slots'][$placement->slot] ?? null;
            if (!is_array($slotManifest)) {
                throw new LayoutRejected('layout_artifact_slot_unknown');
            }
            $child = $placement->expectedRevision === null
                ? $this->catalog->published($revision->scopeRef, $placement->artifactRef, $actor)
                : $this->catalog->readRevision($revision->scopeRef, $placement->artifactRef, $placement->expectedRevision, $actor);
            if ($child === null) {
                throw new LayoutRejected($placement->expectedRevision === null ? 'layout_artifact_child_not_published' : 'layout_artifact_child_revision_unknown');
            }
            if (!in_array($child->kind, $slotManifest['kinds'], true)) {
                throw new LayoutRejected('layout_artifact_slot_kind_rejected');
            }
            $allowedComponents = $slotManifest['components'] ?? [];
            if ($allowedComponents !== [] && !in_array($child->artifact['component'], $allowedComponents, true)) {
                throw new LayoutRejected('layout_artifact_slot_component_rejected');
            }
            $slotCounts[$placement->slot] = ($slotCounts[$placement->slot] ?? 0) + 1;
            if ($slotCounts[$placement->slot] > $slotManifest['max']) {
                throw new LayoutRejected('layout_artifact_slot_max_exceeded');
            }
            $slots[$placement->slot][] = $this->entry($child, $this->scopedId($instanceId, $placement->instanceId), $placement->parameters, $actor, $dependencies, $path, $count, $depth + 1);
        }
        foreach ($manifest['slots'] as $slot => $slotManifest) {
            $slotCount = $slotCounts[$slot] ?? 0;
            if ($slotCount < $slotManifest['min']) {
                throw new LayoutRejected('layout_artifact_slot_min_not_met');
            }
            $slots[$slot] ??= [];
        }
        ksort($slots, SORT_STRING);

        $parameters = $artifact['parameters'];
        $data = is_array($parameters['data'] ?? null) ? $parameters['data'] : [];
        $props = is_array($parameters['props'] ?? null) ? $parameters['props'] : $parameters;
        unset($props['data'], $props['props']);
        $props = array_replace_recursive($props, $placementParameters);
        $literalData = [];
        foreach ($data as $name => $value) {
            $literalData[$name] = ['literal' => $value];
        }
        $literalProps = [];
        foreach ($props as $name => $value) {
            $literalProps[$name] = ['literal' => $value];
        }
        $presentation = ['view' => ['literal' => $artifact['presentation']['view']]];
        if ($artifact['presentation']['variant'] !== null) {
            $presentation['preset'] = ['literal' => $artifact['presentation']['variant']];
        }
        if ($artifact['presentation']['modifiers'] !== []) {
            $presentation['modifiers'] = ['literal' => $artifact['presentation']['modifiers']];
        }

        return [
            'id' => $instanceId,
            'node' => array_filter([
                'type' => $artifact['component'],
                'data' => $literalData,
                'props' => $literalProps,
                'presentation' => $presentation,
                'slots' => $slots,
                'extensions' => [
                    'larena:artifact' => [
                        'artifact_id' => $revision->artifactId,
                        'kind' => $revision->kind,
                        'revision' => $revision->revision,
                        'binding_refs' => $artifact['bindings'],
                        'asset_refs' => $artifact['asset_refs'],
                    ],
                ],
            ], static fn (mixed $value): bool => $value !== []),
        ];
    }

    private function scopedId(string $parentId, string $localId): string
    {
        $candidate = $parentId . ':' . $localId;
        return strlen($candidate) <= 120 ? $candidate : 'n-' . hash('sha256', $candidate);
    }

    /** @param array<string,mixed> $artifact @return array{kinds:list<string>,views:list<string>,variants:list<string|null>,slots:array<string,array{min:int,max:int,kinds:list<string>,components:list<string>}>} */
    private function manifest(array $artifact): array
    {
        $component = (string) $artifact['component'];
        $manifest = $this->manifests[$component] ?? null;
        if (!is_array($manifest)
            || !isset($manifest['kinds'], $manifest['views'], $manifest['variants'], $manifest['slots'])
            || !is_array($manifest['kinds']) || !is_array($manifest['views']) || !is_array($manifest['variants']) || !is_array($manifest['slots'])) {
            throw new LayoutRejected('layout_artifact_component_unknown');
        }
        foreach ($manifest['kinds'] as $kind) {
            if (!is_string($kind) || !in_array($kind, ['page', 'section', 'block'], true)) {
                throw new LayoutRejected('layout_artifact_component_manifest_invalid');
            }
        }
        foreach ($manifest['views'] as $view) {
            if (!is_string($view)) {
                throw new LayoutRejected('layout_artifact_component_manifest_invalid');
            }
        }
        foreach ($manifest['variants'] as $variant) {
            if ($variant !== null && !is_string($variant)) {
                throw new LayoutRejected('layout_artifact_component_manifest_invalid');
            }
        }
        if (!in_array($artifact['kind'], $manifest['kinds'], true)) {
            throw new LayoutRejected('layout_artifact_component_kind_rejected');
        }
        if (!in_array($artifact['presentation']['view'], $manifest['views'], true)) {
            throw new LayoutRejected('layout_artifact_view_unknown');
        }
        if (!in_array($artifact['presentation']['variant'], $manifest['variants'], true)) {
            throw new LayoutRejected('layout_artifact_variant_unknown');
        }
        $slots = [];
        foreach ($manifest['slots'] as $slot => $slotManifest) {
            if (!is_string($slot) || !is_array($slotManifest)
                || !is_int($slotManifest['min'] ?? null) || !is_int($slotManifest['max'] ?? null)
                || $slotManifest['min'] < 0 || $slotManifest['max'] < $slotManifest['min'] || $slotManifest['max'] > 500
                || !is_array($slotManifest['kinds'] ?? null)) {
                throw new LayoutRejected('layout_artifact_component_manifest_invalid');
            }
            $kinds = [];
            foreach ($slotManifest['kinds'] as $kind) {
                if (!is_string($kind) || !in_array($kind, ['page', 'section', 'block'], true)) {
                    throw new LayoutRejected('layout_artifact_component_manifest_invalid');
                }
                $kinds[] = $kind;
            }
            $components = [];
            if (isset($slotManifest['components'])) {
                if (!is_array($slotManifest['components'])) {
                    throw new LayoutRejected('layout_artifact_component_manifest_invalid');
                }
                foreach ($slotManifest['components'] as $allowedComponent) {
                    if (!is_string($allowedComponent)) {
                        throw new LayoutRejected('layout_artifact_component_manifest_invalid');
                    }
                    $components[] = $allowedComponent;
                }
            }
            $slots[$slot] = ['min' => $slotManifest['min'], 'max' => $slotManifest['max'], 'kinds' => $kinds, 'components' => $components];
        }
        return ['kinds' => $manifest['kinds'], 'views' => $manifest['views'], 'variants' => $manifest['variants'], 'slots' => $slots];
    }
}
