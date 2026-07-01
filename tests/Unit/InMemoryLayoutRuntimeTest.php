<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\LayoutBinding;
use Larena\Layout\Contracts\LayoutDescriptor;
use Larena\Layout\Contracts\LayoutProfile;
use Larena\Layout\Contracts\LayoutRegion;
use Larena\Layout\Contracts\PageDescriptor;
use Larena\Layout\Contracts\SectionCall;
use Larena\Layout\Enums\LayoutBindingScope;
use Larena\Layout\Enums\LayoutProfileCode;
use Larena\Layout\Runtime\InMemoryLayoutRuntime;

$runtime = new InMemoryLayoutRuntime();
$profile = LayoutProfile::make(LayoutProfileCode::AdminPage, ['topbar', 'sidebar', 'main']);
$layout = new LayoutDescriptor(
    'admin.frontend_conveyor',
    $profile,
    [
        new LayoutRegion('topbar', ['admin.header'], true),
        new LayoutRegion('sidebar', ['admin.navigation'], true),
        new LayoutRegion('main', ['admin.panel', 'admin.table'], true),
    ],
    ['admin.menu.smart', 'data.table.read_only_adapter'],
);
$page = new PageDescriptor(
    'admin.frontend_conveyor_demo',
    'larena.internal.frontend-conveyor-demo',
    new LayoutBinding(LayoutBindingScope::AdminRoute, '/larena/internal/frontend-conveyor-demo', 'admin.frontend_conveyor', ['route', 'layout_binding']),
    $profile,
    [
        new SectionCall('admin.header', 'topbar', ['title' => 'Frontend conveyor'], 'section.header_01'),
        new SectionCall('admin.navigation', 'sidebar', ['active' => 'frontend'], 'section.navigation_01'),
        new SectionCall('admin.panel', 'main', ['summary' => 'canonical demo'], 'section.panel_01'),
    ],
);

$plan = $runtime->resolvePlan($page, $layout);
assert($plan->isValid());
assert($plan->cacheKeyPayload['runtime'] === 'larena/layout:in_memory_layout_runtime');
assert($plan->cacheKeyPayload['section_instance_ids'] === ['section.header_01', 'section.navigation_01', 'section.panel_01']);
assert($plan->cacheKeyPayload['asset_requirements'] === ['admin.menu.smart', 'data.table.read_only_adapter']);

$unknownRegionPage = new PageDescriptor(
    'admin.frontend_conveyor_demo',
    'larena.internal.frontend-conveyor-demo',
    new LayoutBinding(LayoutBindingScope::AdminRoute, '/larena/internal/frontend-conveyor-demo', 'admin.frontend_conveyor', ['route']),
    $profile,
    [new SectionCall('admin.panel', 'missing', [], 'section.panel_01')],
);

try {
    $runtime->resolvePlan($unknownRegionPage, $layout);
    fwrite(STDERR, 'Layout runtime accepted unknown region.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $exception) {
    assert(str_starts_with($exception->getMessage(), 'layout_runtime_unknown_region:'));
}

$duplicatePage = new PageDescriptor(
    'admin.frontend_conveyor_demo',
    'larena.internal.frontend-conveyor-demo',
    new LayoutBinding(LayoutBindingScope::AdminRoute, '/larena/internal/frontend-conveyor-demo', 'admin.frontend_conveyor', ['route']),
    $profile,
    [
        new SectionCall('admin.panel', 'main', [], 'section.duplicate_01'),
        new SectionCall('admin.table', 'main', [], 'section.duplicate_01'),
    ],
);

try {
    $runtime->resolvePlan($duplicatePage, $layout);
    fwrite(STDERR, 'Layout runtime accepted duplicate section instance id.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $exception) {
    assert($exception->getMessage() === 'layout_runtime_invalid_page');
}

$missingInstancePage = new PageDescriptor(
    'admin.frontend_conveyor_demo',
    'larena.internal.frontend-conveyor-demo',
    new LayoutBinding(LayoutBindingScope::AdminRoute, '/larena/internal/frontend-conveyor-demo', 'admin.frontend_conveyor', ['route']),
    $profile,
    [new SectionCall('admin.panel', 'main')],
);

assert(!$runtime->validatePage($missingInstancePage));

echo "InMemoryLayoutRuntimeTest passed.\n";
