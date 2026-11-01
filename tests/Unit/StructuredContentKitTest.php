<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/ConformanceHtml.php';

use Larena\Layout\Tests\Support\ConformanceHtml;

/**
 * The copy of the SIMAI Framework's structured-content conformance kit is the pinned one: every file and the
 * built-in type manifests hash as PIN.json says. And the PHP reading of the kit's comparison rule decides every pair
 * of normalization.json as the rule does, with the canonical form the kit gives.
 */
function runStructuredContentKitTest(): void
{
    $package = dirname(__DIR__, 2);
    $kit = $package.'/tests/Fixtures/structured-content-conformance';
    $pin = json_decode((string) file_get_contents($kit.'/PIN.json'), true, 32, JSON_THROW_ON_ERROR);
    assert(preg_match('/^[0-9a-f]{40}$/', $pin['source']['revision']) === 1);

    $listed = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kit, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($kit) + 1);
        if ($relative !== 'PIN.json') {
            $listed[] = $relative;
        }
    }
    sort($listed, SORT_STRING);
    $pinned = array_keys($pin['files']);
    sort($pinned, SORT_STRING);
    assert($listed === $pinned, 'the kit copy holds exactly the pinned files');
    foreach ($pin['files'] as $relative => $sha256) {
        assert(hash_file('sha256', $kit.'/'.$relative) === $sha256, $relative.' is the pinned file');
    }
    foreach ($pin['derived'] as $relative => $entry) {
        assert(hash_file('sha256', $package.'/'.$relative) === $entry['sha256'], $relative.' is the pinned derivation');
    }
    $manifest = json_decode((string) file_get_contents($kit.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    assert($manifest['version'] === $pin['kit_version']);
    assert(count($manifest['cases']) === $manifest['counts']['cases']);

    $normalization = json_decode((string) file_get_contents($kit.'/normalization.json'), true, 32, JSON_THROW_ON_ERROR);
    assert(count($normalization['vectors']) > 0);
    foreach ($normalization['vectors'] as $vector) {
        assert(ConformanceHtml::canonical($vector['left']) === $vector['canonical'][0], 'canonical left: '.$vector['label']);
        assert(ConformanceHtml::canonical($vector['right']) === $vector['canonical'][1], 'canonical right: '.$vector['label']);
        assert(ConformanceHtml::same($vector['left'], $vector['right']) === $vector['equal'], 'equal: '.$vector['label']);
    }

    echo "StructuredContentKitTest passed.\n";
}

runStructuredContentKitTest();
