<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\AdminCollectionLayoutPlan;
use Larena\Layout\Contracts\AdminFormLayoutPlan;
use Larena\Layout\Contracts\AdminLayoutRecipe;
use Larena\Layout\Contracts\AdminLayoutRegion;
use Larena\Layout\Contracts\LayoutRegion;
use Larena\Layout\Enums\LayoutProfileCode;
use Larena\Layout\Runtime\AdminLayoutRecipeRegistry;

$expectInvalidArgument = static function (callable $callback, string $expectedPrefix): void {
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        assert(str_starts_with($exception->getMessage(), $expectedPrefix));

        return;
    }

    throw new RuntimeException('Expected InvalidArgumentException with prefix ' . $expectedPrefix);
};

$registry = AdminLayoutRecipeRegistry::withDefaults();
assert(array_keys($registry->recipes()) === ['admin.collection', 'admin.form']);

$collection = $registry->recipe('admin.collection');
assert(AdminCollectionLayoutPlan::frameworkUtilityClasses() === [
    'utility.display' => ['flex'],
    'utility.flex-direction' => ['flex-col'],
    'utility.gap' => ['gap-1'],
    'utility.overflow' => ['overflow-x-auto'],
    'utility.width' => ['w-full'],
]);
assert($collection->validate());
assert($collection->profile === LayoutProfileCode::ListPage);
assert(array_map(static fn (AdminLayoutRegion $region): string => $region->key, $collection->regions) === [
    'heading',
    'toolbar',
    'content',
    'pagination',
]);
assert($collection->acceptsAssignments([
    'heading' => ['collection.heading'],
    'content' => ['collection.content'],
]));
assert($collection->acceptsAssignments([
    'heading' => ['collection.heading'],
    'toolbar' => ['collection.toolbar'],
    'content' => ['collection.content'],
    'pagination' => ['collection.pagination'],
]));
assert(!$collection->acceptsAssignments(['heading' => ['collection.heading']]));
assert(!$collection->acceptsAssignments([
    'heading' => ['collection.heading'],
    'unknown' => ['collection.unknown'],
    'content' => ['collection.content'],
]));
assert(!$collection->acceptsAssignments([
    'heading' => ['collection.shared'],
    'content' => ['collection.shared'],
]));
assert(!$collection->acceptsAssignments([
    'heading' => ['invalid invocation'],
    'content' => ['collection.content'],
]));
assert(!$collection->acceptsAssignments([
    'heading' => 'collection.heading',
    'content' => ['collection.content'],
]));
assert(!$collection->acceptsAssignments([
    0 => ['collection.heading'],
    'content' => ['collection.content'],
]));
assert(!$collection->acceptsAssignments([
    'heading' => [123],
    'content' => ['collection.content'],
]));
assert(!$collection->acceptsAssignments([
    'heading' => ['collection.heading'],
    'toolbar' => ['collection.toolbar.primary', 'collection.toolbar.secondary'],
    'content' => ['collection.content'],
]));

$collectionCompatibility = AdminCollectionLayoutPlan::standard();
assert($collectionCompatibility->isValid());
assert(array_map(static fn (LayoutRegion $region): string => $region->key, $collectionCompatibility->regions) === [
    'heading',
    'toolbar',
    'content',
    'pagination',
]);
assert(array_map(static fn (LayoutRegion $region): bool => $region->required, $collectionCompatibility->regions) === [
    true,
    false,
    true,
    false,
]);

$form = $registry->recipe('admin.form');
assert($form->validate());
assert($form->profile === LayoutProfileCode::FormPage);
assert($form->acceptsAssignments([
    'heading' => ['form.heading'],
    'fields' => ['form.field.title', 'form.field.status'],
    'actions' => ['form.action.save'],
]));
assert(!$form->acceptsAssignments([
    'heading' => ['form.heading'],
    'actions' => ['form.action.save'],
]));
assert(!$form->acceptsAssignments([
    'heading' => ['form.heading'],
    'notifications' => [
        'form.notice.one',
        'form.notice.two',
        'form.notice.three',
        'form.notice.four',
        'form.notice.five',
    ],
    'fields' => ['form.field.title'],
    'actions' => ['form.action.save'],
]));
assert(!$form->acceptsAssignments([
    'heading' => ['form.heading'],
    'fields' => array_map(static fn (int $index): string => 'form.field.field_' . $index, range(1, 65)),
    'actions' => ['form.action.save'],
]));
assert(!$form->acceptsAssignments([
    'heading' => ['form.heading'],
    'fields' => ['form.field.title'],
    'actions' => [
        'form.action.one',
        'form.action.two',
        'form.action.three',
        'form.action.four',
        'form.action.five',
    ],
]));

