<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\FileCompiledPageSnapshotStore;
use Larena\Layout\Runtime\FrameworkRecipeCompiler;
use Larena\Layout\Runtime\FrameworkRecipeRequestFactory;
use Larena\Layout\Runtime\FrameworkRecipeSnapshotPublisher;
use Larena\Layout\Runtime\PageCompositionNormalizer;

$node = getenv('SIMAI_NODE_BINARY');
$frameworkRoot = getenv('SIMAI_UI_ROOT');
if (! is_string($node) || $node === '' || ! is_string($frameworkRoot) || $frameworkRoot === '') {
    echo "FrameworkRecipeSnapshotPersistenceTest skipped: exact Framework candidate is not configured.\n";
    return;
}
$entry = realpath($frameworkRoot.'/distr/core/js/composition/index.mjs');
assert(is_string($entry));
$root = sys_get_temp_dir().'/larena-recipe-snapshots-'.bin2hex(random_bytes(8));
mkdir($root, 0755, true);

$remove = static function (string $path) use (&$remove): void {
    if (! is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entryName) {
        if ($entryName !== '.' && $entryName !== '..') {
            $remove($path.'/'.$entryName);
        }
    }
    rmdir($path);
};

try {
    $policy = new class implements PageDescriptorAuthorizationPolicy {
        public bool $revoked = false;

        public function assertAllowed(string $actor, string $operation, string $scopeRef): void
        {
            if ($this->revoked || $actor !== 'actor:editor' || $scopeRef !== 'site-17') {
                throw new LayoutRejected('layout_scope_denied');
            }
        }
    };
    $store = new FileCompiledPageSnapshotStore($root, $policy);
    $compiler = FrameworkRecipeCompiler::fromFrameworkDistribution($node, $frameworkRoot);
    $publisher = new FrameworkRecipeSnapshotPublisher($compiler, $store);
    $factory = new FrameworkRecipeRequestFactory();
    $composition = (new PageCompositionNormalizer())->normalizeEditorBlocks([[
        'instance_id' => 'block_text_01', 'type' => 'text', 'enabled' => true, 'sort' => 100,
        'settings' => ['heading' => 'Статья', 'body' => 'Содержимое снимка.', 'alignment' => 'left'],
    ]]);
    $resolved = [['instance_id' => 'block_text_01', 'settings' => ['body' => 'Содержимое снимка.']]];

    $compact = $publisher->publish('site-17', 'article.demo', 'content:1', $factory->article($composition, $resolved, 'site-17', 'compact'), null, 'actor:editor');
    assert($compact->activationRevision === 1);
    assert(str_contains($compact->snapshot['html'], 'Краткая шапка'));
    assert($store->active('site-17', 'article.demo', 'actor:editor')?->snapshotDigest === $compact->snapshotDigest);

    $expanded = $publisher->publish('site-17', 'article.demo', 'content:2', $factory->article($composition, $resolved, 'site-17', 'expanded'), 1, 'actor:editor');
    assert($expanded->activationRevision === 2);
    assert($expanded->snapshotDigest !== $compact->snapshotDigest);
    assert(str_contains($expanded->snapshot['html'], 'Расширенная шапка'));

    $rolledBack = $store->rollback('site-17', 'article.demo', $compact->snapshotDigest, 2, 'actor:editor');
    assert($rolledBack->activationRevision === 3);
    assert($rolledBack->snapshotDigest === $compact->snapshotDigest);
    assert(str_contains($rolledBack->snapshot['html'], 'Краткая шапка'));

    try {
        $publisher->publish('site-17', 'article.demo', 'content:3', $factory->article($composition, $resolved, 'site-17', 'expanded'), 2, 'actor:editor');
        throw new RuntimeException('A stale activation revision was accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === 'layout_snapshot_activation_conflict');
    }
    assert($store->active('site-17', 'article.demo', 'actor:editor')->activationRevision === 3);

    $brokenPublisher = new FrameworkRecipeSnapshotPublisher(
        new FrameworkRecipeCompiler($node, $entry, 'sha256:'.str_repeat('0', 64)),
        $store,
    );
    try {
        $brokenPublisher->publish('site-17', 'article.demo', 'content:4', $factory->article($composition, $resolved, 'site-17', 'expanded'), 3, 'actor:editor');
        throw new RuntimeException('A failed compilation changed the active snapshot.');
    } catch (InvalidArgumentException $exception) {
        assert($exception->getMessage() === 'layout_framework_recipe_digest_mismatch');
    }
    assert($store->active('site-17', 'article.demo', 'actor:editor')->snapshotDigest === $compact->snapshotDigest);

    $headPath = $root.'/'.hash('sha256', 'site-17').'/'.hash('sha256', 'article.demo').'/active.json';
    $validHead = file_get_contents($headPath);
    if (! is_string($validHead)) {
        throw new RuntimeException('The active pointer could not be read for its integrity check.');
    }
    file_put_contents($headPath, '{}');
    try {
        $store->active('site-17', 'article.demo', 'actor:editor');
        throw new RuntimeException('A malformed active pointer was accepted.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === 'layout_snapshot_head_invalid');
    }
    file_put_contents($headPath, $validHead);

    $policy->revoked = true;
    try {
        $store->active('site-17', 'article.demo', 'actor:editor');
        throw new RuntimeException('A revoked actor read the active snapshot.');
    } catch (LayoutRejected $exception) {
        assert($exception->reasonCode === 'layout_scope_denied');
    }
} finally {
    $remove($root);
}

echo "FrameworkRecipeSnapshotPersistenceTest passed.\n";
