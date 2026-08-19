<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\SiteDescriptor;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\MinimalCmsRenderPlanRuntime;
use Larena\Layout\Runtime\PageDescriptorComponentRegistry;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;

$runtime = new MinimalCmsRenderPlanRuntime(new PageAssemblyDescriptorNormalizer(
    new PageDescriptorComponentRegistry(['layout.section'], ['ui.input']),
));
$site = new SiteDescriptor('site.main', 'scope:site.main', ['page.home'], ['region.main']);
$page = [
    'schema' => PageAssemblyDescriptorNormalizer::SCHEMA,
    'site_id' => 'site.main',
    'page_id' => 'page.home',
    'scope_ref' => 'scope:site.main',
    'layout_id' => 'layout.default',
    'regions' => [[
        'id' => 'region.main',
        'sort' => 10,
        'sections' => [[
            'id' => 'section.hero', 'component' => 'layout.section', 'view' => 'default', 'preset' => null, 'modifiers' => [], 'props' => [],
            'sort' => 10,
            'blocks' => [[
                'id' => 'block.title', 'component' => 'ui.input', 'view' => 'default', 'preset' => null, 'modifiers' => [], 'props' => [],
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

$first = $runtime->plan($site, $page);
$second = $runtime->plan($site, $page);
assert($first->toArray() === $second->toArray());
assert($first->siteId === 'site.main');
assert($first->pageId === 'page.home');
assert($first->regions[0]['sections'][0]['blocks'][0]['component'] === 'ui.input');

$reject = static function (callable $operation, string $reason): void {
    try {
        $operation();
        throw new RuntimeException('Expected LayoutRejected: ' . $reason);
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason);
    }
};

$unknownRegion = $page;
$unknownRegion['regions'][0]['id'] = 'region.unknown';
$reject(static fn () => $runtime->plan($site, $unknownRegion), 'layout_render_plan_region_unknown');

$unknownComponent = $page;
$unknownComponent['regions'][0]['sections'][0]['blocks'][0]['component'] = 'ui.unknown';
$reject(static fn () => $runtime->plan($site, $unknownComponent), 'layout_descriptor_block_component_unknown');

$unknownSource = $page;
$unknownSource['regions'][0]['sections'][0]['blocks'][0]['bindings'][0]['kind'] = 'remote_service';
$reject(static fn () => $runtime->plan($site, $unknownSource), 'layout_page_assembly_binding_kind_unknown');

$wrongSite = new SiteDescriptor('site.other', 'scope:site.main', ['page.home'], ['region.main']);
$reject(static fn () => $runtime->plan($wrongSite, $page), 'layout_render_plan_site_mismatch');

echo "MinimalCmsRenderPlanTest passed.\n";
