<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Contracts\PageBindingValidator;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageProjectionResolver;
use Larena\Layout\Runtime\StorageListBindingQuery;
use Larena\Layout\ValueObjects\PageBindingResult;

final class StorageListBindingTestPolicy implements PageDescriptorAuthorizationPolicy
{
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if ($actor !== 'actor:editor' || $scopeRef !== 'scope:site.main') {
            throw new LayoutRejected('layout_scope_denied');
        }
    }
}

final class StorageListBindingTestValidator implements PageBindingValidator
{
    /** @var list<array<string, mixed>> */
    private array $seen = [];

    public function reset(): void
    {
        $this->seen = [];
    }

    /** @return list<mixed> */
    public function kinds(): array
    {
        return array_column($this->seen, 'kind');
    }

    public function validate(array $binding, string $scopeRef, string $actor): void
    {
        $this->seen[] = $binding;
        if (($binding['kind'] ?? null) === 'storage_list' && isset($binding['query']['filters']['internal_note'])) {
            throw new LayoutRejected('layout_storage_list_query_invalid');
        }
    }
}

final class StorageListBindingTestOwner implements PageBindingOwnerResolver
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    public function reset(): void
    {
        $this->calls = [];
    }

    /** @return list<array<string, mixed>> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function resolve(array $binding, string $actor, string $scopeRef): PageBindingResult
    {
        $this->calls[] = $binding;
        if ($binding['kind'] === 'content_document') {
            return PageBindingResult::contentDocument([
                'document_id' => $binding['target'], 'scope_ref' => $scopeRef, 'revision' => 1,
                'semantic_hash' => str_repeat('a', 64), 'blocks' => [],
            ]);
        }

        return PageBindingResult::storageList([
            'target' => $binding['target'],
            'locale' => (string) ($binding['request']['locale'] ?? 'en'),
            'state' => 'ready',
            'continuation' => 'next-page-cursor',
            'records' => [
                ['record_id' => 'news-1', 'values' => ['title' => 'First', 'body' => '<p>markup</p>', 'raw_html' => 'x', 'order_index' => 2]],
                ['record_id' => 'news-2', 'values' => ['title' => 'Second']],
            ],
        ]);
    }
}

function storageListBindingTestReject(callable $operation, string $reason): void
{
    try {
        $operation();
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason, $exception->reasonCode . ' !== ' . $reason);

        return;
    }
    throw new RuntimeException('Expected LayoutRejected: ' . $reason);
}

/** @param array<string, mixed> $binding @return array<string, mixed> */
function storageListBindingTestPage(array $binding): array
{
    return [
        'schema' => PageAssemblyDescriptorNormalizer::SCHEMA,
        'schema_version' => PageAssemblyDescriptorNormalizer::SCHEMA_VERSION,
        'site_id' => 'site.main',
        'page_id' => 'site.news',
        'scope_ref' => 'scope:site.main',
        'layout_id' => 'layout.default',
        'regions' => [[
            'id' => 'region.main', 'sort' => 10, 'sections' => [[
                'id' => 'section.main', 'component' => 'larena.section', 'view' => 'default', 'preset' => null,
                'modifiers' => [], 'props' => [], 'sort' => 10, 'blocks' => [
                    [
                        'id' => 'block.intro', 'component' => 'larena.content', 'view' => 'default', 'preset' => null,
                        'modifiers' => [], 'props' => [], 'sort' => 10, 'bindings' => [[
                            'id' => 'binding.intro', 'kind' => 'content_document', 'target' => 'site.news',
                            'selector' => '$', 'expected_revision' => 1,
                        ]],
                    ],
                    [
                        'id' => 'block.news', 'component' => 'larena.list', 'view' => 'default', 'preset' => null,
                        'modifiers' => [], 'props' => ['title' => 'News'], 'sort' => 20, 'bindings' => [$binding],
                    ],
                ],
            ]],
        ]],
    ];
}

$binding = [
    'id' => 'binding.news',
    'kind' => 'storage_list',
    'target' => 'site_node@v1',
    'selector' => '$',
    'query' => [
        'sort' => [['field' => 'order_index', 'direction' => 'desc'], ['field' => '_published_at']],
        'filters' => ['order_index' => ['value' => 3, 'operator' => 'gte'], 'target' => ['operator' => 'in', 'values' => ['page', 'news']]],
        'limit' => 10,
    ],
];
$normalizer = new PageAssemblyDescriptorNormalizer();

// The binding is accepted with its query and normalized: defaults filled, keys ordered.
$normalized = $normalizer->normalize(storageListBindingTestPage($binding));
$list = $normalized['regions'][0]['sections'][0]['blocks'][1]['bindings'][0];
assert($list === [
    'id' => 'binding.news',
    'kind' => 'storage_list',
    'query' => [
        'filters' => ['order_index' => ['operator' => 'gte', 'value' => 3], 'target' => ['operator' => 'in', 'values' => ['page', 'news']]],
        'limit' => 10,
        'search' => null,
        'sort' => [['direction' => 'desc', 'field' => 'order_index'], ['direction' => 'asc', 'field' => '_published_at']],
    ],
    'selector' => '$',
    'target' => 'site_node@v1',
], json_encode($list));
assert(StorageListBindingQuery::normalize([])['limit'] === StorageListBindingQuery::DEFAULT_LIMIT);

