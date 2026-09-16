<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Exceptions\LayoutRejected;
use Throwable;

/** Projects owner-resolved values into Framework inputs, never into stored artifacts. */
final readonly class LayoutArtifactInputResolver
{
    /** @param array<string,array<string,array<string,mixed>>> $contracts */
    public function __construct(
        private PageBindingOwnerResolver $owners,
        private array $contracts,
        private FrameworkCanonicalJson $canonical = new FrameworkCanonicalJson(),
    ) {}

    /** @param array<string,mixed> $recipe @return array<string,mixed> */
    public function resolve(array $recipe, string $actor, string $scope): array
    {
        if (($recipe['schema'] ?? null) !== 'simai.composition.recipe.v1' || !is_array($recipe['root'] ?? null) || !empty($recipe['inputs'])) {
            throw new LayoutRejected('layout_artifact_input_recipe_invalid');
        }
        $values = [];
        $definitions = [];
        $receipt = [];
        $nodes = 0;
        $recipe['root'] = $this->entry($recipe['root'], $actor, $scope, $values, $definitions, $receipt, $nodes, 0);
        if ($definitions !== []) {
            $recipe['inputs'] = $definitions;
        }

        if (strlen($this->canonical->encode([$recipe, $values])) > 1048576) {
            throw new LayoutRejected('layout_artifact_input_document_limit');
        }

        return ['recipe' => $recipe, 'inputs' => ['schema' => 'simai.composition.inputs.v1', 'scope' => $scope, 'values' => $values], 'ownerReceipt' => array_values($receipt)];
    }

    /** @param array<string,mixed> $entry @param array<string,mixed> $values @param array<string,mixed> $definitions @param array<string,mixed> $receipt @return array<string,mixed> */
    private function entry(array $entry, string $actor, string $scope, array &$values, array &$definitions, array &$receipt, int &$nodes, int $depth): array
    {
        if ($depth > LayoutArtifactResolver::MAX_TREE_DEPTH || ++$nodes > LayoutArtifactResolver::MAX_TREE_NODES || !is_array($entry['node'] ?? null)) {
            throw new LayoutRejected('layout_artifact_input_tree_invalid');
        }
        $node = $entry['node'];
        $type = $node['type'] ?? '';
        $destinations = [];
        foreach ($node['extensions']['larena:artifact']['binding_refs'] ?? [] as $binding) {
            $contract = $this->contracts[$type][$binding['binding_id']] ?? null;
            if (!is_array($contract) || ($contract['kind'] ?? null) !== $binding['kind']
                || !in_array($contract['plane'] ?? null, ['data', 'props', 'presentation'], true)
                || !is_string($contract['field'] ?? null) || preg_match('/^[a-z][a-z0-9_]{0,119}$/D', $contract['field']) !== 1
                || !is_array($contract['schema'] ?? null) || !in_array($contract['format'] ?? null, ['value', 'inline_text'], true)) {
                throw new LayoutRejected('layout_artifact_input_contract_unknown');
            }
            $destination = $contract['plane'].'.'.$contract['field'];
            if (isset($destinations[$destination]) || isset($node[$contract['plane']][$contract['field']])) {
                throw new LayoutRejected('layout_artifact_input_destination_conflict');
            }
            $destinations[$destination] = true;
            try {
                $result = $this->owners->resolve($binding, $actor, $scope);
                $revision = $result->value['revision'] ?? null;
                if ($result->kind !== $binding['kind'] || !is_int($revision) || $revision < 1
                    || ($binding['expected_revision'] !== null && $revision !== $binding['expected_revision'])) {
                    throw new LayoutRejected('layout_artifact_input_revision_mismatch');
                }
                if (isset($result->value['scope_ref']) && $result->value['scope_ref'] !== $scope) {
                    throw new LayoutRejected('layout_artifact_input_scope_mismatch');
                }
                $value = $this->select($result->value, $binding['selector']);
                if ($contract['format'] === 'inline_text') {
                    if (!is_string($value)) {
                        throw new LayoutRejected('layout_artifact_input_text_invalid');
                    }
                    $value = [['type' => 'text', 'value' => $value]];
                }
                $this->safe($value, 0);
            } catch (Throwable $exception) {
                if ($exception instanceof LayoutRejected) {
                    throw $exception;
                }
                throw new LayoutRejected('layout_artifact_input_owner_unavailable');
            }
            $owner = match ($binding['kind']) {
                'content_document' => 'larena/content', 'storage_record' => 'larena/storage',
                'logical_file' => 'larena/filesystem', 'setting' => 'larena/setting',
                default => throw new LayoutRejected('layout_artifact_input_kind_unknown'),
            };
            $origin = ['scope' => $scope, 'owner' => $owner, 'ref' => $binding['target'].'#'.$binding['selector'], 'revision' => (string) $revision];
            $key = 'i-'.hash('sha256', $this->canonical->encode([$origin, $contract['format'], $contract['schema']]));
            $kind = $binding['kind'] === 'setting' ? 'setting' : 'content';
            $input = ['kind' => $kind, 'value' => $value, 'valueDigest' => $this->canonical->digest($value), 'origin' => $origin];
            if (isset($values[$key]) && $values[$key] !== $input) {
                throw new LayoutRejected('layout_artifact_input_owner_changed_during_resolution');
            }
            $values[$key] = $input;
            $definitions[$key] = ['kind' => $kind, 'schema' => $contract['schema'], 'expectedOrigin' => $origin];
            $receipt[$key] = $origin + ['valueDigest' => $input['valueDigest'],
                'sourceLayer' => $result->value['source_layer'] ?? 'owner',
                'resolvedRef' => $result->value['logical_ref'] ?? $result->value['document_id'] ?? $result->value['record_id'] ?? $result->value['key'] ?? $binding['target']];
            $node[$contract['plane']][$contract['field']] = ['input' => $key];
        }
        foreach ($node['slots'] ?? [] as $slot => $children) {
            foreach ($children as $index => $child) {
                $node['slots'][$slot][$index] = $this->entry($child, $actor, $scope, $values, $definitions, $receipt, $nodes, $depth + 1);
            }
        }
        $entry['node'] = $node;
        return $entry;
    }

    /** @param array<string,mixed> $value */
    private function select(array $value, string $selector): mixed
    {
        if ($selector === '$') {
            return $value;
        }
        foreach (explode('.', $selector) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                throw new LayoutRejected('layout_artifact_input_selector_unavailable');
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function safe(mixed $value, int $depth): void
    {
        if ($depth > 12 || is_object($value) || is_resource($value)) {
            throw new LayoutRejected('layout_artifact_input_value_unsafe');
        }
        if (is_string($value) && (strlen($value) > 65536 || preg_match('/<[^>]*>|<\?php|javascript\s*:|data\s*:/i', $value) === 1)) {
            throw new LayoutRejected('layout_artifact_input_value_unsafe');
        }
        if (is_array($value)) {
            if (count($value) > 256) {
                throw new LayoutRejected('layout_artifact_input_value_limit');
            }
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/(?:^|_)(?:html|password|token|session|secret|csrf|path|storage_key)(?:_|$)/i', $key) === 1) {
                    throw new LayoutRejected('layout_artifact_input_value_unsafe');
                }
                $this->safe($child, $depth + 1);
            }
        }
    }
}
