<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Runtime\PageDescriptorNormalizer;
use Larena\Layout\Runtime\PageProjectionResolver;

$path = tempnam(sys_get_temp_dir(), 'larena-layout-');
if ($path === false) {
    throw new RuntimeException('Temporary database could not be created.');
}
$pdo = new PDO('sqlite:' . $path);
$store = new PdoPageDescriptorStore($pdo);
$store->install();
$descriptor = [
    'schema' => 'larena.layout.page_descriptor', 'schema_version' => 1,
    'page_id' => 'page.home', 'scope_ref' => 'scope:global', 'layout_id' => 'layout.default',
    'regions' => [[
        'id' => 'region.main', 'sort' => 100, 'sections' => [[
            'id' => 'section.main', 'component' => 'larena.section', 'sort' => 100, 'blocks' => [[
                'id' => 'block.content', 'component' => 'larena.content', 'sort' => 100, 'bindings' => [[
                    'id' => 'binding.content', 'kind' => 'content_document', 'target' => 'page.home', 'selector' => '$', 'expected_revision' => 1,
                ], [
                    'id' => 'binding.asset', 'kind' => 'logical_file', 'target' => '550e8400-e29b-41d4-a716-446655440000', 'selector' => 'logical_ref', 'expected_revision' => 1,
                ]],
            ]],
        ]],
    ]],
];
$created = $store->create($descriptor, 'user:admin.one');
assert($created->revision === 1);
$reopened = new PdoPageDescriptorStore(new PDO('sqlite:' . $path));
assert($reopened->read('scope:global', 'page.home', 'user:admin.one')?->semanticHash === $created->semanticHash);

$resolver = new class implements PageBindingOwnerResolver {
    public function resolve(array $binding, string $actor, string $scopeRef): mixed
    {
        if ($scopeRef !== 'scope:global') {
            throw new RuntimeException('scope mismatch');
        }
        return ['kind' => $binding['kind'], 'target' => $binding['target'], 'revision' => $binding['expected_revision']];
    }
};
$projection = (new PageProjectionResolver($reopened, $resolver))->project('scope:global', 'page.home', 'user:admin.one');
assert($projection['descriptor_hash'] === $created->semanticHash);
assert($projection['regions'][0]['sections'][0]['blocks'][0]['bindings'][0]['value']['kind'] === 'content_document');

$reordered = $descriptor;
$reordered['regions'][0]['sections'][0]['blocks'][0]['bindings'] = array_reverse($reordered['regions'][0]['sections'][0]['blocks'][0]['bindings']);
$normalizer = new PageDescriptorNormalizer();
assert($normalizer->hash($descriptor) !== $normalizer->hash($reordered));
$regionReordered = $descriptor;
$regionReordered['regions'][] = ['id' => 'region.first', 'sort' => 10, 'sections' => []];
assert($normalizer->normalize($regionReordered)['regions'][0]['id'] === 'region.first');

$updated = $descriptor;
$updated['layout_id'] = 'layout.revised';
assert($store->update($updated, 1, 'user:admin.one')->revision === 2);
try {
    $store->update($descriptor, 1, 'user:admin.one');
    throw new RuntimeException('Stale descriptor update was accepted.');
} catch (LayoutRejected $exception) {
    assert($exception->reasonCode === 'layout_descriptor_revision_conflict');
}

$invalidCases = [];
$unknown = $descriptor;
$unknown['regions'][0]['sections'][0]['blocks'][0]['component'] = 'larena.unknown';
$invalidCases[] = $unknown;
$duplicate = $descriptor;
$duplicate['regions'][0]['sections'][0]['blocks'][0]['id'] = 'section.main';
$invalidCases[] = $duplicate;
$crossScope = $descriptor;
$crossScope['scope_ref'] = 'tenant:secret';
$invalidCases[] = $crossScope;
$cycleLike = $descriptor;
$cycleLike['regions'][0]['sections'][0]['blocks'][0]['bindings'][0]['kind'] = 'page_descriptor';
$invalidCases[] = $cycleLike;
$unsafe = $descriptor;
$unsafe['regions'][0]['sections'][0]['blocks'][0]['bindings'][0]['selector'] = '<script>'; 
$invalidCases[] = $unsafe;
foreach ($invalidCases as $invalid) {
    try {
        $normalizer->normalize($invalid);
        throw new RuntimeException('Invalid descriptor was accepted.');
    } catch (LayoutRejected) {
    }
}

$pdo->exec("UPDATE larena_layout_page_descriptors SET semantic_hash = '" . str_repeat('0', 64) . "' WHERE scope_ref = 'scope:global' AND page_id = 'page.home'");
try {
    $store->read('scope:global', 'page.home', 'user:admin.one');
    throw new RuntimeException('Tampered descriptor was accepted.');
} catch (LayoutRejected $exception) {
    assert($exception->reasonCode === 'layout_descriptor_persisted_integrity_failed');
}

@unlink($path);
echo "PageDescriptorPersistenceTest passed.\n";
