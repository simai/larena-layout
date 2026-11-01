<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

use stdClass;

/**
 * Draws SIMAI Framework structured content (a Composition Document with content.* nodes) to HTML on a server, as
 * the Framework's `SF.Composition.render` draws it in a browser: the same validation first, the same markup for
 * every built-in type, the same assets and diagnostics. The Framework's conformance kit
 * (tests/Fixtures/structured-content-conformance) is the contract: a page drawn here and a page drawn there are the
 * same page.
 *
 * Built-in registry only. A registered type (content.diagram, content.math) is drawn by a host: here it draws nothing
 * and reports renderer_unavailable at node:<id>, and its assets are still named. A ui-layout `layout.scope` is not
 * drawn here either (its routing is the Framework's), and reports the same.
 */
final class Renderer
{
    private const ALERT_ICONS = ['neutral' => 'info', 'info' => 'info', 'success' => 'check_circle', 'warning' => 'warning', 'error' => 'error'];

    private const GRID_COLUMNS = [
        1 => 'grid-col-1',
        2 => 'grid-col-1 md:grid-col-2',
        3 => 'grid-col-1 md:grid-col-2 lg:grid-col-3',
        4 => 'grid-col-1 md:grid-col-2 lg:grid-col-4',
    ];

    // The icon of a file's kind, by its media type: sf-download-file's own glyph when the type says nothing.
    private const FILE_ICONS = [
        ['~^application/pdf$~D', 'picture_as_pdf'],
        ['~^image/~', 'image'],
        ['~^video/~', 'movie'],
        ['~^audio/~', 'audio_file'],
        ['~^application/(?:zip|x-zip-compressed|x-7z-compressed|x-rar-compressed|vnd\.rar|x-tar|gzip|x-gzip)$~D', 'folder_zip'],
        ['~^(?:text/csv|application/vnd\.ms-excel|application/vnd\.openxmlformats-officedocument\.spreadsheetml\.[a-z.]+|application/vnd\.oasis\.opendocument\.spreadsheet)$~D', 'table_chart'],
        ['~^(?:application/vnd\.ms-powerpoint|application/vnd\.openxmlformats-officedocument\.presentationml\.[a-z.]+|application/vnd\.oasis\.opendocument\.presentation)$~D', 'slideshow'],
        ['~^(?:text/|application/msword$|application/vnd\.openxmlformats-officedocument\.wordprocessingml\.[a-z.]+$|application/vnd\.oasis\.opendocument\.text$|application/rtf$)~D', 'description'],
    ];

    private readonly TypeRegistry $registry;

    /** @var list<array{code: string, path: string, message: string}> */
    private array $diagnostics = [];

    private string $outlet = '';

    private string $locale = 'en';

    public function __construct(?TypeRegistry $registry = null)
    {
        $this->registry = $registry ?? TypeRegistry::builtIn();
    }

    /**
     * Draws a Document. `$content` is the page Document a layout's content.outlet draws in its place; leave it out
     * when there is none.
     */
    public function render(mixed $document, mixed $content = new NoContent()): RenderedContent
    {
        $validation = Validator::validate($document, $this->registry);
        if ($validation !== []) {
            return new RenderedContent('', [], $validation);
        }
        /** @var stdClass $document */
        $renderer = new self($this->registry);
        $renderer->locale = is_string(Js::get($document, 'locale')) ? Js::get($document, 'locale') : 'en';
        $outletAssets = [];
        if (! $content instanceof NoContent) {
            if (Js::get($content, 'profile') !== 'structured-content') {
                $renderer->diagnostics[] = ['code' => 'outlet_content_invalid', 'path' => '$.content', 'message' => 'The page content must be a structured-content Document'];
            } else {
                $page = (new self($this->registry))->render($content);
                $renderer->outlet = $page->html;
                $outletAssets = $page->assets;
                foreach ($page->diagnostics as $entry) {
                    $path = str_starts_with($entry['path'], '$') ? substr($entry['path'], 1) : ':'.$entry['path'];
                    $renderer->diagnostics[] = [...$entry, 'path' => '$.content'.$path];
                }
            }
        }
        $html = $renderer->node(Js::get($document, 'root'));
        $assets = array_values(array_unique([...$this->assets(Js::get($document, 'root')), ...$outletAssets]));
        sort($assets, SORT_STRING);

        return new RenderedContent($html, $assets, $renderer->diagnostics);
    }

