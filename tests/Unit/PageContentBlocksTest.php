<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseManager;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Providers\LayoutServiceProvider;
use Larena\Layout\Runtime\PageAssemblyContentBlocks;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageContentBlockValidator;
use Larena\Layout\Runtime\PageDescriptorComponentRegistry;
use Larena\Layout\Runtime\StorageListPageBlocks;
use Larena\Layout\SiteTemplate\SiteTemplateCatalog;
use Larena\Property\Contracts\PropertyConstraintValidator;
use Larena\Property\Runtime\PropertyTypeRegistry;

// A site page's blocks in its record (owner decision q-4ede0beabe): Layout checks a content@1 value's blocks against
// its block components, the Property registry of the container does so through it, and a page assembly `site.<slug>`
// converts to the content@1 value the validator accepts.

$normalizer = new PageAssemblyDescriptorNormalizer(new PageDescriptorComponentRegistry(['layout.section'], ['larena.list', 'larena.content']));
$validator = PageContentBlockValidator::of($normalizer);

// Known types are the assembly's block components, every version but 1 is unknown.
assert($validator->knows('larena.list') && $validator->knows('larena.content') && !$validator->knows('acme.hero'));
assert($validator->validateBlock('acme.hero', 1, []) === ['property_content_block_unknown:acme.hero']);
assert($validator->validateBlock('larena.content', 2, []) === ['layout_block_version_unknown']);
assert($validator->validateBlock('larena.content', 1, []) === []);
assert($validator->validateBlock('larena.content', 1, ['title' => 'О нас', 'items' => [['label' => 'a']]]) === []);

// Params follow the assembly's props rules, named by their top-level param.
assert($validator->validateBlock('larena.content', 1, ['body_html' => 'x']) === ['layout_block_param_invalid:body_html']);
assert($validator->validateBlock('larena.content', 1, ['items' => [['note' => '<script>x</script>']]]) === ['layout_block_param_invalid:items']);
assert($validator->validateBlock('larena.content', 1, ['link' => 'javascript:alert(1)']) === ['layout_block_param_invalid:link']);
assert($validator->validateBlock('larena.content', 1, ['a', 'b']) === ['layout_block_params_invalid']);

// A list: its binding's target (required) and query as params, its other params are props.
$list = ['target' => 'site_node@v1', 'query' => ['sort' => [['field' => 'order_index', 'direction' => 'desc']], 'limit' => 5], 'title' => 'Новости', 'title_field' => 'title'];
assert($validator->validateBlock('larena.list', 1, $list) === []);
assert($validator->validateBlock('larena.list', 1, ['target' => 'news']) === []);
assert($validator->validateBlock('larena.list', 1, ['title' => 'Без цели']) === ['layout_block_param_invalid:target']);
assert($validator->validateBlock('larena.list', 1, ['target' => 'Bad Target']) === ['layout_block_param_invalid:target']);
assert($validator->validateBlock('larena.list', 1, ['target' => 'news', 'query' => ['limit' => 500]]) === ['layout_block_param_invalid:query']);
assert($validator->validateBlock('larena.list', 1, ['target' => 'news', 'query' => ['order' => 'x']]) === ['layout_block_param_invalid:query']);
assert($validator->validateBlock('larena.list', 1, ['target' => 'news', 'title' => '<b>x</b>']) === ['layout_block_param_invalid:title']);

// Through Property: an unknown type is Property's code, a known block carries Layout's.
$registry = PropertyTypeRegistry::builtIns()->withContentBlocks($validator);
$content = static fn (array $blocks) => $registry->normalizeAndValidate('content', 1, $blocks);
assert($content([['type' => 'larena.list', 'version' => 1, 'params' => $list], ['type' => 'larena.content', 'version' => 1, 'params' => []]])->canBePersistedByOwner());
assert($content([['type' => 'acme.hero', 'version' => 1, 'params' => []]])->errors === ['property_content_block_unknown:acme.hero']);
assert($content([['type' => 'larena.list', 'version' => 1, 'params' => ['title' => 'x']]])->errors === ['layout_block_param_invalid:target']);

// The provider extends the container's Property registry; the check asks the host's normalizer each time.
$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$app = new Container();
$app->instance(DatabaseManager::class, $capsule->getDatabaseManager());
(new ReflectionClass(LayoutServiceProvider::class))->newInstance($app)->register();
$app->singleton(PropertyTypeRegistry::class, static fn (): PropertyTypeRegistry => PropertyTypeRegistry::builtIns());
$app->alias(PropertyTypeRegistry::class, PropertyConstraintValidator::class);
$app->scoped(PageAssemblyDescriptorNormalizer::class, static fn (): PageAssemblyDescriptorNormalizer => $normalizer);
$bound = $app->make(PropertyConstraintValidator::class);
assert($bound instanceof PropertyTypeRegistry && $bound->contentBlocks() instanceof PageContentBlockValidator);
assert($bound->normalizeAndValidate('content', 1, [['type' => 'acme.hero', 'version' => 1, 'params' => []]])->errors === ['property_content_block_unknown:acme.hero']);
// Without the host's registry Layout's default components hold, larena.list among them.
$bare = new Container();
$bare->instance(DatabaseManager::class, $capsule->getDatabaseManager());
(new ReflectionClass(LayoutServiceProvider::class))->newInstance($bare)->register();
$bare->singleton(PropertyTypeRegistry::class, static fn (): PropertyTypeRegistry => PropertyTypeRegistry::builtIns());
assert($bare->make(PropertyTypeRegistry::class)->contentBlocks()?->knows('larena.list') === true);

