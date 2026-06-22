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
use Larena\Layout\Contracts\SitePackLayoutManifest;
use Larena\Layout\Enums\LayoutBindingScope;
use Larena\Layout\Enums\LayoutProfileCode;

$adminProfileWithoutAccess = new LayoutProfile(LayoutProfileCode::AdminPage, ['main'], false, false);
assert(!$adminProfileWithoutAccess->isValid());

$validProfile = LayoutProfile::make(LayoutProfileCode::PublicPage, ['main']);
$duplicateRegions = new LayoutDescriptor('site.default', $validProfile, [new LayoutRegion('main'), new LayoutRegion('main')]);
assert(!$duplicateRegions->isValid());

$bindingWithoutTrace = new LayoutBinding(LayoutBindingScope::Page, '/about', 'site.default', []);
assert(!$bindingWithoutTrace->isValid());

$pageWithoutRoute = new PageDescriptor('about', '', new LayoutBinding(LayoutBindingScope::Page, '/about', 'site.default', ['page']), $validProfile);
assert(!$pageWithoutRoute->isValid());

$validLayout = new LayoutDescriptor('site.default', $validProfile, [new LayoutRegion('main')]);
$validPage = new PageDescriptor('about', 'public.about', new LayoutBinding(LayoutBindingScope::Page, '/about', 'site.default', ['page']), $validProfile);
$canonicalPlan = new ResolvedLayoutPlan($validPage, $validLayout, ['layout'], ['page' => 'about'], true);
assert(!$canonicalPlan->isValid());

$unvalidatedDraft = new LayoutDraft($validLayout, 'user:1', false);
assert(!$unvalidatedDraft->canPublish());

$unscopedDataSource = new DataSourceBinding('content.hero', 'larena/storage', 'storage.schema.hero', false);
assert(!$unscopedDataSource->isValid());

$phpManifest = new SitePackLayoutManifest('layout.v1', ['site.default'], [], true);
assert(!$phpManifest->isPortable());

$missingSettingsPlan = AdminSettingsDrivenLayoutPlan::fromSettingsReadModel(
    [
        'owner_package' => 'larena/setting',
        'status' => 'unavailable_optional_package_boundary',
        'field_count' => 0,
        'settings_write_allowed' => false,
        'database_write_allowed' => false,
    ],
    'larena.internal.package-owned-admin-frontend-read-only-route',
    '/larena/internal/package-owned-admin-frontend/read-only-route',
);

assert($missingSettingsPlan->isValid());
assert($missingSettingsPlan->cacheKeyPayload['settings_available'] === false);
assert($missingSettingsPlan->cacheKeyPayload['settings_field_count'] === 0);
assert($missingSettingsPlan->page->sections[2]->params['settings_available'] === false);
assert($missingSettingsPlan->page->sections[2]->params['write_actions_allowed'] === false);
