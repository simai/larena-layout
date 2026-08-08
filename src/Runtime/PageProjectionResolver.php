<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Contracts\PageDescriptorStore;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Throwable;

final readonly class PageProjectionResolver
{
    public const MAX_PROJECTION_BYTES = 262_144;
    public const MAX_PROJECTION_DEPTH = 12;
    public const MAX_PROJECTION_NODES = 4_096;
    public const MAX_CONTAINER_ITEMS = 256;

    public function __construct(
        private PageDescriptorStore $store,
        private PageBindingOwnerResolver $owners,
        private PageDescriptorAuthorizationPolicy $authorization,
    )
    {
    }

    /** @return array<string, mixed> */
    public function project(string $scopeRef, string $pageId, string $actor): array
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::PROJECT, $scopeRef);
        $revision = $this->store->read($scopeRef, $pageId, $actor);
        if ($revision === null) {
            throw new LayoutRejected('layout_descriptor_missing');
        }
        $descriptor = $revision->descriptor;
        $nodes = 0;
        foreach ($descriptor['regions'] as &$region) {
            foreach ($region['sections'] as &$section) {
                foreach ($section['blocks'] as &$block) {
                    foreach ($block['bindings'] as &$binding) {
                        try {
                            $result = $this->owners->resolve($binding, $actor, $scopeRef);
                            if ($result->kind !== $binding['kind']) {
                                throw new LayoutRejected('layout_binding_result_kind_mismatch');
                            }
                            $this->assertResultContract($result->kind, $result->value, $binding, $scopeRef);
                            $binding['value'] = $this->safeValue($result->value, 0, $nodes);
                        } catch (Throwable) {
                            throw new LayoutRejected('layout_binding_unavailable', 'A page binding could not be resolved.');
                        }
                    }
                    unset($binding);
                }
                unset($block);
            }
            unset($section);
        }
        unset($region);

        $projection = [
            'schema' => 'larena.layout.page_projection',
            'schema_version' => 1,
            'descriptor_revision' => $revision->revision,
            'descriptor_hash' => $revision->semanticHash,
            'page_id' => $revision->pageId,
            'scope_ref' => $revision->scopeRef,
            'layout_id' => $descriptor['layout_id'],
            'regions' => $descriptor['regions'],
        ];
        $projection = $this->canonicalize($projection);
        try {
            $encoded = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            throw new LayoutRejected('layout_projection_json_invalid');
        }
        if (strlen($encoded) > self::MAX_PROJECTION_BYTES) {
            throw new LayoutRejected('layout_projection_size_limit_exceeded');
        }

        return $projection;
    }

    /** @param array<string, mixed> $value @param array<string, mixed> $binding */
    private function assertResultContract(string $kind, array $value, array $binding, string $scopeRef): void
    {
        $revision = $value['revision'] ?? null;
        if (!is_int($revision) || $revision !== ($binding['expected_revision'] ?? null)) {
            throw new LayoutRejected('layout_binding_result_revision_mismatch');
        }

        $valid = match ($kind) {
            'content_document' => ($value['document_id'] ?? null) === ($binding['target'] ?? null)
                && ($value['scope_ref'] ?? null) === $scopeRef
                && is_string($value['semantic_hash'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $value['semantic_hash']) === 1
                && is_array($value['blocks'] ?? null)
                && array_is_list($value['blocks']),
            'storage_record' => $this->validStorageRecord($value, $binding),
            'logical_file' => ($value['logical_ref'] ?? null) === ($binding['target'] ?? null)
                && $this->nonEmptyString($value['public_id'] ?? null)
                && $this->nonEmptyString($value['display_name'] ?? null)
                && $this->nonEmptyString($value['mime_type'] ?? null)
                && $this->nonEmptyString($value['extension'] ?? null)
                && is_int($value['size_bytes'] ?? null)
                && $value['size_bytes'] >= 0
                && (is_string($value['alt_text'] ?? null) || ($value['alt_text'] ?? null) === null),
            'tree_node' => ($value['node_ref'] ?? null) === ($binding['target'] ?? null)
                && in_array($value['kind'] ?? null, ['folder', 'file_reference'], true)
                && $this->nonEmptyString($value['name'] ?? null)
                && $this->nullableUuid($value['parent_ref'] ?? null)
                && $this->nullableUuid($value['logical_file_ref'] ?? null)
                && is_int($value['sibling_order'] ?? null)
                && $value['sibling_order'] >= 0
                && is_int($value['depth'] ?? null)
                && $value['depth'] >= 0,
            default => false,
        };
        if (!$valid) {
            throw new LayoutRejected('layout_binding_result_shape_invalid');
        }
    }

    /** @param array<string, mixed> $value @param array<string, mixed> $binding */
    private function validStorageRecord(array $value, array $binding): bool
    {
        $target = explode('|', (string) ($binding['target'] ?? ''), 2);

        return count($target) === 2
            && ($value['schema_id'] ?? null) === $target[0]
            && ($value['record_id'] ?? null) === $target[1]
            && is_array($value['values'] ?? null)
            && !array_is_list($value['values']);
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function nullableUuid(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/\A[a-f0-9]{8}-[a-f0-9-]{27}\z/iD', $value) === 1);
    }

    private function safeValue(mixed $value, int $depth, int &$nodes): mixed
    {
        $nodes++;
        if ($depth > self::MAX_PROJECTION_DEPTH || $nodes > self::MAX_PROJECTION_NODES || is_object($value) || is_resource($value)) {
            throw new LayoutRejected('layout_binding_value_unsafe');
        }
        if (is_float($value) && (!is_finite($value))) {
            throw new LayoutRejected('layout_binding_value_unsafe');
        }
        if (is_string($value)) {
            if (strlen($value) > 65_536
                || preg_match('/<[^>]*>|<\?php|javascript\s*:|data\s*:\s*text\/html|PRIVATE_/i', $value) === 1
                || preg_match('#^(?:/|[A-Za-z]:\\\\|file://)#', $value) === 1
                || str_contains($value, 'larena/media/blobs/')) {
                throw new LayoutRejected('layout_binding_value_unsafe');
            }
        }
        if (is_array($value)) {
            if (count($value) > self::MAX_CONTAINER_ITEMS) {
                throw new LayoutRejected('layout_binding_fanout_limit_exceeded');
            }
            foreach ($value as $key => $child) {
                if (is_string($key) && preg_match('/(?:^|_)(?:path|disk|storage_key|private|secret|password|token|code|html|raw)(?:_|$)/i', $key) === 1) {
                    throw new LayoutRejected('layout_binding_key_unsafe');
                }
                $value[$key] = $this->safeValue($child, $depth + 1, $nodes);
            }
            return $this->canonicalize($value);
        }
        return $value;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
