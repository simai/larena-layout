<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LegacyPageDescriptorAdapter;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageDescriptorComponentRegistry;
use Larena\Layout\Runtime\PageDescriptorNormalizer;

$registry = new PageDescriptorComponentRegistry(['admin.collection'], ['dataview.table', 'ui.input']);
$normalizer = new PageAssemblyDescriptorNormalizer($registry);
$example = json_decode((string) file_get_contents(__DIR__ . '/../../resources/examples/admin-collection.page-assembly.json'), true, 512, JSON_THROW_ON_ERROR);
assert(is_array($example));
$normalized = $normalizer->normalize($example);
assert($normalized['schema'] === PageAssemblyDescriptorNormalizer::SCHEMA);
assert($normalized['regions'][0]['sections'][0]['component'] === 'admin.collection');
assert($normalized['regions'][0]['sections'][0]['blocks'][0]['component'] === 'dataview.table');
assert($normalized['regions'][0]['sections'][0]['blocks'][0]['modifiers'] === ['filterable', 'searchable']);
assert($normalizer->hash($example) === $normalizer->hash($normalized));

$legacy = [
    'schema' => PageDescriptorNormalizer::SCHEMA,
    'schema_version' => PageDescriptorNormalizer::SCHEMA_VERSION,
    'page_id' => 'page.home',
    'scope_ref' => 'scope:site.main',
    'layout_id' => 'layout.default',
    'regions' => [[
        'id' => 'region.main',
        'sort' => 10,
        'sections' => [[
            'id' => 'section.hero',
            'component' => 'admin.collection',
            'sort' => 10,
            'blocks' => [[
                'id' => 'block.title',
                'component' => 'ui.input',
                'sort' => 10,
                'bindings' => [[
                    'id' => 'binding.title',
                    'kind' => 'storage_record',
                    'target' => 'structure.pages|record.home',
                    'selector' => 'title',
                    'expected_revision' => 1,
                ]],
            ]],
        ]],
    ]],
];
$adapter = new LegacyPageDescriptorAdapter(
    new PageDescriptorNormalizer($registry),
    $normalizer,
);
$adapted = $adapter->adapt($legacy, 'site.main');
assert($adapted['schema'] === PageAssemblyDescriptorNormalizer::SCHEMA);
assert($adapted['site_id'] === 'site.main');
assert($adapted['regions'][0]['sections'][0]['view'] === 'default');
assert($adapted['regions'][0]['sections'][0]['blocks'][0]['props'] === []);

$reject = static function (array $candidate, string $reason) use ($normalizer): void {
    try {
        $normalizer->normalize($candidate);
        throw new RuntimeException('Expected LayoutRejected: ' . $reason);
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason);
    }
};

$unsafe = $example;
$unsafe['regions'][0]['sections'][0]['blocks'][0]['props']['raw_html'] = '<script>alert(1)</script>';
$reject($unsafe, 'layout_page_assembly_prop_key_unsafe:props.raw_html');

$unknownViewShape = $example;
$unknownViewShape['regions'][0]['sections'][0]['blocks'][0]['renderer'] = 'ui.sf.element';
$reject($unknownViewShape, 'layout_page_assembly_unknown_key');

$unknownComponent = $example;
$unknownComponent['regions'][0]['sections'][0]['blocks'][0]['component'] = 'dataview.unknown';
$reject($unknownComponent, 'layout_descriptor_block_component_unknown');

echo "PageAssemblyDescriptorTest passed.\n";
