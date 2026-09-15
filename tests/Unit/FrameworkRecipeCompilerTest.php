<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Runtime\FrameworkRecipeCompiler;
use Larena\Layout\Runtime\FrameworkRecipeRequestFactory;
use Larena\Layout\Runtime\PageCompositionNormalizer;

$node = getenv('SIMAI_NODE_BINARY');
$frameworkRoot = getenv('SIMAI_UI_ROOT');
if (! is_string($node) || $node === '' || ! is_string($frameworkRoot) || $frameworkRoot === '') {
    echo "FrameworkRecipeCompilerTest skipped: exact Framework candidate is not configured.\n";
    return;
}
$entry = realpath($frameworkRoot.'/distr/core/js/composition/index.mjs');
assert(is_string($entry));
$composition = (new PageCompositionNormalizer())->normalizeEditorBlocks([[
    'instance_id' => 'block_text_01', 'type' => 'text', 'enabled' => true, 'sort' => 100,
    'settings' => ['heading' => 'Статья', 'body' => 'Текст из существующего источника Larena.', 'alignment' => 'left'],
]]);
$resolved = [['instance_id' => 'block_text_01', 'settings' => ['heading' => 'Статья', 'body' => 'Текст из существующего источника Larena.', 'alignment' => 'left']]];
$factory = new FrameworkRecipeRequestFactory();
$compiler = FrameworkRecipeCompiler::fromFrameworkDistribution($node, $frameworkRoot);

$compact = $compiler->compile($factory->article($composition, $resolved, 'site-17', 'compact'));
assert(str_contains($compact['html'], 'Краткая шапка'));
assert(str_contains($compact['html'], '<h1'));
assert(str_contains($compact['html'], 'Текст из существующего источника Larena.'));
assert(! str_contains($compact['html'], 'Расширенная шапка'));
assert(count($compact['dependencyReceipt']['references']) === 2);
$compactAgain = $compiler->compile($factory->article($composition, $resolved, 'site-17', 'compact'));
assert($compactAgain['document'] === $compact['document']);
assert($compactAgain['dependencyReceipt']['documentDigest'] === $compact['dependencyReceipt']['documentDigest']);

$expanded = $compiler->compile($factory->article($composition, $resolved, 'site-17', 'expanded'));
assert(str_contains($expanded['html'], 'Расширенная шапка'));
assert($expanded['document'] !== $compact['document']);
assert($expanded['dependencyReceipt'] !== $compact['dependencyReceipt']);
assert($expanded['dependencyReceipt']['documentDigest'] !== $compact['dependencyReceipt']['documentDigest']);

$projection = [
    'schema' => 'larena.layout.page_projection',
    'scope_ref' => 'scope:minimal-cms',
    'page_id' => 'page.home',
    'regions' => [[
        'id' => 'region.main',
        'sections' => [[
            'id' => 'section.main',
            'component' => 'layout.section',
            'blocks' => [[
                'id' => 'block.title',
                'component' => 'ui.input',
                'bindings' => [[
                    'kind' => 'storage_record',
                    'target' => 'workbench.project_items|record-00000000000000000000000000000000',
                    'selector' => 'title',
                    'expected_revision' => 5,
                    'value' => ['values' => ['title' => 'Настоящая страница Larena']],
                ]],
            ]],
        ]],
    ]],
];
$boundHeading = $compiler->compile($factory->boundHeading($projection, 'scope:minimal-cms'));
assert(str_contains($boundHeading['html'], '<h1'));
assert(str_contains($boundHeading['html'], 'Настоящая страница Larena'));
assert(count($boundHeading['dependencyReceipt']['references']) === 1);

$unsupported = $projection;
$unsupported['regions'][0]['sections'][0]['blocks'][0]['component'] = 'ui.unknown';
try {
    $factory->boundHeading($unsupported, 'scope:minimal-cms');
    throw new RuntimeException('An unsupported Larena component was accepted.');
} catch (InvalidArgumentException $exception) {
    assert($exception->getMessage() === 'layout_framework_recipe_projection_component_unsupported');
}

$denied = $factory->article($composition, $resolved, 'site-17', 'compact');
$denied['inputs']['values']['body']['origin']['scope'] = 'other-site';
try {
    $compiler->compile($denied);
    throw new RuntimeException('Cross-scope content was accepted.');
} catch (InvalidArgumentException $exception) {
    assert(str_starts_with($exception->getMessage(), 'layout_framework_recipe_failed:'));
}

try {
    (new FrameworkRecipeCompiler($node, $entry, 'sha256:'.str_repeat('0', 64)))->compile($factory->article($composition, $resolved, 'site-17', 'compact'));
    throw new RuntimeException('A stale Framework candidate was accepted.');
} catch (InvalidArgumentException $exception) {
    assert($exception->getMessage() === 'layout_framework_recipe_digest_mismatch');
}

echo "FrameworkRecipeCompilerTest passed.\n";
