<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Exceptions\LayoutRejected;

/**
 * PHP adapter for the node-only Recipe subset emitted by LayoutArtifactResolver.
 * References, inputs and select remain owned by the full Framework resolver.
 */
final class FrameworkNodeRecipeResolver
{
    private int $nodes = 0;
    /** @var array<string,true> */
    private array $ids = [];

    /** @param array<string,array<string,mixed>> $manifests */
    public function __construct(private readonly array $manifests, private readonly FrameworkCanonicalJson $canonical = new FrameworkCanonicalJson()) {}

    /** @param array<string,mixed> $recipe @param array<string,string> $executionContract @return array<string,mixed> */
    public function resolve(array $recipe, string $scope, array $executionContract): array
    {
        $this->nodes = 0;
        $this->ids = [];
        if (($recipe['schema'] ?? null) !== 'simai.composition.recipe.v1'
            || !is_string($recipe['id'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $recipe['id']) !== 1
            || ($recipe['profile'] ?? null) !== 'ui-layout' || !is_array($recipe['root'] ?? null)
            || (isset($recipe['inputs']) && $recipe['inputs'] !== [])) {
            throw new LayoutRejected('layout_framework_node_recipe_invalid');
        }
        foreach (['contractDigest', 'registryDigest', 'rendererDigest'] as $key) {
            if (!is_string($executionContract[$key] ?? null) || $executionContract[$key] === '') {
                throw new LayoutRejected('layout_framework_execution_contract_invalid');
            }
        }
        $root = $this->entry($recipe['root'], [], $recipe['id'], 0);
        $document = array_filter([
            'schema' => 'simai.composition.document.v1',
            'id' => $recipe['id'],
            'profile' => 'ui-layout',
            'locale' => is_string($recipe['locale'] ?? null) ? $recipe['locale'] : null,
            'root' => $root,
        ], static fn (mixed $value): bool => $value !== null);
        $contract = $executionContract + ['canonicalization' => FrameworkCanonicalJson::PROFILE];
        return [
            'document' => $document,
            'dependencyReceipt' => [
                'schema' => 'simai.composition.dependencies.v1',
                'scope' => $scope,
                'recipeDigest' => $this->canonical->digest($recipe),
                'documentDigest' => $this->canonical->digest($document),
                'references' => [],
                'inputs' => [],
                'logicalPointers' => [],
                'executionContract' => $contract,
            ],
            'diagnostics' => [],
        ];
    }

    /** @param array<string,mixed> $entry @param list<array{0:string,1:string}> $segments @return array<string,mixed> */
    private function entry(array $entry, array $segments, string $recipeId, int $depth): array
    {
        if ($depth > LayoutArtifactResolver::MAX_TREE_DEPTH || ++$this->nodes > LayoutArtifactResolver::MAX_TREE_NODES
            || array_keys($entry) !== ['id', 'node'] || !is_string($entry['id'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $entry['id']) !== 1 || !is_array($entry['node'])) {
            throw new LayoutRejected('layout_framework_node_recipe_entry_invalid');
        }
        $source = $entry['node'];
        $type = $source['type'] ?? null;
        $manifest = is_string($type) ? ($this->manifests[$type] ?? null) : null;
        if (!is_array($manifest)) {
            throw new LayoutRejected('layout_framework_node_recipe_type_unknown');
        }
        $nodeSegments = [...$segments, ['node', $entry['id']]];
        $id = 'n-'.hash('sha256', $this->canonical->encode(['sf-composition-node-v1', $recipeId, $nodeSegments]));
        if (isset($this->ids[$id])) {
            throw new LayoutRejected('layout_framework_node_recipe_id_duplicate');
        }
        $this->ids[$id] = true;
        $node = ['id' => $id, 'type' => $type];
        foreach (['data', 'props', 'presentation'] as $field) {
            if (!isset($source[$field])) {
                continue;
            }
            if (!is_array($source[$field]) || array_is_list($source[$field])) {
                throw new LayoutRejected('layout_framework_node_recipe_value_invalid');
            }
            $node[$field] = [];
            foreach ($source[$field] as $name => $wrapper) {
                if (!is_array($wrapper) || array_keys($wrapper) !== ['literal']) {
                    throw new LayoutRejected('layout_framework_node_recipe_value_unsupported');
                }
                $node[$field][$name] = $wrapper['literal'];
            }
        }
        if (isset($source['extensions'])) {
            if (!is_array($source['extensions']) || array_is_list($source['extensions'])) {
                throw new LayoutRejected('layout_framework_node_recipe_extension_invalid');
            }
            $node['extensions'] = $source['extensions'];
        }
        $slots = $source['slots'] ?? [];
        if (!is_array($slots) || ($slots !== [] && array_is_list($slots))) {
            throw new LayoutRejected('layout_framework_node_recipe_slots_invalid');
        }
        $allowedSlots = is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($slots as $slot => $children) {
            if (!isset($allowedSlots[$slot]) || !is_array($children) || !array_is_list($children) || count($children) > 500) {
                throw new LayoutRejected('layout_framework_node_recipe_slot_unknown');
            }
            $node['slots'][$slot] = [];
            foreach ($children as $child) {
                if (!is_array($child)) {
                    throw new LayoutRejected('layout_framework_node_recipe_child_invalid');
                }
                $node['slots'][$slot][] = $this->entry($child, [...$nodeSegments, ['slot', (string) $slot]], $recipeId, $depth + 1);
            }
        }
        return $node;
    }
}