// Shape errors are refused when the page is saved.
$invalid = [
    'expected revision' => [...$binding, 'expected_revision' => 1],
    'selector' => [...$binding, 'selector' => 'title'],
    'target' => [...$binding, 'target' => 'Site Pages'],
    'limit' => [...$binding, 'query' => ['limit' => 101]],
    'limit type' => [...$binding, 'query' => ['limit' => '10']],
    'four sorts' => [...$binding, 'query' => ['sort' => [['field' => 'a'], ['field' => 'b'], ['field' => 'c'], ['field' => 'd']]]],
    'repeated sort' => [...$binding, 'query' => ['sort' => [['field' => 'a'], ['field' => 'a', 'direction' => 'desc']]]],
    'direction' => [...$binding, 'query' => ['sort' => [['field' => 'a', 'direction' => 'up']]]],
    'filters list' => [...$binding, 'query' => ['filters' => [['operator' => 'eq']]]],
    'filter field' => [...$binding, 'query' => ['filters' => ['Title' => ['operator' => 'eq', 'value' => 'x']]]],
    'filter value' => [...$binding, 'query' => ['filters' => ['title' => ['operator' => 'eq', 'value' => ['nested']]]]],
    'filter key' => [...$binding, 'query' => ['filters' => ['title' => ['operator' => 'eq', 'value' => 'x', 'raw' => 1]]]],
    'unknown query key' => [...$binding, 'query' => ['columns' => ['title']]],
    'search' => [...$binding, 'query' => ['search' => str_repeat('x', 201)]],
    'no query' => array_diff_key($binding, ['query' => true]),
];
$reasons = [
    'expected revision' => 'layout_page_assembly_unknown_key',
    'selector' => 'layout_page_assembly_binding_selector_invalid',
    'target' => 'layout_page_assembly_binding_target_invalid',
    'no query' => 'layout_page_assembly_unknown_key',
];
foreach ($invalid as $case => $candidate) {
    storageListBindingTestReject(
        static fn () => $normalizer->normalize(storageListBindingTestPage($candidate)),
        $reasons[$case] ?? 'layout_storage_list_query_invalid',
    );
}

// The host checks every binding when the page is saved; a refusal writes nothing.
$path = tempnam(sys_get_temp_dir(), 'larena-layout-list-');
assert(is_string($path));
$pdo = new PDO('sqlite:' . $path);
$validator = new StorageListBindingTestValidator();
$policy = new StorageListBindingTestPolicy();
$store = new PdoPageDescriptorStore($pdo, $policy, bindings: $validator);
$store->install();
$hidden = $binding;
$hidden['query']['filters'] = ['internal_note' => ['operator' => 'eq', 'value' => 'x']];
storageListBindingTestReject(static fn () => $store->create(storageListBindingTestPage($hidden), 'actor:editor'), 'layout_storage_list_query_invalid');
assert($store->read('scope:site.main', 'site.news', 'actor:editor') === null, 'a refused page is not written');
$validator->reset();
$created = $store->create(storageListBindingTestPage($binding), 'actor:editor');
assert($created->revision === 1);
assert($validator->kinds() === ['content_document', 'storage_list'], 'every binding is checked');
storageListBindingTestReject(static fn () => $store->create(storageListBindingTestPage($binding), 'actor:intruder'), 'layout_scope_denied');

// Publishing projects the page without reading the list: its rows change without the page changing.
$owner = new StorageListBindingTestOwner();
$resolver = new PageProjectionResolver($store, $owner, $policy);
$projection = $resolver->project('scope:site.main', 'site.news', 'actor:editor');
assert(array_column($owner->calls(), 'kind') === ['content_document'], 'a list is not resolved into the projection');
$projected = $projection['regions'][0]['sections'][0]['blocks'][1]['bindings'][0];
assert($projected['kind'] === 'storage_list' && !array_key_exists('value', $projected));
assert($projection === $resolver->project('scope:site.main', 'site.news', 'actor:editor'));

// When the page is shown, the list is read with the request context; unsafe values are left out.
$owner->reset();
$page = $resolver->resolveRequestBinding($projected, 'actor:editor', 'scope:site.main', ['locale' => 'ru', 'cursor' => 'abc']);
assert(($owner->calls()[0]['request'] ?? null) === ['locale' => 'ru', 'cursor' => 'abc']);
assert($page['state'] === 'ready' && $page['locale'] === 'ru' && $page['continuation'] === 'next-page-cursor');
assert($page['records'][0] === ['record_id' => 'news-1', 'values' => ['order_index' => 2, 'title' => 'First']], json_encode($page['records'][0]));
assert($page['records'][1]['values'] === ['title' => 'Second']);
storageListBindingTestReject(
    static fn () => $resolver->resolveRequestBinding($normalized['regions'][0]['sections'][0]['blocks'][0]['bindings'][0], 'actor:editor', 'scope:site.main', []),
    'layout_binding_kind_unknown',
);
storageListBindingTestReject(
    static fn () => $resolver->resolveRequestBinding($projected, 'actor:editor', 'scope:site.main', ['cursor' => str_repeat('x', 4097)]),
    'layout_binding_request_invalid',
);
storageListBindingTestReject(
    static fn () => PageBindingResult::storageList(['target' => 'x', 'records' => []]),
    'layout_binding_result_shape_invalid',
);
@unlink($path);

echo "StorageListBindingTest passed.\n";
