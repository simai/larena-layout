<?php

declare(strict_types=1);

use Larena\Layout\Contracts\DataSourceBinding;
use Larena\Layout\Contracts\AdminSettingsDrivenLayoutPlan;
use Larena\Layout\Contracts\LayoutBinding;
use Larena\Layout\Contracts\LayoutDescriptor;
use Larena\Layout\Contracts\LayoutDraft;
use Larena\Layout\Contracts\LayoutProfile;
use Larena\Layout\Contracts\LayoutRegion;
use Larena\Layout\Contracts\PageDescriptor;
use Larena\Layout\Contracts\ResolvedLayoutPlan;
use Larena\Layout\Contracts\SectionCall;
use Larena\Layout\Contracts\SitePackLayoutManifest;
use Larena\Layout\Enums\LayoutBindingScope;
use Larena\Layout\Enums\LayoutProfileCode;

$profile = LayoutProfile::make(LayoutProfileCode::PublicPage, ['main']);
assert($profile->isValid());

$layout = new LayoutDescriptor('site.default', $profile, [new LayoutRegion('main', ['hero'], true)], ['core.assets.public']);
assert($layout->isValid());

$binding = new LayoutBinding(LayoutBindingScope::Page, '/about', 'site.default', ['site', 'section', 'page']);
assert($binding->isValid());

$section = new SectionCall('hero.default', 'main', ['title' => 'Example']);
assert($section->isValid());

$page = new PageDescriptor('about', 'public.about', $binding, $profile, [$section]);
assert($page->isValid());

$plan = new ResolvedLayoutPlan($page, $layout, ['binding:page', 'layout:site.default'], ['page' => 'about']);
assert($plan->isValid());

$draft = new LayoutDraft($layout, 'user:1', true);
assert($draft->canPublish());

$source = new DataSourceBinding('content.hero', 'larena/storage', 'storage.schema.hero', true);
assert($source->isValid());

$manifest = new SitePackLayoutManifest('layout.v1', ['site.default'], ['core.assets.public']);
assert($manifest->isPortable());

$settingsDrivenPlan = AdminSettingsDrivenLayoutPlan::fromSettingsReadModel(
    [
        'owner_package' => 'larena/setting',
        'status' => 'available',
        'field_count' => 5,
        'settings_write_allowed' => false,
        'database_write_allowed' => false,
    ],
    'larena.internal.package-owned-admin-frontend-read-only-route',
    '/larena/internal/package-owned-admin-frontend/read-only-route',
);

assert($settingsDrivenPlan->isValid());
assert($settingsDrivenPlan->page->pageKey === 'admin.settings_driven_read_only_route');
assert($settingsDrivenPlan->layout->layoutKey === 'admin.settings_driven_shell');
assert($settingsDrivenPlan->cacheKeyPayload['layout_source'] === 'settings_read_model');
assert($settingsDrivenPlan->cacheKeyPayload['settings_owner_package'] === 'larena/setting');
assert($settingsDrivenPlan->cacheKeyPayload['settings_available'] === true);
assert($settingsDrivenPlan->cacheKeyPayload['write_actions_allowed'] === false);
assert($settingsDrivenPlan->cacheKeyPayload['database_write_allowed'] === false);
assert($settingsDrivenPlan->page->sections[2]->sectionKey === 'settings_content');
assert($settingsDrivenPlan->page->sections[2]->params['settings_field_count'] === 5);
