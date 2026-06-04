<?php

declare(strict_types=1);

use Larena\Layout\Contracts\DataSourceBinding;
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