    /** @return list<string> every asset the Document's types name */
    private function assets(mixed $node): array
    {
        $manifest = $this->registry->manifest(Js::get($node, 'type'));
        $assets = is_array($manifest['assets'] ?? null) ? $manifest['assets'] : [];
        foreach (Js::entries(Js::get($node, 'slots')) as $children) {
            foreach (is_array($children) ? $children : [] as $child) {
                array_push($assets, ...$this->assets($child));
            }
        }

        return $assets;
    }

    private function node(stdClass $node): string
    {
        $manifest = (array) $this->registry->manifest(Js::get($node, 'type'));
        $slots = [];
        $lists = [];
        foreach (Js::entries(Js::get($node, 'slots')) as $name => $children) {
            $rendered = [];
            foreach (is_array($children) ? $children : [] as $child) {
                $rendered[] = $this->node($child);
            }
            $slots[(string) $name] = implode('', $rendered);
            $lists[(string) $name] = $rendered;
        }
        $name = $manifest['renderer']['name'] ?? null;
        $html = is_string($name) ? $this->builtIn($name, $node, $slots, $lists) : null;
        if ($html === null) {
            $this->diagnostics[] = ['code' => 'renderer_unavailable', 'path' => 'node:'.Js::string(Js::get($node, 'id')), 'message' => 'Renderer '.Js::string($name).' is unavailable'];

            return '';
        }

        return $html;
    }

    /**
     * @param array<string, string> $slots
     * @param array<string, list<string>> $lists
     */
    private function builtIn(string $name, stdClass $node, array $slots, array $lists): ?string
    {
        $data = Js::get($node, 'data');
        $props = Js::get($node, 'props');
        $id = self::id($node);
        $default = $slots['default'] ?? '';

        return match ($name) {
            'layout.page' => '<main'.$id.'>'.$default.'</main>',
            'layout.section' => '<section'.$id.'>'.$default.'</section>',
            'layout.columns' => '<div class="sf-composition-columns"'.$id.(Js::isInteger(Js::get($props, 'columns')) ? ' data-composition-columns="'.Js::string(Js::get($props, 'columns')).'"' : '').'>'
                .implode('', array_map(static fn (string $child): string => '<div class="sf-composition-column">'.$child.'</div>', $lists['columns'] ?? [])).'</div>',
            'layout.regions' => '<div class="sf-composition-regions"'.$id.'><div class="sf-composition-regions-grid">'.($slots['regions'] ?? '').'</div></div>',
            'layout.region' => $this->region($node, $default),
            'content.heading' => $this->heading($node),
            'content.paragraph' => '<p'.$id.'>'.InlineContent::render(Js::get($data, 'content')).'</p>',
            'content.document' => '<article'.$id.'>'.(Validator::truthy(Js::get($data, 'title')) ? '<h1>'.Js::escapeHtml(Js::get($data, 'title')).'</h1>' : '').$default.'</article>',
            'content.list' => $this->list($node, $slots['items'] ?? ''),
            'content.list-item' => '<li'.$id.'>'.InlineContent::render(Js::get($data, 'content')).$default.'</li>',
            'content.quote' => '<blockquote'.$id.'>'.$default.'</blockquote>',
            'content.code' => $this->code($node),
            'content.divider' => '<hr class="sf-divider sf-divider-horizontal"'.$id.'>',
            'content.table' => $this->table($node),
            'content.figure' => $this->figure($node),
            'content.alert' => $this->alert($node, $default),
            'content.details' => $this->details($node, $default),
            'content.tabs' => $this->tabs($node, $lists['tabs'] ?? []),
            // A tab is drawn by its tabs: the panel is theirs, the content its own.
            'content.tab' => $default,
            'content.steps' => $this->steps($node, $lists['steps'] ?? []),
            // A step is drawn by its steps: the item is theirs, title and content its own.
            'content.step' => (is_array(Js::get($data, 'title')) ? '<div class="sf-procedure-title">'.InlineContent::render(Js::get($data, 'title')).'</div>' : '').'<div class="sf-procedure-content">'.$default.'</div>',
            'content.grid' => $this->grid($node, $lists['default'] ?? []),
            'content.card' => '<div class="sf-card'.(Js::get($props, 'variant') === 'plain' ? ' sf-card--plain' : '').'"'.$id.'>'.$default.'</div>',
            'content.media' => $this->media($node, $default),
            'content.file' => $this->file($node),
            // Cleaned at render by the html@1 subset: no script, no style, no attribute but a safe href survives.
            'content.html' => '<div'.$id.'>'.RichTextSubset::clean(Js::get($data, 'markup')).'</div>',
            'content.outlet' => '<div data-sf-outlet=""'.$id.'>'.$this->outlet.'</div>',
            default => null,
        };
    }

