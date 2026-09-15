<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LayoutArtifactNormalizer;

function runLayoutArtifactNormalizerTest(): void
{
    $normalizer = new LayoutArtifactNormalizer();
    $artifact = layoutArtifactFixture('block.notice', 'block', 'content.notice');
    $artifact['parameters'] = ['tone' => 'info', 'count' => 2];
    $normalized = $normalizer->normalize($artifact);
    assert($normalized['schema'] === LayoutArtifactNormalizer::SCHEMA);
    assert($normalizer->hash($artifact) === $normalizer->hash($normalized));
    assert(str_contains($normalizer->encode(layoutArtifactFixture('block.empty', 'block', 'content.notice')), '"parameters":{}'));

    $reordered = array_reverse($artifact, true);
    assert($normalizer->hash($artifact) === $normalizer->hash($reordered));

    foreach ([
        ['key' => 'password', 'value' => 'unsafe', 'reason' => 'layout_artifact_field_forbidden'],
        ['key' => 'copy', 'value' => '<script>alert(1)</script>', 'reason' => 'layout_artifact_value_forbidden'],
        ['key' => 'path', 'value' => '/private/site/key', 'reason' => 'layout_artifact_value_forbidden'],
    ] as $unsafe) {
        $candidate = $artifact;
        $candidate['parameters'] = [$unsafe['key'] => $unsafe['value']];
        rejectLayoutArtifact(static fn () => $normalizer->normalize($candidate), $unsafe['reason']);
    }

    $unknown = $artifact;
    $unknown['unexpected'] = true;
    rejectLayoutArtifact(static fn () => $normalizer->normalize($unknown), 'layout_artifact_unknown_key');

    $duplicate = $artifact;
    $duplicate['placements'] = [
        layoutPlacementFixture('same', 'block.child', 1),
        layoutPlacementFixture('same', 'block.other', 1),
    ];
    rejectLayoutArtifact(static fn () => $normalizer->normalize($duplicate), 'layout_artifact_placement_id_duplicate');

    echo "LayoutArtifactNormalizerTest passed.\n";
}

/** @return array<string,mixed> */
function layoutArtifactFixture(string $id, string $kind, string $component): array
{
    return [
        'schema' => 'larena.layout.artifact.v1',
        'artifact_id' => $id,
        'scope_ref' => 'scope:tenant-alpha',
        'kind' => $kind,
        'component' => $component,
        'presentation' => ['view' => 'default', 'variant' => null, 'modifiers' => []],
        'parameters' => [],
        'placements' => [],
        'bindings' => [],
        'asset_refs' => [],
        'extensions' => [],
    ];
}

/** @return array<string,mixed> */
function layoutPlacementFixture(string $instance, string $artifact, ?int $revision, string $slot = 'default', int $sort = 100): array
{
    return ['instance_id' => $instance, 'artifact_ref' => $artifact, 'expected_revision' => $revision, 'slot' => $slot, 'sort' => $sort, 'enabled' => true, 'parameters' => []];
}

function rejectLayoutArtifact(callable $operation, string $reasonPrefix): void
{
    try {
        $operation();
        throw new RuntimeException('Rejected layout artifact was accepted.');
    } catch (LayoutRejected $exception) {
        assert(str_starts_with($exception->reasonCode, $reasonPrefix), $exception->reasonCode);
        assert($exception->getPrevious() === null);
    }
}

runLayoutArtifactNormalizerTest();
