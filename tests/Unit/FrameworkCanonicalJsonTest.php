<?php

declare(strict_types=1);

use Larena\Layout\Runtime\FrameworkCanonicalJson;

function runFrameworkCanonicalJsonTest(): void
{
    $canonical = new FrameworkCanonicalJson();
    $vectors = [
        ['{}', '{}', 'sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a'],
        ['[]', '[]', 'sha256:4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945'],
        ['{"b":2,"a":1}', '{"a":1,"b":2}', 'sha256:43258cff783fe7036d8a43033f830adfc60ec037382473548ac742b888292777'],
        ['["Привет","😀","é","é"]', '["Привет","😀","é","é"]', 'sha256:bdc526db7b63a0cdd7633ce8e9019468990178a0007c1fec6069fd4b84604bec'],
        ['{"😀":1,"":2}', '{"😀":1,"":2}', 'sha256:04208f6cdb854e2ab1b07dd3633a39dec854344fe72824cf7f2fdb4e2e33129e'],
        ['{"10":"ten","2":"two","01":"leading","4294967295":"not-index"}', '{"2":"two","10":"ten","01":"leading","4294967295":"not-index"}', 'sha256:52437e1186cc8bb62f9c0e61d1bc92b3092f1bc96a2607d97da5bd05ac5b8c62'],
    ];
    foreach ($vectors as [$json, $expected, $digest]) {
        $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        assert($canonical->encode($value) === $expected);
        assert($canonical->digest($value) === $digest);
    }
    assert($canonical->encode("line separator ") === '"line'." ".'separator'." ".'"');
    echo "FrameworkCanonicalJsonTest passed.\n";
}

runFrameworkCanonicalJsonTest();
