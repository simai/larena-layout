<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../../src/Runtime/ArtifactRegionInheritanceModes.php';

use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\ArtifactRegionInheritanceModes;

$modes = new ArtifactRegionInheritanceModes;
$artifact = ['extensions' => []];
$before = serialize($artifact);
$defaults = ['aside' => 'inherit', 'main' => 'replace'];
$regions = ['main', 'aside'];

$effective = $modes->effective($artifact, $defaults, $regions);
assert($effective['main'] === ['mode' => 'replace', 'origin' => 'registration']);
assert($effective['aside'] === ['mode' => 'inherit', 'origin' => 'registration']);

$overridden = $modes->withOverride($artifact, 'main', 'empty', $regions);
assert($modes->effective($overridden, $defaults, $regions)['main'] === ['mode' => 'empty', 'origin' => 'artifact']);
$overridden = $modes->withOverride($overridden, 'aside', 'replace', $regions);
assert(array_keys($overridden['extensions'][ArtifactRegionInheritanceModes::EXTENSION]) === ['aside', 'main']);

$reset = $modes->withOverride($overridden, 'main', null, $regions);
assert($modes->effective($reset, $defaults, $regions)['main'] === ['mode' => 'replace', 'origin' => 'registration']);
$reset = $modes->withOverride($reset, 'aside', null, $regions);
assert($reset['extensions'] === []);
assert(serialize($artifact) === $before);

$invalid = [
    [fn () => $modes->effective(['extensions' => [ArtifactRegionInheritanceModes::EXTENSION => ['other' => 'inherit']]], $defaults, $regions), 'layout_region_modes_region_unknown'],
    [fn () => $modes->withOverride($artifact, 'main', 'append', $regions), 'layout_region_mode_invalid'],
    [fn () => $modes->effective($artifact, ['main' => 'replace'], $regions), 'layout_region_modes_registration_invalid'],
];
foreach ($invalid as [$operation, $reason]) {
    try {
        $operation();
        throw new RuntimeException('Invalid region mode accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === $reason);
    }
}

echo "ArtifactRegionInheritanceModesTest passed: defaults, inherit, replace, empty, reset, immutability and refusals.\n";
