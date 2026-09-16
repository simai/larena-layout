<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LayoutArtifactInputResolver;
use Larena\Layout\ValueObjects\PageBindingResult;

$owner = new class implements PageBindingOwnerResolver {
    public string $text = 'Unicode Привет 👋';
    public int $revision = 2;
    public function resolve(array $binding, string $actor, string $scopeRef): PageBindingResult
    {
        if ($actor !== 'actor:allowed') throw new LayoutRejected('owner_denied');
        return PageBindingResult::storageRecord(['record_id' => 'record.demo', 'revision' => $this->revision, 'schema_id' => 'demo', 'values' => ['title' => $this->text]]);
    }
};
$binding = ['binding_id' => 'title', 'kind' => 'storage_record', 'target' => 'demo|record.demo', 'selector' => 'values.title', 'expected_revision' => 2];
$recipe = ['schema' => 'simai.composition.recipe.v1', 'id' => 'page.demo', 'profile' => 'ui-layout', 'root' => ['id' => 'heading', 'node' => ['type' => 'content.heading', 'extensions' => ['larena:artifact' => ['binding_refs' => [$binding]]]]]];
$contracts = ['content.heading' => ['title' => ['kind' => 'storage_record', 'plane' => 'data', 'field' => 'content', 'format' => 'inline_text', 'schema' => ['type' => 'array']]]];
$resolver = new LayoutArtifactInputResolver($owner, $contracts);
$before = serialize($recipe);
$result = $resolver->resolve($recipe, 'actor:allowed', 'scope:demo');
assert(serialize($recipe) === $before);
assert(count($result['inputs']['values']) === 1);
$input = array_values($result['inputs']['values'])[0];
assert($input['origin']['revision'] === '2');
assert($input['origin']['owner'] === 'larena/storage');
assert($input['value'][0]['value'] === $owner->text);
assert(count($result['ownerReceipt']) === 1);
assert($result === $resolver->resolve($recipe, 'actor:allowed', 'scope:demo'));
foreach ([['actor:foreign', 'owner_denied'], ['actor:allowed', 'layout_artifact_input_revision_mismatch'], ['actor:allowed', 'layout_artifact_input_value_unsafe']] as $index => [$actor, $code]) {
    $owner->revision = $index === 1 ? 3 : 2;
    $owner->text = $index === 2 ? '<script>unsafe</script>' : 'safe';
    try { $resolver->resolve($recipe, $actor, 'scope:demo'); throw new RuntimeException('Unsafe input accepted.'); }
    catch (LayoutRejected $exception) { assert($exception->reasonCode === $code); }
}
echo "LayoutArtifactInputResolverTest passed.\n";
