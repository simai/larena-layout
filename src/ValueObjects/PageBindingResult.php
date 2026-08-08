<?php

declare(strict_types=1);

namespace Larena\Layout\ValueObjects;

use Larena\Layout\Exceptions\LayoutRejected;

final readonly class PageBindingResult
{
    /** @param array<string, mixed> $value */
    private function __construct(public string $kind, public array $value)
    {
    }

    /** @param array<string, mixed> $value */
    public static function contentDocument(array $value): self
    {
        self::exactKeys($value, ['blocks', 'document_id', 'revision', 'scope_ref', 'semantic_hash']);

        return new self('content_document', $value);
    }

    /** @param array<string, mixed> $value */
    public static function storageRecord(array $value): self
    {
        self::exactKeys($value, ['record_id', 'revision', 'schema_id', 'values']);

        return new self('storage_record', $value);
    }

    /** @param array<string, mixed> $value */
    public static function logicalFile(array $value): self
    {
        self::exactKeys($value, ['alt_text', 'display_name', 'extension', 'logical_ref', 'mime_type', 'public_id', 'revision', 'size_bytes']);

        return new self('logical_file', $value);
    }

    /** @param array<string, mixed> $value */
    public static function treeNode(array $value): self
    {
        self::exactKeys($value, ['depth', 'kind', 'logical_file_ref', 'name', 'node_ref', 'parent_ref', 'revision', 'sibling_order']);

        return new self('tree_node', $value);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new LayoutRejected('layout_binding_result_shape_invalid');
        }
    }
}
