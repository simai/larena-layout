<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\Enums\LayoutBindingScope;
use Larena\Layout\Enums\LayoutProfileCode;

final class AdminSettingsDrivenLayoutPlan
{
    /**
     * @param array<string, mixed> $settingsReadModel
     */
    public static function fromSettingsReadModel(
        array $settingsReadModel,
        string $routeKey,
        string $routeUri,
    ): ResolvedLayoutPlan {
        $profile = LayoutProfile::make(
            LayoutProfileCode::AdminPage,
            ['topbar', 'sidebar', 'workspace', 'drawer', 'modal', 'notifications'],
        );

        $fieldCount = max(0, (int) ($settingsReadModel['field_count'] ?? 0));
        $settingsStatus = (string) ($settingsReadModel['status'] ?? 'unknown');
        $settingsOwner = (string) ($settingsReadModel['owner_package'] ?? 'larena/setting');
        $settingsAvailable = $settingsStatus === 'available' && $fieldCount > 0;

        $layout = new LayoutDescriptor(
            'admin.settings_driven_shell',
            $profile,
            [
                new LayoutRegion('topbar', ['navigation_shell']),
                new LayoutRegion('sidebar', ['navigation_shell', 'settings_navigation']),
                new LayoutRegion('workspace', ['content', 'settings_table', 'settings_detail', 'diagnostics'], true),
                new LayoutRegion('drawer', ['drawer', 'settings_detail']),
                new LayoutRegion('modal', ['modal']),
                new LayoutRegion('notifications', ['notifications']),
            ],
            [
                'ui.admin_shell.build_manifest',
                'ui.admin_shell.smart_manifest',
                'ui.admin_shell.tokens',
                'setting.admin_form.read_model',
            ],
            ['token' => 'layout.page', 'variant' => 'admin_shell'],
            ['token' => 'layout.content', 'variant' => 'workspace'],
            [
                'before_content' => ['region' => 'topbar', 'source' => 'service.before_content'],
                'after_content' => ['region' => 'notifications', 'source' => 'service.after_content'],
            ],
            [
                'desktop' => ['primary_region' => 'workspace', 'flow' => 'shell_grid'],
                'tablet' => ['primary_region' => 'workspace', 'flow' => 'shell_stack'],
                'mobile' => ['primary_region' => 'workspace', 'flow' => 'shell_stack'],
            ],
        );

        $regionContent = [
            'topbar' => ['title' => 'Admin topbar', 'source' => 'content.topbar'],
            'sidebar' => ['title' => 'Admin navigation', 'source' => 'content.sidebar'],
            'workspace' => ['title' => 'Settings workspace', 'source' => 'content.workspace'],
            'drawer' => ['title' => 'Context drawer', 'source' => 'content.drawer'],
            'modal' => ['title' => 'Modal layer', 'source' => 'content.modal'],
            'notifications' => ['title' => 'Notifications', 'source' => 'content.notifications'],
        ];
        $page = new PageDescriptor(
            'admin.settings_driven_read_only_route',
            $routeKey,
            new LayoutBinding(
                LayoutBindingScope::AdminRoute,
                $routeUri,
                'admin.settings_driven_shell',
                [
                    'larena/admin:route',
                    'larena/layout:settings_driven_layout_plan',
                    $settingsOwner . ':read_model',
                ],
            ),
            $profile,
            [
                new SectionCall('topbar_navigation', 'topbar', ['component' => 'admin.shell.topbar']),
                new SectionCall('sidebar_navigation', 'sidebar', ['component' => 'admin.shell.sidebar']),
                new SectionCall('settings_content', 'workspace', [
                    'component' => 'admin.shell.workspace',
                    'settings_owner_package' => $settingsOwner,
                    'settings_status' => $settingsStatus,
                    'settings_field_count' => $fieldCount,
                    'settings_available' => $settingsAvailable,
                    'write_actions_allowed' => false,
                ]),
                new SectionCall('settings_table', 'workspace', [
                    'component' => 'sf-table',
                    'settings_owner_package' => $settingsOwner,
                    'read_only' => true,
                ]),
                new SectionCall('settings_diagnostics', 'workspace', [
                    'component' => 'admin.diagnostics.panel',
                    'settings_status' => $settingsStatus,
                    'database_write_allowed' => false,
                ]),
            ],
            'Package-Owned Admin Frontend Read-Only Route',
            $regionContent,
        );
        $pageJson = [
            'owner_package' => 'larena/layout',
            'route_key' => $routeKey,
            'title' => $page->title,
            'layout_key' => $layout->layoutKey,
            'region_content' => $page->regionContent,
            'sections' => array_map(static fn (SectionCall $section): array => [
                'section_key' => $section->sectionKey,
                'region_key' => $section->regionKey,
                'instance_id' => $section->instanceId,
                'params' => $section->params,
            ], $page->sections),
        ];
        $layoutSettings = [
            'owner_package' => 'larena/layout',
            'layout_key' => $layout->layoutKey,
            'page_wrap' => $layout->pageWrap,
            'content_wrap' => $layout->contentWrap,
            'service_areas' => $layout->serviceAreas,
            'breakpoint_placements' => $layout->breakpointPlacements,
        ];
        $renderBlueprint = [
            'owner_package' => 'larena/layout',
            'source' => 'page_json_plus_layout_settings',
            'route_key' => $routeKey,
            'title' => $page->title,
            'layout_key' => $layout->layoutKey,
            'page_wrap' => $layout->pageWrap,
            'content_wrap' => $layout->contentWrap,
            'service_areas' => $layout->serviceAreas,
            'breakpoint_placements' => $layout->breakpointPlacements,
            'region_content' => $page->regionContent,
        ];

        return new ResolvedLayoutPlan(
            $page,
            $layout,
            [
                'route:larena/admin',
                'layout:larena/layout',
                'page-json:admin.settings_driven_read_only_route',
                'layout-settings:admin.settings_driven_shell',
                'settings:' . $settingsOwner,
                'ui:larena/ui',
                'asset_activation:larena/core:core.assets',
            ],
            [
                'route' => 'package-owned-admin-frontend-read-only-route',
                'mode' => 'read-only',
                'layout_source' => 'settings_read_model',
                'settings_owner_package' => $settingsOwner,
                'settings_status' => $settingsStatus,
                'settings_field_count' => $fieldCount,
                'settings_available' => $settingsAvailable,
                'page_json_hash' => hash('sha256', json_encode($pageJson, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'layout_settings_hash' => hash('sha256', json_encode($layoutSettings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'write_actions_allowed' => false,
                'database_write_allowed' => false,
            ],
            false,
            $pageJson,
            $layoutSettings,
            $renderBlueprint,
        );
    }
}