    private static function id(stdClass $node): string
    {
        return ' data-sf-composition-id="'.Js::escapeHtml(Js::get($node, 'id')).'"';
    }

    // Docara's ratio words and the figure's modifier: 16/9 is sf-figure--ratio-16x9.
    private static function ratioClass(mixed $ratio): string
    {
        return Validator::truthy($ratio) && $ratio !== 'auto' ? ' sf-figure--ratio-'.str_replace('/', 'x', Js::string($ratio)) : '';
    }

    private function region(stdClass $node, string $default): string
    {
        $props = Js::get($node, 'props');
        $element = RegionRules::ELEMENTS[Js::string(Js::get($props, 'landmark'))] ?? 'undefined';
        $sticky = Js::get($props, 'sticky') === true ? ' data-sf-region-sticky=""' : '';
        $label = Js::has($props, 'label') ? ' aria-label="'.Js::escapeHtml(Js::get($props, 'label')).'"' : '';
        $placement = Js::get($props, 'placement') ?? 'block';

        return '<'.$element.' class="sf-composition-region"'.self::id($node).' data-sf-region="'.Js::escapeHtml(Js::get($props, 'name')).'" data-sf-region-placement="'.Js::escapeHtml($placement).'"'.$sticky.$label.'>'.$default.'</'.$element.'>';
    }

    private function heading(stdClass $node): string
    {
        $data = Js::get($node, 'data');
        $level = Js::get($data, 'level');
        $level = Js::string(Validator::truthy($level) ? $level : 2);

        return '<h'.$level.self::id($node).'>'.InlineContent::render(Js::get($data, 'content')).'</h'.$level.'>';
    }

    private function list(stdClass $node, string $items): string
    {
        $props = Js::get($node, 'props');
        $ordered = Js::get($props, 'ordered') === true;
        $start = Js::get($props, 'start');
        $startAttribute = $ordered && Js::isInteger($start) && ! Js::same($start, 1) ? ' start="'.Js::string($start).'"' : '';
        $tag = $ordered ? 'ol' : 'ul';

        return '<'.$tag.$startAttribute.self::id($node).'>'.$items.'</'.$tag.'>';
    }

    private function code(stdClass $node): string
    {
        $props = Js::get($node, 'props');
        $lang = Js::get($props, 'lang');
        $language = Validator::truthy($lang) ? ' class="language-'.Js::escapeHtml($lang).'" data-lang="'.Js::escapeHtml($lang).'"' : '';
        $lines = Js::get($props, 'numbers') === true ? ' data-lines' : '';
        $title = Js::get($props, 'title');
        $code = '<div class="sf-highlight"'.(Validator::truthy($title) ? '' : self::id($node)).'><pre><code'.$language.$lines.'>'.Js::escapeHtml(Js::get(Js::get($node, 'data'), 'code')).'</code></pre></div>';
        if (! Validator::truthy($title)) {
            return $code;
        }

        return '<figure class="flex flex-col gap-1/2 m-0"'.self::id($node).'><figcaption class="text-1/2 weight-5 color-on-surface-variant">'.Js::escapeHtml($title).'</figcaption>'.$code.'</figure>';
    }

    private function table(stdClass $node): string
    {
        $data = Js::get($node, 'data');
        $props = Js::get($node, 'props');
        $rows = Js::get($data, 'rows');
        $rows = is_array($rows) ? $rows : [];
        $header = Js::get($props, 'header') ?? 'row';
        $headRow = $header === 'row' || $header === 'both';
        $headColumn = $header === 'column' || $header === 'both';
        $align = Js::get($props, 'align');
        $align = is_array($align) ? $align : [];
        // The frame sets every cell to the start; a column set otherwise says so on its cells.
        $cellStyle = static fn (int $index): string => ($align[$index] ?? null) === 'center' || ($align[$index] ?? null) === 'end' ? ' style="text-align: '.$align[$index].'"' : '';
        $cell = static fn (mixed $content, int $index, string $tag, ?string $scope): string => '<'.$tag.($scope !== null ? ' scope="'.$scope.'"' : '').$cellStyle($index).'>'.InlineContent::render($content).'</'.$tag.'>';
        $row = static function (mixed $cells, bool $inHead) use ($cell, $headColumn): string {
            $out = '';
            foreach (is_array($cells) ? $cells : [] as $index => $content) {
                $out .= $inHead
                    ? $cell($content, $index, 'th', 'col')
                    : ($headColumn && $index === 0 ? $cell($content, $index, 'th', 'row') : $cell($content, $index, 'td', null));
            }

            return '<tr>'.$out.'</tr>';
        };
        $caption = is_array(Js::get($data, 'caption')) ? '<caption>'.InlineContent::render(Js::get($data, 'caption')).'</caption>' : '';
        $head = $headRow ? '<thead>'.$row($rows[0] ?? [], true).'</thead>' : '';
        $body = '';
        foreach ($headRow ? array_slice($rows, 1) : $rows as $cells) {
            $body .= $row($cells, false);
        }

        return '<div class="table-frame"'.self::id($node).'><table class="table">'.$caption.$head.($body !== '' ? '<tbody>'.$body.'</tbody>' : '').'</table></div>';
    }

