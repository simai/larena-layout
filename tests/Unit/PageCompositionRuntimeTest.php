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
assert($composition->toArray()['schema'] === 'larena.layout.page_composition.v1');

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

echo "PageCompositionRuntimeTest passed.\n";
