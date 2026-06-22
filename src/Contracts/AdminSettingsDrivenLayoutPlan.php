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
        );

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
        );

        return new ResolvedLayoutPlan(
            $page,
            $layout,
            [
                'route:larena/admin',
                'layout:larena/layout',
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
                'write_actions_allowed' => false,
                'database_write_allowed' => false,
            ],
        );
    }
}
