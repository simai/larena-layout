<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Runtime\FrameworkCompositionProjector;
use Larena\Layout\Runtime\PageCompositionNormalizer;

$source = [
    'schema' => 'larena.layout.page_composition',
    'layout_id' => 'docara.default',
    'sections' => [[
        'section_id' => 'docara.main', 'instance_id' => 'section_main', 'region_id' => 'main',
        'sort' => 100, 'enabled' => true, 'parameters' => ['width' => 'wide'],
        'blocks' => [[
            'block_id' => 'text', 'instance_id' => 'block_text_01', 'enabled' => false, 'sort' => 200,
            'parameters' => ['alignment' => 'left'], 'smart_view' => 'docara.text',
            'content_bindings' => [[
                'binding_id' => 'body', 'content_type' => 'docara.page_block', 'content_ref' => 'content-01',
                'field' => 'body', 'value_type' => 'text', 'expected_revision' => 7,
            ]], 'asset_refs' => [],
        ]],
    ]],
];
$composition = (new PageCompositionNormalizer())->normalizeDocument($source);
$before = $composition->toArray();
$document = (new FrameworkCompositionProjector())->project(
    $composition,
    [[
        'instance_id' => 'block_text_01', 'smart_view' => 'docara.text',
        'settings' => ['heading' => 'Projection', 'body' => 'Resolved body', 'alignment' => 'left'],
    ]],
    'docara:smart-page-demo',
    'en',
    ['docara.page_block' => 'larena/docara'],
);

assert($composition->toArray() === $before);
assert($document['schema'] === 'simai.composition.document.v1');
assert($document['extensions']['larena:page-composition']['source_schema'] === 'larena.layout.page_composition');
$section = $document['root']['slots']['default'][0];
$block = $section['slots']['default'][0];
assert($section['extensions']['larena:page-section']['region_id'] === 'main');
assert($block['id'] === 'block_text_01');
assert($block['props']['body'] === 'Resolved body');
assert($block['bindings'][0] === ['owner' => 'larena/docara', 'ref' => 'content-01', 'target' => 'body', 'revision' => '7']);
assert($block['extensions']['larena:page-block']['enabled'] === false);
assert($block['extensions']['larena:page-block']['content_bindings'][0]['value_type'] === 'text');

try {
    (new FrameworkCompositionProjector())->project($composition, [[
        'instance_id' => 'block_text_01', 'settings' => ['heading' => '', 'body' => '', 'alignment' => 'left'],
    ]], 'docara:smart-page-demo', 'en', []);
    throw new RuntimeException('Missing binding owner was accepted.');
} catch (InvalidArgumentException $exception) {
    assert($exception->getMessage() === 'layout_framework_composition_binding_owner_missing:docara.page_block');
}

echo "FrameworkCompositionProjectorTest passed.\n";