$formCompatibility = AdminFormLayoutPlan::standard();
assert($formCompatibility->isValid());
assert(array_map(static fn (LayoutRegion $region): string => $region->key, $formCompatibility->regions) === [
    'heading',
    'notifications',
    'fields',
    'actions',
]);
assert(array_map(static fn (LayoutRegion $region): bool => $region->required, $formCompatibility->regions) === [
    true,
    false,
    true,
    true,
]);

$unbounded = new AdminLayoutRegion('items', 0, null);
assert($unbounded->validate());
assert(!$unbounded->required());
assert($unbounded->acceptsCount(1000));
assert(!$unbounded->acceptsCount(-1));
assert(!(new AdminLayoutRegion('invalid region', 0, 1))->validate());
assert(!(new AdminLayoutRegion('items', -1, 1))->validate());
assert(!(new AdminLayoutRegion('items', 2, 1))->validate());
$expectInvalidArgument(
    static fn () => (new AdminLayoutRegion('invalid region', 0, 1))->toArray(),
    'layout_admin_recipe_invalid:region:invalid region',
);

$invalidKey = new AdminLayoutRecipe(
    'admin form',
    '1.0.0',
    LayoutProfileCode::FormPage,
    [new AdminLayoutRegion('fields', 1, 1)],
    true,
    true,
);
assert(!$invalidKey->validate());
$expectInvalidArgument(
    static fn () => $invalidKey->toArray(),
    'layout_admin_recipe_invalid:admin form',
);
assert(!(new AdminLayoutRecipe(
    'admin.form',
    '1.0',
    LayoutProfileCode::FormPage,
    [new AdminLayoutRegion('fields', 1, 1)],
    true,
    true,
))->validate());
assert(!(new AdminLayoutRecipe(
    'admin.form',
    '1.0.0',
    LayoutProfileCode::PublicPage,
    [new AdminLayoutRegion('fields', 1, 1)],
    true,
    true,
))->validate());
assert(!(new AdminLayoutRecipe(
    'admin.form',
    '1.0.0',
    LayoutProfileCode::FormPage,
    [new AdminLayoutRegion('fields', 1, 1), new AdminLayoutRegion('fields', 0, 1)],
    true,
    true,
))->validate());
assert(!(new AdminLayoutRecipe(
    'admin.form',
    '1.0.0',
    LayoutProfileCode::FormPage,
    [new AdminLayoutRegion('fields', 1, 1)],
    false,
    true,
))->validate());
assert(!(new AdminLayoutRecipe(
    'admin.form',
    '1.0.0',
    LayoutProfileCode::FormPage,
    [new AdminLayoutRegion('fields', 1, 1)],
    true,
    false,
))->validate());

$collectionProjection = $collection->toArray();
assert($collectionProjection['schema'] === 'larena.layout.admin_recipe.v1');
assert($collectionProjection['owner_package'] === 'larena/layout');
assert($collectionProjection['profile'] === 'list_page');
assert($collectionProjection['safety'] === [
    'requires_access_policy' => true,
    'requires_audit_for_effects' => true,
]);
assert($collectionProjection['regions'][0] === [
    'key' => 'heading',
    'min_items' => 1,
    'max_items' => 1,
    'required' => true,
]);
assert($collectionProjection === AdminCollectionLayoutPlan::recipe()->toArray());
assert($collection->region('heading')->toArray() === $collectionProjection['regions'][0]);

$expectInvalidArgument(
    static fn () => $collection->region('missing'),
    'layout_admin_recipe_unknown:region:missing',
);
$expectInvalidArgument(
    static fn () => $registry->recipe('admin.missing'),
    'layout_admin_recipe_unknown:admin.missing',
);
$expectInvalidArgument(
    static fn () => $registry->register(AdminCollectionLayoutPlan::recipe()),
    'layout_admin_recipe_collision:admin.collection',
);
$expectInvalidArgument(
    static fn () => (new AdminLayoutRecipeRegistry())->register($invalidKey),
    'layout_admin_recipe_invalid:admin form',
);

echo "AdminLayoutRecipeTest passed.\n";
