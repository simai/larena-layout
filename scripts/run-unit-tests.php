<?php

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

$tests = [
    __DIR__ . '/../tests/Unit/LayoutContractTest.php',
    __DIR__ . '/../tests/Unit/LayoutFailsClosedTest.php',
    __DIR__ . '/../tests/Unit/InMemoryLayoutRuntimeTest.php',
    __DIR__ . '/../tests/Unit/InMemoryPageBuilderRuntimeTest.php',
];

foreach ($tests as $test) {
    require $test;
}

echo "Larena Layout unit tests passed.\n";
