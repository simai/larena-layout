<?php

declare(strict_types=1);

namespace Larena\Layout\SiteTemplate;

use Larena\Setting\Contracts\SettingDefinition;
use Larena\Setting\Enums\SettingLevel;
use Larena\Setting\Runtime\SettingDefinitionRegistry;

/**
 * A site's template and what stands in its areas, as settings (sites plan, stage 6; the model's §2.2): they inherit from
 * the site down the page tree, a section or a page may set its own, a value may lock the levels below, and each
 * language may have its own. One key per area, so a section can change its footer and keep the header it inherits. They
 * go live as every setting does, draft first, without a page's «Опубликовать».
 *
 * An area's value is a content@1 list of blocks; Property checks it against Layout's block components. It is not a field
 * of the settings form: it has its own editor (hidden here). Registered only where Setting is installed.
 */
final class LayoutSettingDefinitions
{
    public const TEMPLATE = 'layout.template';

    public const AREA_PREFIX = 'layout.area.';

    public const OPTIONS = 'layout.site_templates';

    /** The right that changes a site's look: the same as the site's other settings. */
    public const WRITE = 'admin.site_settings.write';

    public static function area(string $area): string
    {
        return self::AREA_PREFIX.$area;
    }

    public static function register(SettingDefinitionRegistry $registry, SiteTemplateCatalog $templates, ?\Closure $word = null): void
    {
        $levels = [SettingLevel::Site->value, SettingLevel::Section->value, SettingLevel::Page->value];
        $registry->options(self::OPTIONS, static fn (string $locale): array => array_map(static fn (array $template): array => [
            'value' => $template['key'],
            'label' => $word !== null ? (string) $word($template['label'], $locale) : $template['label'],
        ], $templates->all()));
        $definitions = [new SettingDefinition(self::TEMPLATE, 'larena/layout', 'larena-layout::settings.template', 'layout', 'choice', 'site',
            SiteTemplateCatalog::DEFAULT, right: self::WRITE, order: 10, optionsSource: self::OPTIONS, levels: $levels, lockable: true)];
        foreach (SiteTemplateCatalog::AREAS as $position => $area) {
            $definitions[] = new SettingDefinition(self::area($area), 'larena/layout', 'larena-layout::settings.areas.'.$area, 'layout', 'content', 'site',
                null, right: self::WRITE, order: 20 + $position * 10, hidden: true, levels: $levels, lockable: true, localized: true);
        }
        $registry->register(...$definitions);
    }
}
