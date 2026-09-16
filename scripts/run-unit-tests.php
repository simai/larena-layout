<?php

declare(strict_types=1);

require __DIR__ . '/../tests/bootstrap.php';

$tests = [
    __DIR__ . '/../tests/Unit/AdminLayoutRecipeTest.php',
    __DIR__ . '/../tests/Unit/LayoutContractTest.php',
    __DIR__ . '/../tests/Unit/LayoutFailsClosedTest.php',
    __DIR__ . '/../tests/Unit/InMemoryLayoutRuntimeTest.php',
    __DIR__ . '/../tests/Unit/InMemoryPageBuilderRuntimeTest.php',
    __DIR__ . '/../tests/Unit/PageCompositionRuntimeTest.php',
    __DIR__ . '/../tests/Unit/FrameworkCompositionProjectorTest.php',
    __DIR__ . '/../tests/Unit/FrameworkRecipeCompilerTest.php',
    __DIR__ . '/../tests/Unit/PageAssemblyDescriptorTest.php',
    __DIR__ . '/../tests/Unit/LayoutArtifactNormalizerTest.php',
    __DIR__ . '/../tests/Unit/LayoutArtifactResolverTest.php',
    __DIR__ . '/../tests/Unit/LayoutArtifactInputResolverTest.php',
    __DIR__ . '/../tests/Unit/FrameworkCanonicalJsonTest.php',
    __DIR__ . '/../tests/Unit/PackageLayoutArtifactCatalogTest.php',
    __DIR__ . '/../tests/Unit/FrameworkNodeRecipeResolverTest.php',
    __DIR__ . '/../tests/Unit/MinimalCmsRenderPlanTest.php',
    __DIR__ . '/../tests/Integration/PageDescriptorPersistenceTest.php',
    __DIR__ . '/../tests/Integration/LayoutArtifactPersistenceTest.php',
    __DIR__ . '/../tests/Integration/FrameworkRecipeSnapshotPersistenceTest.php',
];

foreach ($tests as $test) {
    require $test;
}

echo "Larena Layout unit tests passed.\n";
