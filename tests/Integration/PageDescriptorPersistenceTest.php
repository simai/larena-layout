<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Runtime\PageDescriptorNormalizer;
use Larena\Layout\Runtime\PageProjectionResolver;
use Larena\Layout\ValueObjects\PageBindingResult;

function runPageDescriptorPersistenceTest(): void
{
$path = tempnam(sys_get_temp_dir(), 'larena-layout-');
assert(is_string($path));
$policy = new LayoutScopePolicy([
    'actor:alpha' => ['scope:tenant-alpha'],
    'actor:beta' => ['scope:tenant-beta'],
]);
$pdo = new PDO('sqlite:' . $path);
$store = new PdoPageDescriptorStore($pdo, $policy);
$store->install();
$descriptor = descriptor();
$created = $store->create($descriptor, 'actor:alpha');
assert($created->revision === 1);
$reopened = new PdoPageDescriptorStore(new PDO('sqlite:' . $path), $policy);
assert($reopened->read('scope:tenant-alpha', 'page.home', 'actor:alpha')?->semanticHash === $created->semanticHash);
assert($created->descriptor['schema'] === 'larena.layout.page_assembly.v1');

$pdo->beginTransaction();
$nestedDescriptor = $descriptor;
$nestedDescriptor['page_id'] = 'page.nested';
assert($store->create($nestedDescriptor, 'actor:alpha')->revision === 1);
assert($pdo->inTransaction());
$pdo->rollBack();
assert($reopened->read('scope:tenant-alpha', 'page.nested', 'actor:alpha') === null);

$projection = (new PageProjectionResolver($reopened, new LayoutOwnerFixture(), $policy))
    ->project('scope:tenant-alpha', 'page.home', 'actor:alpha');
assert($projection['descriptor_hash'] === $created->semanticHash);
assert($projection['regions'][0]['sections'][0]['blocks'][0]['bindings'][0]['value']['document_id'] === 'page.home');

$normalizer = new PageDescriptorNormalizer();
$reordered = $descriptor;
$reordered['regions'][0]['sections'][0]['blocks'][0]['bindings'] = array_reverse($reordered['regions'][0]['sections'][0]['blocks'][0]['bindings']);
assert($normalizer->hash($descriptor) !== $normalizer->hash($reordered));
$regionReordered = $descriptor;
$regionReordered['regions'][] = ['id' => 'region.first', 'sort' => 10, 'sections' => []];
assert($normalizer->normalize($regionReordered)['regions'][0]['id'] === 'region.first');

$projectionA = (new PageProjectionResolver($reopened, new LayoutOwnerFixture(false), $policy))
    ->project('scope:tenant-alpha', 'page.home', 'actor:alpha');
$projectionB = (new PageProjectionResolver($reopened, new LayoutOwnerFixture(true), $policy))
    ->project('scope:tenant-alpha', 'page.home', 'actor:alpha');
assert(json_encode($projectionA, JSON_THROW_ON_ERROR) === json_encode($projectionB, JSON_THROW_ON_ERROR));

$beforeForeign = state($pdo);
foreach ([
    static fn () => $store->read('scope:tenant-alpha', 'page.home', 'actor:beta'),
    static fn () => $store->update($descriptor, 1, 'actor:beta'),
    static fn () => (new PageProjectionResolver($store, new LayoutOwnerFixture(), $policy))->project('scope:tenant-alpha', 'page.home', 'actor:beta'),
] as $denied) {
    reject($denied, 'layout_scope_denied');
    assert(state($pdo) === $beforeForeign);
}

$updated = $descriptor;
$updated['layout_id'] = 'layout.revised';
assert($store->update($updated, 1, 'actor:alpha')->revision === 2);
$history = $store->history('scope:tenant-alpha', 'page.home', 'actor:alpha');
assert(array_map(static fn ($revision): int => $revision->revision, $history) === [2, 1]);
$rolledBack = $store->rollback('scope:tenant-alpha', 'page.home', 1, 2, 'actor:alpha');
assert($rolledBack->revision === 3);
assert($rolledBack->descriptor['layout_id'] === 'layout.default');
assert(array_map(static fn ($revision): int => $revision->revision, $store->history('scope:tenant-alpha', 'page.home', 'actor:alpha')) === [3, 2, 1]);
$beforeStale = state($pdo);
reject(static fn () => $store->update($descriptor, 2, 'actor:alpha'), 'layout_descriptor_revision_conflict');
assert(state($pdo) === $beforeStale);
reject(static fn () => $store->rollback('scope:tenant-alpha', 'page.home', 99, 3, 'actor:alpha'), 'layout_descriptor_revision_unknown');

foreach (['unsafe_storage_key', 'unsafe_path', 'unsafe_html', 'unsafe_code', 'unsafe_private', 'private_exception', 'kind_mismatch', 'fanout', 'aggregate'] as $mode) {
    try {
        (new PageProjectionResolver($store, new LayoutOwnerFixture(false, $mode), $policy))
            ->project('scope:tenant-alpha', 'page.home', 'actor:alpha');
        throw new RuntimeException('Unsafe owner projection was accepted: '.$mode);
    } catch (LayoutRejected $exception) {
        assert(in_array($exception->reasonCode, ['layout_binding_unavailable', 'layout_projection_size_limit_exceeded'], true));
        assert($exception->getPrevious() === null);
        assert(!str_contains($exception->getMessage(), 'PRIVATE_'));
        assert(!str_contains($exception->getMessage(), 'storage_key'));
    }
}
reject(static fn () => PageBindingResult::storageRecord([
    'schema_id' => 'catalog.product', 'record_id' => 'product.one', 'revision' => 1,
    'values' => [], 'unknown' => true,
]), 'layout_binding_result_shape_invalid');

$freshPath = tempnam(sys_get_temp_dir(), 'larena-layout-zero-');
assert(is_string($freshPath));
$fresh = new PDO('sqlite:' . $freshPath);
$freshStore = new PdoPageDescriptorStore($fresh, $policy);
$invalid = descriptor();
$invalid['unknown'] = true;
$zeroBefore = state($fresh);
reject(static fn () => $freshStore->create($invalid, 'actor:alpha'), 'layout_descriptor_unknown_key');
assert(state($fresh) === $zeroBefore);
assert($fresh->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'larena_layout_%'")->fetchColumn() === 0);

$pdo->exec("UPDATE larena_layout_page_descriptors SET semantic_hash = '" . str_repeat('0', 64) . "' WHERE scope_ref = 'scope:tenant-alpha' AND page_id = 'page.home'");
reject(static fn () => $store->read('scope:tenant-alpha', 'page.home', 'actor:alpha'), 'layout_descriptor_persisted_integrity_failed');

@unlink($path);
@unlink($freshPath);
echo "PageDescriptorPersistenceTest passed.\n";
}

/** @return array<string, mixed> */
function descriptor(): array
{
    $bindings = [
        ['id' => 'binding.content', 'kind' => 'content_document', 'target' => 'page.home', 'selector' => '$', 'expected_revision' => 1],
        ['id' => 'binding.record', 'kind' => 'storage_record', 'target' => 'catalog.product|product.one', 'selector' => '$', 'expected_revision' => 1],
        ['id' => 'binding.asset', 'kind' => 'logical_file', 'target' => '550e8400-e29b-41d4-a716-446655440000', 'selector' => '$', 'expected_revision' => 1],
        ['id' => 'binding.node', 'kind' => 'tree_node', 'target' => '650e8400-e29b-41d4-a716-446655440000', 'selector' => '$', 'expected_revision' => 1],
    ];

    return [
        'schema' => 'larena.layout.page_descriptor', 'schema_version' => 1,
        'page_id' => 'page.home', 'scope_ref' => 'scope:tenant-alpha', 'layout_id' => 'layout.default',
        'regions' => [[
            'id' => 'region.main', 'sort' => 100, 'sections' => [[
                'id' => 'section.main', 'component' => 'larena.section', 'sort' => 100, 'blocks' => [[
                    'id' => 'block.content', 'component' => 'larena.content', 'sort' => 100, 'bindings' => $bindings,
                ]],
            ]],
        ]],
    ];
}

/** @return array{tables:list<string>,heads:list<array<string,mixed>>,versions:list<array<string,mixed>>} */
function state(PDO $pdo): array
{
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $heads = in_array('larena_layout_page_descriptors', $tables, true)
        ? $pdo->query('SELECT * FROM larena_layout_page_descriptors ORDER BY scope_ref, page_id')->fetchAll(PDO::FETCH_ASSOC)
        : [];
    $versions = in_array('larena_layout_page_descriptor_versions', $tables, true)
        ? $pdo->query('SELECT * FROM larena_layout_page_descriptor_versions ORDER BY scope_ref, page_id, revision')->fetchAll(PDO::FETCH_ASSOC)
        : [];

    return ['tables' => $tables, 'heads' => $heads, 'versions' => $versions];
}

function reject(callable $operation, string $reason): void
{
    try {
        $operation();
        throw new RuntimeException('Rejected operation was accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason);
        assert($exception->getPrevious() === null);
    }
}

final class LayoutScopePolicy implements PageDescriptorAuthorizationPolicy
{
    /** @param array<string, list<string>> $scopes */
    public function __construct(private array $scopes)
    {
    }

    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if (!in_array($operation, [self::CREATE, self::UPDATE, self::READ, self::PROJECT], true)
            || !in_array($scopeRef, $this->scopes[$actor] ?? [], true)) {
            throw new LayoutRejected('layout_scope_denied');
        }
    }
}

final class LayoutOwnerFixture implements PageBindingOwnerResolver
{
    public function __construct(private bool $reverseKeys = false, private string $mode = 'safe')
    {
    }

    public function resolve(array $binding, string $actor, string $scopeRef): PageBindingResult
    {
        if ($this->mode === 'private_exception') {
            throw new RuntimeException('PRIVATE_WIDGET storage_key=/private/blob');
        }
        if (str_starts_with($this->mode, 'unsafe_')) {
            $values = match ($this->mode) {
                'unsafe_storage_key' => ['storage_key' => 'blob.secret'],
                'unsafe_path' => ['title' => '/private/blob'],
                'unsafe_html' => ['title' => '<script>alert(1)</script>'],
                'unsafe_code' => ['code' => 'system()'],
                'unsafe_private' => ['private_metadata' => 'hidden'],
                default => throw new RuntimeException('unknown unsafe mode'),
            };
            return PageBindingResult::storageRecord([
                'schema_id' => 'catalog.product', 'record_id' => 'product.one', 'revision' => 1,
                'values' => $values,
            ]);
        }
        if ($this->mode === 'kind_mismatch') {
            return PageBindingResult::logicalFile([
                'logical_ref' => '550e8400-e29b-41d4-a716-446655440000', 'revision' => 1, 'public_id' => 'file.public',
                'display_name' => 'Image', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => 12, 'alt_text' => null,
            ]);
        }
        if ($this->mode === 'fanout') {
            $values = [];
            for ($index = 0; $index < 257; $index++) {
                $values['field_'.$index] = 'safe';
            }
            return PageBindingResult::storageRecord([
                'schema_id' => 'catalog.product', 'record_id' => 'product.one', 'revision' => 1,
                'values' => $values,
            ]);
        }
        if ($this->mode === 'aggregate') {
            return PageBindingResult::contentDocument([
                'document_id' => 'page.home', 'scope_ref' => $scopeRef, 'revision' => 1,
                'semantic_hash' => str_repeat('a', 64),
                'blocks' => array_fill(0, 200, ['type' => 'paragraph', 'data' => ['text' => str_repeat('x', 2_000)]]),
            ]);
        }

        $value = match ($binding['kind']) {
            'content_document' => PageBindingResult::contentDocument([
                'document_id' => 'page.home', 'scope_ref' => $scopeRef, 'revision' => 1,
                'semantic_hash' => str_repeat('a', 64), 'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hello']]],
            ]),
            'storage_record' => PageBindingResult::storageRecord([
                'schema_id' => 'catalog.product', 'record_id' => 'product.one', 'revision' => 1,
                'values' => ['count' => 2, 'title' => 'Product'],
            ]),
            'logical_file' => PageBindingResult::logicalFile([
                'logical_ref' => $binding['target'], 'revision' => 1, 'public_id' => 'file.public',
                'display_name' => 'Image', 'mime_type' => 'image/png', 'extension' => 'png', 'size_bytes' => 12, 'alt_text' => 'Alt',
            ]),
            'tree_node' => PageBindingResult::treeNode([
                'node_ref' => $binding['target'], 'revision' => 1, 'kind' => 'file_reference', 'name' => 'Image',
                'parent_ref' => null, 'logical_file_ref' => '550e8400-e29b-41d4-a716-446655440000', 'sibling_order' => 0, 'depth' => 0,
            ]),
            default => throw new RuntimeException('unknown kind'),
        };

        if (!$this->reverseKeys) {
            return $value;
        }

        return match ($value->kind) {
            'content_document' => PageBindingResult::contentDocument(array_reverse($value->value, true)),
            'storage_record' => PageBindingResult::storageRecord(array_reverse($value->value, true)),
            'logical_file' => PageBindingResult::logicalFile(array_reverse($value->value, true)),
            'tree_node' => PageBindingResult::treeNode(array_reverse($value->value, true)),
            default => throw new RuntimeException('unknown result kind'),
        };
    }
}

runPageDescriptorPersistenceTest();
