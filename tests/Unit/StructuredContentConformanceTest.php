<?php

declare(strict_types=1);

require_once __DIR__.'/../Support/ConformanceHtml.php';

use Larena\Layout\StructuredContent\Renderer;
use Larena\Layout\StructuredContent\Validator;
use Larena\Layout\Tests\Support\ConformanceHtml;

/**
 * Every case of the SIMAI Framework's structured-content conformance kit, as its README says a renderer passes one:
 * validation agrees on validity, the HTML is equal under the comparison rule, the diagnostics are the same multiset
 * of (code, path), and the assets are the same set.
 *
 * @return list<string> the failures, one line each
 */
function structuredContentConformanceFailures(): array
{
    $kit = dirname(__DIR__).'/Fixtures/structured-content-conformance';
    $manifest = json_decode((string) file_get_contents($kit.'/manifest.json'), true, 64, JSON_THROW_ON_ERROR);
    $renderer = new Renderer();
    $failures = [];
    foreach ($manifest['cases'] as $id) {
        $case = json_decode((string) file_get_contents($kit.'/cases/'.$id.'.json'), false, 512, JSON_THROW_ON_ERROR);
        $document = $case->input->document;
        $rendered = property_exists($case->input, 'content')
            ? $renderer->render($document, content: $case->input->content)
            : $renderer->render($document);
        $expect = $case->expect;
        $valid = Validator::validate($document) === [];
        if ($valid !== $expect->valid) {
            $failures[] = $id.': valid is '.var_export($valid, true);
        }
        if (! ConformanceHtml::same($rendered->html, $expect->html)) {
            $failures[] = $id.": html\n  got:  ".ConformanceHtml::canonical($rendered->html)."\n  want: ".ConformanceHtml::canonical($expect->html);
        }
        $want = ConformanceHtml::diagnosticKeys(array_map(static fn (object $entry): array => ['code' => $entry->code, 'path' => $entry->path], $expect->diagnostics));
        $got = ConformanceHtml::diagnosticKeys($rendered->diagnostics);
        if ($got !== $want) {
            $failures[] = $id.': diagnostics '.json_encode($got).' want '.json_encode($want);
        }
        $assets = $rendered->assets;
        $expected = $expect->assets;
        sort($assets, SORT_STRING);
        sort($expected, SORT_STRING);
        if (array_values(array_unique($assets)) !== array_values(array_unique($expected))) {
            $failures[] = $id.': assets '.json_encode($assets).' want '.json_encode($expected);
        }
    }

    return $failures;
}

function runStructuredContentConformanceTest(): void
{
    $failures = structuredContentConformanceFailures();
    if ($failures !== []) {
        fwrite(STDERR, implode("\n", $failures)."\n");
    }
    assert($failures === [], count($failures).' structured-content conformance failures');
    echo "StructuredContentConformanceTest passed: every case of the kit.\n";
}

runStructuredContentConformanceTest();
