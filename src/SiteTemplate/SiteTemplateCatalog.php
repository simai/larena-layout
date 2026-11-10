<?php

declare(strict_types=1);

namespace Larena\Layout\SiteTemplate;

use InvalidArgumentException;

/**
 * The templates a site page may stand in (sites plan, stage 6, decision by recommendation: Blade templates a package
 * declares): each a view with the areas it shows. Layout declares two; another package adds its own with register().
 * A template receives `areas` (an HtmlString by area, empty ones left out) and `main`, the page's own part; it stands them
 * in the SIMAI Framework's declared regions (layout.region with its landmark: banner, main, complementary, contentinfo),
 * marked up as the structured-content Renderer draws them, the page's own part in the one `main` landmark.
 *
 * The chosen template is the setting `layout.template`; what stands in an area is its setting `layout.area.<area>`.
 */
final class SiteTemplateCatalog
{
    public const DEFAULT = 'larena-default';

    public const WITH_SIDEBAR = 'larena-sidebar';

    /** Every area a template may have: each is one setting key, so each inherits on its own. */
    public const AREAS = ['header', 'footer', 'sidebar'];

    /** @var array<string, array{key: string, label: string, view: string, areas: list<string>}> */
    private array $templates = [];

    public function __construct()
    {
        $this->register(self::DEFAULT, 'larena-layout::settings.templates.default', 'larena-layout::site.default', ['header', 'footer']);
        $this->register(self::WITH_SIDEBAR, 'larena-layout::settings.templates.sidebar', 'larena-layout::site.sidebar', ['header', 'sidebar', 'footer']);
    }

    /** @param list<string> $areas */
    public function register(string $key, string $label, string $view, array $areas): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,80}$/', $key) !== 1 || array_diff($areas, self::AREAS) !== []) {
            throw new InvalidArgumentException('layout_site_template_invalid');
        }
        $this->templates[$key] = ['key' => $key, 'label' => $label, 'view' => $view, 'areas' => $areas];
    }

    /** @return list<array{key: string, label: string, view: string, areas: list<string>}> */
    public function all(): array
    {
        return array_values($this->templates);
    }

    /**
     * The template of this key; the default for one no package declares any more, so a page never fails on it.
     *
     * @return array{key: string, label: string, view: string, areas: list<string>}
     */
    public function find(mixed $key): array
    {
        return is_string($key) && isset($this->templates[$key]) ? $this->templates[$key] : $this->templates[self::DEFAULT];
    }
}
