<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

interface LayoutRuntime
{
    public function validateLayout(LayoutDescriptor $descriptor): bool;

    public function validatePage(PageDescriptor $descriptor): bool;

    public function resolvePlan(PageDescriptor $page, LayoutDescriptor $layout): ResolvedLayoutPlan;

    public function previewDraft(LayoutDraft $draft): ResolvedLayoutPlan;

    public function exportManifest(SitePackLayoutManifest $manifest): SitePackLayoutManifest;
}