    private static function image(mixed $data): string
    {
        $size = '';
        foreach (['width', 'height'] as $key) {
            if (Js::isInteger(Js::get($data, $key))) {
                $size .= ' '.$key.'="'.Js::string(Js::get($data, $key)).'"';
            }
        }

        return '<img src="'.Js::escapeHtml(self::defined($data, 'src')).'" alt="'.Js::escapeHtml(self::defined($data, 'alt')).'"'.$size.' loading="lazy" decoding="async">';
    }

    /** A value as JavaScript's template literal writes it: a missing key is "undefined". */
    private static function defined(mixed $object, string $key): mixed
    {
        return Js::has($object, $key) ? Js::get($object, $key) : 'undefined';
    }

    private function figure(stdClass $node): string
    {
        $data = Js::get($node, 'data');
        $props = Js::get($node, 'props');
        $fit = Js::get($props, 'fit') === 'contain' ? ' sf-figure--contain' : '';
        $caption = is_array(Js::get($data, 'caption')) ? '<figcaption class="sf-figure-caption">'.InlineContent::render(Js::get($data, 'caption')).'</figcaption>' : '';

        return '<figure class="sf-figure'.self::ratioClass(Js::get($props, 'ratio')).$fit.'"'.self::id($node).'><div class="sf-figure-media">'.self::image($data).'</div>'.$caption.'</figure>';
    }

    private function alert(stdClass $node, string $default): string
    {
        $props = Js::get($node, 'props');
        $status = Js::string(Js::get($props, 'status') ?? 'neutral');
        $appearance = Js::string(Js::get($props, 'appearance') ?? 'default');
        $title = Js::get(Js::get($node, 'data'), 'title');
        $titleHtml = is_array($title) ? '<div class="sf-alert-text">'.InlineContent::render($title).'</div>' : '';
        $body = $default !== '' ? '<div class="sf-alert-supporting-text">'.$default.'</div>' : '';

        return '<div class="sf-alert sf-alert--'.$status.' sf-alert--'.$appearance.' flex items-start"'.self::id($node).'><i class="sf-icon" aria-hidden="true">'.(self::ALERT_ICONS[$status] ?? 'undefined').'</i><div class="sf-alert-wrap flex flex-col flex-1"><div class="sf-alert-content flex flex-col flex-1">'.$titleHtml.$body.'</div></div></div>';
    }

    // Native details: it opens and closes with no script.
    private function details(stdClass $node, string $default): string
    {
        $props = Js::get($node, 'props');
        $frame = Js::get($props, 'layout') === 'lines'
            ? 'border-bottom-1 border-bottom-solid border-outline-variant p-y-1'
            : 'bg-surface-0 border-1 border-solid border-outline-variant radius-2 p-2';

        return '<details class="'.$frame.'"'.self::id($node).(Js::get($props, 'open') === true ? ' open' : '').'><summary class="weight-5 cursor-pointer">'.InlineContent::render(Js::get(Js::get($node, 'data'), 'summary')).'</summary><div class="p-top-1">'.$default.'</div></details>';
    }

    /** @param list<string> $panels */
    private function tabs(stdClass $node, array $panels): string
    {
        $tabs = Js::get(Js::get($node, 'slots'), 'tabs');
        $tabs = is_array($tabs) ? $tabs : [];
        $label = Js::get(Js::get($node, 'data'), 'label');
        $labelAttribute = Validator::truthy($label) ? ' aria-label="'.Js::escapeHtml($label).'"' : '';
        // The first tab is selected; the tabs runtime adds roles, ids and keys.
        $buttons = '';
        $bodies = '';
        foreach ($tabs as $index => $tab) {
            $buttons .= '<button type="button" class="sf-button sf-button--default sf-button--primary sf-button--size-1'.($index === 0 ? ' selected' : '').'"><span class="sf-button-text-container">'.Js::escapeHtml(Js::get(Js::get($tab, 'data'), 'label')).'</span></button>';
            $bodies .= '<div class="sf-tabs-main-tab'.($index === 0 ? ' selected' : '').'"'.self::id($tab).($index === 0 ? '' : ' hidden').'>'.($panels[$index] ?? '').'</div>';
        }

        return '<div class="sf-tabs sf-tabs--underline flex flex-col"'.self::id($node).'><div class="sf-tabs-top-container"><div class="sf-tabs-top flex"'.$labelAttribute.'>'.$buttons.'</div></div><div class="sf-tabs-main-container">'.$bodies.'</div></div>';
    }

