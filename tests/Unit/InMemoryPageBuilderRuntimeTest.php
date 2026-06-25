<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Layout\Contracts\AtomicBlockPlacement;
use Larena\Layout\Contracts\LayoutBinding;
use Larena\Layout\Contracts\LayoutDescriptor;
use Larena\Layout\Contracts\LayoutProfile;
use Larena\Layout\Contracts\LayoutRegion;
use Larena\Layout\Contracts\PageDescriptor;
use Larena\Layout\Contracts\SectionDefinition;
use Larena\Layout\Contracts\SectionInstance;
use Larena\Layout\Enums\LayoutBindingScope;
use Larena\Layout\Enums\LayoutProfileCode;
use Larena\Layout\Runtime\InMemoryPageBuilderRuntime;

$profile = LayoutProfile::make(LayoutProfileCode::AdminPage, ['main']);
$layout = new LayoutDescriptor(
    'internal.page_builder_demo',
    $profile,
    [new LayoutRegion('main', ['cards.section'], true)],
    ['layout.internal.workbench'],
);
$page = new PageDescriptor(
    'internal.page_builder_demo',
    'larena.internal.layout-page-builder-demo',
    new LayoutBinding(LayoutBindingScope::AdminRoute, '/larena/internal/layout-page-builder-demo', 'internal.page_builder_demo', ['route']),
    $profile,
    [],
);
$definition = new SectionDefinition(
    'cards.section',
    'larena/layout',
    ['main'],
    ['section.title', 'cards.list'],
    [
        new AtomicBlockPlacement('atom.title_01', 'section.title', 'header', 100, 'provider', 'title'),
        new AtomicBlockPlacement('atom.cards_01', 'cards.list', 'main', 200, 'provider', 'items'),
    ],
    'cards.source',
    ['density' => ['type' => 'string', 'default' => 'compact']],
);
$instance = new SectionInstance(
    'section.cards_01',
    'cards.section',
    'main',
    100,
    true,
    $definition->defaultComposition,
    ['density' => 'compact'],
    ['cards.source' => 'fixture.cards'],
);
$fixtures = [
    'fixture.cards' => [
        'title' => '<script>alert(1)</script>Cards',
        'items' => ['A', 'B'],
    ],
];

$runtime = new InMemoryPageBuilderRuntime();
$preview = $runtime->preview($page, $layout, [$instance], ['cards.section' => $definition], $fixtures);

if (!$preview->isValid()) {
    fwrite(STDERR, 'Page builder preview is invalid.' . PHP_EOL);
    exit(1);
}

if (!str_contains($preview->html, 'data-larena-page-builder-preview="internal.page_builder_demo"')) {
    fwrite(STDERR, 'Page builder preview HTML is missing page marker.' . PHP_EOL);
    exit(1);
}

if (str_contains($preview->html, '<script>alert(1)</script>')) {
    fwrite(STDERR, 'Page builder preview rendered unsafe HTML.' . PHP_EOL);
    exit(1);
}

if (($preview->draftBoundary['publish_enabled'] ?? true) !== false) {
    fwrite(STDERR, 'Page builder preview enabled publish boundary.' . PHP_EOL);
    exit(1);
}

try {
    $runtime->preview($page, $layout, [$instance, $instance], ['cards.section' => $definition], $fixtures);
    fwrite(STDERR, 'Page builder accepted duplicate section instance id.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $exception) {
    assert(str_starts_with($exception->getMessage(), 'layout_page_builder_duplicate_section_instance:'));
}

$unknownDefinition = new SectionInstance(
    'section.unknown_01',
    'unknown.section',
    'main',
    100,
    true,
    $definition->defaultComposition,
    [],
    ['cards.source' => 'fixture.cards'],
);

try {
    $runtime->preview($page, $layout, [$unknownDefinition], ['cards.section' => $definition], $fixtures);
    fwrite(STDERR, 'Page builder accepted unknown section definition.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $exception) {
    assert(str_starts_with($exception->getMessage(), 'layout_page_builder_unknown_section_definition:'));
}

$unknownAtomic = new SectionInstance(
    'section.cards_02',
    'cards.section',
    'main',
    100,
    true,
    [new AtomicBlockPlacement('atom.unknown_01', 'unknown.block', 'main', 100, 'provider', 'title')],
    [],
    ['cards.source' => 'fixture.cards'],
);

try {
    $runtime->preview($page, $layout, [$unknownAtomic], ['cards.section' => $definition], $fixtures);
    fwrite(STDERR, 'Page builder accepted unknown atomic block.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $exception) {
    assert(str_starts_with($exception->getMessage(), 'layout_section_composer_unknown_atomic_block:'));
}

$unknownSource = new SectionInstance(
    'section.cards_03',
    'cards.section',
    'main',
    100,
    true,
    $definition->defaultComposition,
    [],
    ['cards.source' => 'fixture.missing'],
);

try {
    $runtime->preview($page, $layout, [$unknownSource], ['cards.section' => $definition], $fixtures);
    fwrite(STDERR, 'Page builder accepted unknown data source.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException $exception) {
    assert(str_starts_with($exception->getMessage(), 'layout_data_runtime_unknown_source:'));
}

echo "InMemoryPageBuilderRuntimeTest passed.\n";
