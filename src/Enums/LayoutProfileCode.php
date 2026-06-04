<?php

declare(strict_types=1);

namespace Larena\Layout\Enums;

enum LayoutProfileCode: string
{
    case PublicPage = 'public_page';
    case AdminPage = 'admin_page';
    case Dashboard = 'dashboard';
    case FormPage = 'form_page';
    case ListPage = 'list_page';
    case DetailPage = 'detail_page';
    case DocumentationPage = 'documentation_page';
    case WidgetPage = 'widget_page';

    public function requiresAccessPolicy(): bool
    {
        return in_array($this, [self::AdminPage, self::Dashboard, self::FormPage, self::ListPage, self::DetailPage], true);
    }
}