// The converter: a site page assembly's content region, in page order, as content@1.
$assembly = StorageListPageBlocks::put(null, 'scope:site.main', 'site.news', 'site_node@v1', ['limit' => 5], 'Новости');
$assembly['regions'][0]['sections'][0]['blocks'][] = ['id' => 'block.intro', 'component' => 'larena.content', 'view' => 'default', 'preset' => null,
    'modifiers' => [], 'props' => ['title' => 'Вступление'], 'sort' => 50, 'bindings' => []];
$assembly['regions'][] = ['id' => 'region.footer', 'sort' => 200, 'sections' => [['id' => 'section.footer', 'component' => 'layout.section',
    'view' => 'default', 'preset' => null, 'modifiers' => [], 'props' => [], 'sort' => 100, 'blocks' => [['id' => 'block.footer',
        'component' => 'larena.content', 'view' => 'default', 'preset' => null, 'modifiers' => [], 'props' => ['title' => 'Подвал'], 'sort' => 100, 'bindings' => []]]]]];
$stored = $normalizer->normalize($assembly);
$blocks = PageAssemblyContentBlocks::fromAssembly($stored);
assert($blocks === [
    ['type' => 'larena.content', 'version' => 1, 'params' => ['title' => 'Вступление']],
    ['type' => 'larena.list', 'version' => 1, 'params' => [
        'query' => ['filters' => [], 'limit' => 5, 'search' => null, 'sort' => []], 'target' => 'site_node@v1', 'title' => 'Новости',
    ]],
], 'the converter does not read the content region in page order');
assert($content($blocks)->canBePersistedByOwner() && $content($blocks)->normalizedValue === $blocks);
assert(PageAssemblyContentBlocks::fromAssembly(null) === [] && PageAssemblyContentBlocks::fromAssembly(['regions' => []]) === []);

$refuses = static function (array $assembly, string $reason): void {
    try {
        PageAssemblyContentBlocks::fromAssembly($assembly);
    } catch (LayoutRejected $rejected) {
        assert($rejected->reasonCode === $reason, $rejected->reasonCode);

        return;
    }
    throw new RuntimeException('The converter accepted what content@1 cannot carry: ' . $reason);
};
$with = static function (array $change) use ($stored): array {
    foreach ($stored['regions'] as $r => $region) {
        foreach ($region['sections'] as $s => $section) {
            foreach ($section['blocks'] as $b => $block) {
                if ($block['id'] === 'block.list') {
                    $stored['regions'][$r]['sections'][$s]['blocks'][$b] = $change + $block;
                }
            }
        }
    }

    return $stored;
};
$refuses($with(['view' => 'compact']), 'layout_content_block_variant_unsupported:block.list');
$refuses($with(['modifiers' => ['wide']]), 'layout_content_block_variant_unsupported:block.list');
$refuses($with(['bindings' => []]), 'layout_content_block_binding_unsupported:block.list');
$refuses($with(['props' => ['target' => 'other']]), 'layout_content_block_param_conflict:block.list');
$refuses($with(['component' => 'larena.content', 'bindings' => [['id' => 'binding.doc', 'kind' => 'content_document', 'target' => 'doc.a', 'selector' => '$', 'expected_revision' => 1]]]),
    'layout_content_block_binding_unsupported:block.list');

// Rich text carries markup, which no prop may; a menu says where from and how deep.
$withBlocks = PageContentBlockValidator::of(new PageAssemblyDescriptorNormalizer(new PageDescriptorComponentRegistry(['layout.section'], ['larena.list', PageDescriptorComponentRegistry::TEXT, PageDescriptorComponentRegistry::MENU])));
assert($withBlocks->validateBlock(PageDescriptorComponentRegistry::TEXT, 1, ['body' => '<p>Text</p>']) === []);
assert($withBlocks->validateBlock(PageDescriptorComponentRegistry::TEXT, 1, []) === ['layout_block_param_invalid:body']);
assert($withBlocks->validateBlock(PageDescriptorComponentRegistry::TEXT, 1, ['body' => '<p>x</p>', 'other' => '<b>y</b>']) !== []);
assert($withBlocks->validateBlock(PageDescriptorComponentRegistry::MENU, 1, ['root' => 'top', 'depth' => 2, 'title' => 'Menu']) === []);
assert($withBlocks->validateBlock(PageDescriptorComponentRegistry::MENU, 1, ['root' => 'side']) === ['layout_block_param_invalid:root']);
assert($withBlocks->validateBlock(PageDescriptorComponentRegistry::MENU, 1, ['depth' => 9]) === ['layout_block_param_invalid:depth']);

// The templates: a package declares one, an unknown key reads as the default, and an area must be a known one.
$templates = new SiteTemplateCatalog();
assert($templates->find('no-such')['key'] === SiteTemplateCatalog::DEFAULT);
assert($templates->find(SiteTemplateCatalog::WITH_SIDEBAR)['areas'] === ['header', 'sidebar', 'footer']);
$templates->register('acme-wide', 'acme::wide', 'acme::wide', ['footer']);
assert(count($templates->all()) === 3);
$refused = false;
try {
    $templates->register('acme-wrong', 'acme::wrong', 'acme::wrong', ['middle']);
} catch (InvalidArgumentException) {
    $refused = true;
}
assert($refused, 'an unknown area is refused');

// What a page's move leaves behind: the blocks outside the content region.
assert(PageAssemblyContentBlocks::outsideContent(null) === 0);
assert(PageAssemblyContentBlocks::outsideContent(['regions' => [['id' => 'region.footer', 'sections' => [['id' => 's', 'sort' => 1, 'blocks' => [['id' => 'b', 'sort' => 1]]]]]]]) === 1);

echo "PageContentBlocksTest passed.\n";
