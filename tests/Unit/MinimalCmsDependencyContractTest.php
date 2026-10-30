<?php

declare(strict_types=1);

$composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$larena = static function (array $dependencies): array {
    $names = array_values(array_filter(array_keys($dependencies), static fn (string $name): bool => str_starts_with($name, 'larena/')));
    sort($names, SORT_STRING);
    return $names;
};
$actualRequire = $larena($composer['require'] ?? []);
$actualRequireDev = $larena($composer['require-dev'] ?? []);
if ($actualRequire !== ['larena/core']) { throw new RuntimeException('Unexpected layout runtime dependencies: '.json_encode($actualRequire, JSON_THROW_ON_ERROR)); }
// Property is optional: Layout checks content@1 blocks where it is installed (suggested; a development requirement for that check's tests).
if ($actualRequireDev !== ['larena/property', 'larena/setting']) { throw new RuntimeException('Unexpected layout compatibility dependencies: '.json_encode($actualRequireDev, JSON_THROW_ON_ERROR)); }
if (!isset($composer['suggest']['larena/property'])) { throw new RuntimeException('larena/property is not suggested.'); }
echo "MinimalCmsDependencyContractTest passed.\n";
