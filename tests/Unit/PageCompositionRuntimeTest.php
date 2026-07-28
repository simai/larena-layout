<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Runtime\PageBlockCatalog;
use Larena\Layout\Runtime\PageCompositionNormalizer;

$catalog = new PageBlockCatalog();
assert(array_map(static fn ($definition): string => $definition->key, $catalog->all()) === ['text', 'image', 'hero', 'columns', 'cta']);
assert(count($catalog->editorSchema()) === 5);

$normalizer = new PageCompositionNormalizer($catalog);
$composition = $normalizer->normalize([
    ['instance_id' => 'block_cta_01', 'type' => 'cta', 'enabled' => '1', 'sort' => 500, 'settings' => ['title' => 'Act', 'body' => '', 'label' => 'Open', 'url' => '/open', 'style' => 'primary']],
    ['instance_id' => 'block_text_01', 'type' => 'text', 'enabled' => true, 'sort' => 100, 'settings' => ['heading' => 'Intro', 'body' => 'Body', 'alignment' => 'left']],
]);
assert($composition->isValid());
assert($composition->blocks[0]->type === 'text');
assert($composition->blocks[1]->smartView === 'docara.cta');
assert($composition->toArray()['schema'] === 'larena.layout.page_composition.v2');
assert($composition->layoutId === 'docara.default');
assert($composition->sections[0]->sectionId === 'docara.main');

$v2 = $normalizer->normalizeDocument([
    'schema' => 'larena.layout.page_composition.v2',
    'layout_id' => 'docara.article',
    'sections' => [[
        'section_id' => 'docara.content',
        'instance_id' => 'section_content',
        'region_id' => 'main',
        'sort' => 100,
        'enabled' => true,
        'parameters' => ['width' => 'wide'],
        'blocks' => [[
            'block_id' => 'image',
            'instance_id' => 'block_image_01',
            'enabled' => true,
            'sort' => 100,
            'parameters' => [],
            'smart_view' => 'docara.image',
            'content_bindings' => [[
                'binding_id' => 'alt',
                'content_type' => 'docara.page_block',
                'content_ref' => 'content-image-01',
                'field' => 'alt',
                'value_type' => 'string',
                'expected_revision' => 3,
            ], [
                'binding_id' => 'caption',
                'content_type' => 'docara.page_block',
                'content_ref' => 'content-image-01',
                'field' => 'caption',
                'value_type' => 'string',
                'expected_revision' => 3,
            ]],
            'asset_refs' => [[
                'asset_id' => 'image',
                'logical_ref' => '550e8400-e29b-41d4-a716-446655440000',
                'role' => 'file_ref',
            ]],
        ]],
    ]],
]);
assert($v2->isValid());
assert($v2->toArray() === $normalizer->normalizeDocument($v2->toArray())->toArray());
assert($v2->blocks[0]->contentBindings[0]->expectedRevision === 3);
assert($v2->blocks[0]->assetRefs[0]->logicalRef === '550e8400-e29b-41d4-a716-446655440000');

$convertedV1 = $normalizer->normalizeDocument([
    'schema' => 'larena.layout.page_composition.v1',
    'blocks' => [[
        'instance_id' => 'block_text_v1',
        'type' => 'text',
        'enabled' => true,
        'sort' => 100,
        'settings' => ['heading' => 'Legacy', 'body' => 'Preserved text', 'alignment' => 'left'],
    ]],
]);
assert($convertedV1->schema === 'larena.layout.page_composition.v2');
assert($convertedV1->blocks[0]->settings['body'] === 'Preserved text');

foreach ([
    [['instance_id' => 'block_1', 'type' => 'unknown', 'enabled' => true, 'settings' => []]],
    [['instance_id' => 'block_1', 'type' => 'text', 'enabled' => true, 'settings' => ['heading' => '', 'body' => '', 'alignment' => 'left']]],
    [['instance_id' => 'block_1', 'type' => 'cta', 'enabled' => true, 'settings' => ['title' => 'Unsafe', 'body' => '', 'label' => 'Run', 'url' => 'javascript:alert(1)', 'style' => 'primary']]],
    [
        ['instance_id' => 'block_same', 'type' => 'text', 'enabled' => true, 'settings' => ['heading' => '', 'body' => 'One', 'alignment' => 'left']],
        ['instance_id' => 'block_same', 'type' => 'text', 'enabled' => true, 'settings' => ['heading' => '', 'body' => 'Two', 'alignment' => 'left']],
    ],
] as $invalid) {
    try {
        $normalizer->normalize($invalid);
        throw new RuntimeException('Invalid composition was accepted.');
    } catch (InvalidArgumentException) {
    }
}

foreach ([
    ['schema' => 'larena.layout.page_composition.v2', 'layout_id' => 'docara.article', 'sections' => [[
        'section_id' => 'docara.content', 'instance_id' => 'section_content', 'region_id' => 'main', 'enabled' => true,
        'parameters' => ['html' => '<b>stored output</b>'], 'blocks' => [],
    ]]],
    ['schema' => 'larena.layout.page_composition.v2', 'layout_id' => 'docara.article', 'sections' => [[
        'section_id' => 'docara.content', 'instance_id' => 'section_content', 'region_id' => 'main', 'enabled' => true,
        'parameters' => [], 'blocks' => [[
            'block_id' => 'text', 'instance_id' => 'block_text_unsafe', 'enabled' => true, 'sort' => 100,
            'parameters' => ['html' => '<script>alert(1)</script>', 'alignment' => 'left'],
            'smart_view' => 'docara.text', 'content_bindings' => [
                ['binding_id' => 'body', 'content_type' => 'docara.page_block', 'content_ref' => 'unsafe', 'field' => 'body', 'value_type' => 'text', 'expected_revision' => 1],
            ], 'asset_refs' => [],
        ]],
    ]]],
] as $invalidDocument) {
    try {
        $normalizer->normalizeDocument($invalidDocument);
        throw new RuntimeException('Unsafe v2 document was accepted.');
    } catch (InvalidArgumentException) {
    }
}

echo "PageCompositionRuntimeTest passed.\n";
