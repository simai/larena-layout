<?php

declare(strict_types=1);

namespace Larena\Layout\Enums;

enum LayoutBindingScope: string
{
    case Site = 'site';
    case Section = 'section';
    case Page = 'page';
    case ContentType = 'content_type';
    case AdminRoute = 'admin_route';

    public function precedence(): int
    {
        return match ($this) {
            self::Site => 10,
            self::ContentType => 20,
            self::Section => 30,
            self::Page => 40,
            self::AdminRoute => 50,
        };
    }
}