    /** @param list<string> $items */
    private function steps(stdClass $node, array $items): string
    {
        $steps = Js::get(Js::get($node, 'slots'), 'steps');
        $steps = is_array($steps) ? $steps : [];
        $props = Js::get($node, 'props');
        $current = Js::isInteger(Js::get($props, 'current')) ? (int) Js::get($props, 'current') : null;
        $layout = Js::get($props, 'layout') === 'list' ? ' sf-procedure--list' : '';
        $out = '';
        foreach ($steps as $index => $step) {
            $number = $index + 1;
            $state = $current === null ? '' : ($number < $current ? ' sf-procedure-step--complete' : ($number === $current ? ' sf-procedure-step--current' : ''));
            $out .= '<li class="sf-procedure-step'.$state.'"'.self::id($step).($current === $number ? ' aria-current="step"' : '').'>'.($items[$index] ?? '').'</li>';
        }

        return '<ol class="sf-procedure'.$layout.'"'.self::id($node).'>'.$out.'</ol>';
    }

    /** @param list<string> $children */
    private function grid(stdClass $node, array $children): string
    {
        $props = Js::get($node, 'props');
        $columns = Js::get($props, 'columns') ?? 3;
        $columns = Js::isInteger($columns) ? (self::GRID_COLUMNS[(int) $columns] ?? 'undefined') : 'undefined';
        $gap = Js::get($props, 'gap');
        $gap = Js::isInteger($gap) ? Js::string($gap) : '2';

        return '<div class="grid '.$columns.' gap-'.$gap.'"'.self::id($node).'>'.implode('', array_map(static fn (string $child): string => '<div class="min-w-0">'.$child.'</div>', $children)).'</div>';
    }

    private function media(stdClass $node, string $default): string
    {
        $props = Js::get($node, 'props');
        $figure = '<figure class="sf-figure'.self::ratioClass(Js::get($props, 'ratio') ?? '16/9').'"><div class="sf-figure-media">'.self::image(Js::get($node, 'data')).'</div></figure>';
        $text = '<div class="min-w-0">'.$default.'</div>';
        $parts = Js::get($props, 'side') === 'left' ? $figure.$text : $text.$figure;

        return '<div class="grid grid-col-1 md:grid-col-2 gap-3 items-cross-center"'.self::id($node).'>'.$parts.'</div>';
    }

    private static function fileIcon(mixed $mime): string
    {
        if (! is_string($mime) || $mime === '') {
            return 'download';
        }
        foreach (self::FILE_ICONS as [$pattern, $icon]) {
            if (preg_match($pattern, $mime) === 1) {
                return $icon;
            }
        }

        return 'download';
    }

    // sf-download-file's row, drawn as a plain link: it downloads with no script, the name falls back to the
    // address's last part, and the size reads in the Document's language.
    private function file(stdClass $node): string
    {
        $data = Js::get($node, 'data');
        $href = Js::get($data, 'href');
        $label = Js::get($data, 'label');
        $mime = Js::get($data, 'mime');
        $size = Js::get($data, 'size');
        $fromAddress = FileSize::nameFromAddress(Js::string($href));
        $name = Validator::truthy($label) ? $label : ($fromAddress !== '' ? $fromAddress : $href);
        $type = Validator::truthy($mime) ? ' type="'.Js::escapeHtml($mime).'"' : '';
        $sizeText = Js::isInteger($size) ? FileSize::format((int) $size, $this->locale) : '';
        $sizePart = $sizeText !== '' ? '<span class="sf-download-file__size sf-download-file-file-size">'.Js::escapeHtml($sizeText).'</span>' : '';

        return '<a class="sf-download-file" href="'.Js::escapeHtml($href).'" download'.$type.self::id($node).'><span class="sf-download-file__body sf-download-file-wrap"><span class="sf-download-file__main sf-download-file-container"><i class="sf-download-file__icon sf-icon" aria-hidden="true">'.self::fileIcon($mime).'</i><span class="sf-download-file__name sf-download-file-file-name">'.Js::escapeHtml($name).'</span></span>'.$sizePart.'</span></a>';
    }
}
