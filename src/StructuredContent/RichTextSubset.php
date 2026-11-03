<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/**
 * The html@1 subset of sf-input-rich, the rule content.html is cleaned by at render: the SIMAI Framework's
 * html-subset.mjs (its string reader `parseHtml`, the cleaner and the writer) read the same way on a server.
 *
 *   p  h2  h3  h4  strong  em  a[href]  ul  ol  li  blockquote  br
 *
 * No attribute is kept but a safe href; script, style and the other containers whose content is not text go with
 * their content; any other element is unwrapped and its text stays. What comes out is written from the cleaned model,
 * every text escaped, so it holds nothing the subset does not allow whatever the reader understood.
 */
final class RichTextSubset
{
    public const ELEMENTS = ['p', 'h2', 'h3', 'h4', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'blockquote', 'br'];

    private const BLOCKS = ['p', 'h2', 'h3', 'h4'];

    private const SCHEMES = ['https:', 'mailto:', 'tel:'];

    private const DROPPED = [
        'script', 'style', 'template', 'head', 'title', 'meta', 'link', 'base', 'noscript',
        'iframe', 'frame', 'frameset', 'noembed', 'noframes', 'object', 'embed', 'applet',
        'svg', 'math', 'canvas', 'video', 'audio', 'img', 'picture', 'source', 'track',
        'input', 'select', 'textarea', 'xmp', 'plaintext',
    ];

    private const BREAKING = [
        'address', 'article', 'aside', 'body', 'caption', 'center', 'dd', 'details', 'dialog', 'dir', 'div',
        'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h5', 'h6', 'header',
        'hgroup', 'hr', 'html', 'legend', 'listing', 'main', 'menu', 'nav', 'optgroup', 'option', 'pre',
        'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr',
    ];

    private const LISTS = ['ul', 'ol'];

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const RAW = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'];

    // Where an implied </p> stops looking for the paragraph (the parser's button scope).
    private const P_SCOPE = ['td', 'th', 'table', 'caption', 'button'];

    private const CONTROLS = '\x{0000}-\x{001F}\x{007F}-\x{009F}';

    private const CONTROLS_AND_SPACE = '\x{0000}-\x{0020}\x{007F}-\x{009F}\x{00A0}\x{1680}\x{2000}-\x{200B}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const HREF_MAX = 2048;

    private const NAMED = [
        'amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{00A0}", 'shy' => "\u{00AD}",
        'hellip' => "\u{2026}", 'mdash' => "\u{2014}", 'ndash' => "\u{2013}", 'minus' => "\u{2212}", 'laquo' => "\u{00AB}", 'raquo' => "\u{00BB}",
        'lsquo' => "\u{2018}", 'rsquo' => "\u{2019}", 'sbquo' => "\u{201A}", 'ldquo' => "\u{201C}", 'rdquo' => "\u{201D}", 'bdquo' => "\u{201E}",
        'bull' => "\u{2022}", 'middot' => "\u{00B7}", 'copy' => "\u{00A9}", 'reg' => "\u{00AE}", 'trade' => "\u{2122}", 'deg' => "\u{00B0}",
        'times' => "\u{00D7}", 'divide' => "\u{00F7}", 'plusmn' => "\u{00B1}", 'euro' => "\u{20AC}", 'pound' => "\u{00A3}", 'yen' => "\u{00A5}",
        'cent' => "\u{00A2}", 'sect' => "\u{00A7}", 'para' => "\u{00B6}", 'thinsp' => "\u{2009}", 'ensp' => "\u{2002}", 'emsp' => "\u{2003}",
        'zwj' => "\u{200D}", 'zwnj' => "\u{200C}", 'larr' => "\u{2190}", 'rarr' => "\u{2192}", 'uarr' => "\u{2191}", 'darr' => "\u{2193}",
        'numero' => "\u{2116}", 'prime' => "\u{2032}", 'Prime' => "\u{2033}",
    ];

    /** Untrusted HTML into the subset, in its stored form. */
    public static function clean(mixed $input): string
    {
        return self::serialize(self::cleanTree(self::parse(is_string($input) ? $input : '')));
    }

    /** The href the subset keeps, or null: https:, mailto: and tel: URLs and a path on the site. */
    public static function href(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = Js::trim($value);
        if ($trimmed === '' || Js::length($trimmed) > self::HREF_MAX) {
            return null;
        }
        $compact = (string) preg_replace('/['.self::CONTROLS_AND_SPACE.']/u', '', $trimmed);
        if ($compact === '') {
            return null;
        }
        if (str_starts_with($compact, '/')) {
            // A site path, but not "//host" or "/\host", which leave the site.
            if (preg_match('#^/[/\\\\]#', $compact) === 1) {
                return null;
            }
            $path = (string) preg_replace('/['.self::CONTROLS.']/u', '', $trimmed);

            return preg_match('#^/[/\\\\]#', $path) === 1 ? null : $path;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $compact, $match) !== 1) {
            return null;
        }
        $scheme = strtolower($match[1]);
        if (! in_array($scheme.':', self::SCHEMES, true)) {
            return null;
        }
        $candidate = (string) preg_replace('/['.self::CONTROLS.']/u', '', $trimmed);
        $protocol = Url::protocol($candidate);
        if ($protocol !== $scheme.':') {
            return null;
        }
        $href = (string) Url::href($candidate);
        if ($protocol === 'https:' && (string) Url::hostname($candidate) === '') {
            return null;
        }
        if ($protocol !== 'https:' && Js::length($href) <= Js::length($protocol)) {
            return null;
        }

        return $href;
    }

    public static function decodeEntities(string $text): string
    {
        return (string) preg_replace_callback('/&(#x[0-9a-f]+|#[0-9]+|[a-z][a-z0-9]*);?/iu', static function (array $match): string {
            $whole = $match[0];
            $body = $match[1];
            if ($body[0] === '#') {
                $hex = $body[1] === 'x' || $body[1] === 'X';
                $digits = ltrim(substr($body, $hex ? 2 : 1), '0');
                if ($digits === '' || strlen($digits) > 8) {
                    return "\u{FFFD}";
                }
                $code = $hex ? (int) hexdec($digits) : (int) $digits;
                if ($code <= 0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                    return "\u{FFFD}";
                }

                return (string) mb_chr($code, 'UTF-8');
            }

            return array_key_exists($body, self::NAMED) && str_ends_with($whole, ';') ? self::NAMED[$body] : $whole;
        }, $text);
    }

    // ---------------------------------------------------------------- the reader

    /** @return array{type: 'element', name: string, attrs: array<string, string>, children: list<array<string, mixed>>} */
    public static function parse(string $source): array
    {
        $root = ['type' => 'element', 'name' => '#root', 'attrs' => [], 'children' => []];
        /** @var list<array<string, mixed>> $stack every open element by reference, the root first */
        $stack = [&$root];
        $ws = Js::WHITE_SPACE;
        $append = static function (array $node) use (&$stack): void {
            $top = &$stack[count($stack) - 1];
            $top['children'][] = $node;
        };
        $appendText = static function (string $value) use (&$stack): void {
            if ($value === '') {
                return;
            }
            $top = &$stack[count($stack) - 1];
            $last = count($top['children']) - 1;
            if ($last >= 0 && $top['children'][$last]['type'] === 'text') {
                $top['children'][$last]['value'] .= $value;
            } else {
                $top['children'][] = ['type' => 'text', 'value' => $value];
            }
        };
        $closeTo = static function (string $name, ?array $stopAt = null) use (&$stack): bool {
            for ($index = count($stack) - 1; $index > 0; $index--) {
                $open = $stack[$index]['name'];
                if ($stopAt !== null && in_array($open, $stopAt, true)) {
                    return false;
                }
                if ($open === $name) {
                    array_splice($stack, $index);

                    return true;
                }
            }

            return false;
        };
        $at = 0;
        $length = strlen($source);
        while ($at < $length) {
            $lt = strpos($source, '<', $at);
            if ($lt === false) {
                $appendText(self::decodeEntities(substr($source, $at)));
                break;
            }
            if ($lt > $at) {
                $appendText(self::decodeEntities(substr($source, $at, $lt - $at)));
            }
            $at = $lt;
            if (substr_compare($source, '<!--', $at, 4) === 0) {
                $end = strpos($source, '-->', $at + 4);
                $at = $end === false ? $length : $end + 3;
                continue;
            }
            $next = $source[$at + 1] ?? '';
            if ($next === '!' || $next === '?') {
                $end = strpos($source, '>', $at + 2);
                $at = $end === false ? $length : $end + 1;
                continue;
            }
            $rest = substr($source, $at);
            if ($next === '/') {
                if (preg_match('/^<\/([a-z][a-z0-9-]*)[^>]*>?/iu', $rest, $match) !== 1) {
                    // "</" not followed by a name is a bogus comment.
                    $end = strpos($source, '>', $at + 2);
                    $at = $end === false ? $length : $end + 1;
                    continue;
                }
                $at += strlen($match[0]);
                $name = strtolower($match[1]);
                if ($name === 'br') {
                    $append(['type' => 'element', 'name' => 'br', 'attrs' => [], 'children' => []]);
                } elseif ($name === 'p' && ! $closeTo('p', self::P_SCOPE)) {
                    // A </p> without a <p> is an empty paragraph, as a browser reads it.
                    $append(['type' => 'element', 'name' => 'p', 'attrs' => [], 'children' => []]);
                } else {
                    // As the reference reads it: a </p> that closed its paragraph also closes the next one out.
                    $closeTo($name);
                }
                continue;
            }
            if (preg_match('/^<([a-z][a-z0-9-]*)/i', $rest, $tag) !== 1) {
                $appendText('<');
                $at++;
                continue;
            }
            $at += strlen($tag[0]);
            $name = strtolower($tag[1]);
            $attrs = [];
            $selfClosing = false;
            // Attributes, up to the closing ">".
            while ($at < $length) {
                $rest = substr($source, $at);
                if (preg_match('/^['.$ws.'\/]+/u', $rest, $space) === 1) {
                    if (str_contains($space[0], '/') && ($source[$at + strlen($space[0])] ?? '') === '>') {
                        $selfClosing = true;
                    }
                    $at += strlen($space[0]);
                    continue;
                }
                if ($source[$at] === '>') {
                    $at++;
                    break;
                }
                $pattern = '/^([^'.$ws.'\/>"\'=][^'.$ws.'\/>"\'=]*)(?:['.$ws.']*=['.$ws.']*(?:"([^"]*)"?|\'([^\']*)\'?|([^'.$ws.'>]*)))?/u';
                if (preg_match($pattern, $rest, $attr, PREG_UNMATCHED_AS_NULL) !== 1) {
                    $at++;
                    continue;
                }
                $at += strlen((string) $attr[0]);
                $key = mb_strtolower((string) $attr[1], 'UTF-8');
                if (! array_key_exists($key, $attrs)) {
                    $attrs[$key] = self::decodeEntities($attr[2] ?? $attr[3] ?? $attr[4] ?? '');
                }
            }
            if ($name === 'li') {
                $closeTo('li', self::LISTS);
            }
            // A block start ends an open paragraph (the browser's implied </p>).
            if (in_array($name, self::BLOCKS, true) || in_array($name, self::LISTS, true) || $name === 'blockquote' || in_array($name, self::BREAKING, true)) {
                $closeTo('p', self::P_SCOPE);
            }
            $top = &$stack[count($stack) - 1];
            $top['children'][] = ['type' => 'element', 'name' => $name, 'attrs' => $attrs, 'children' => []];
            $element = &$top['children'][count($top['children']) - 1];
            unset($top);
            if ($name === 'plaintext') {
                unset($element);
                break;
            }
            if (in_array($name, self::RAW, true)) {
                $after = substr($source, $at);
                $end = preg_match('/<\/'.preg_quote($name, '/').'['.$ws.'\/>]/iu', $after, $close, PREG_OFFSET_CAPTURE) === 1 ? $at + $close[0][1] : null;
                $body = substr($source, $at, ($end ?? $length) - $at);
                $element['children'][] = ['type' => 'text', 'value' => $name === 'textarea' || $name === 'title' ? self::decodeEntities($body) : $body];
                $gt = $end !== null ? strpos($source, '>', $end) : false;
                $at = $gt === false ? $length : $gt + 1;
                unset($element);
                continue;
            }
            if (! in_array($name, self::VOID, true) && ! $selfClosing) {
                $stack[] = &$element;
            }
            unset($element);
        }

        return $root;
    }

    // ---------------------------------------------------------------- the cleaner

    /** @return array{strong?: bool, em?: bool} */
    private static function styleMarks(?string $style): array
    {
        $out = [];
        if ($style === null || $style === '') {
            return $out;
        }
        $ws = Js::WHITE_SPACE;
        if (preg_match('/(?:^|;)['.$ws.']*font-weight['.$ws.']*:['.$ws.']*([^;!]+)/iu', $style, $match) === 1) {
            $weight = mb_strtolower(Js::trim($match[1]), 'UTF-8');
            if ($weight !== '') {
                $number = self::number($weight);
                if ($weight === 'bold' || $weight === 'bolder' || $number >= 600) {
                    $out['strong'] = true;
                } elseif ($weight === 'normal' || $weight === 'lighter' || $number < 600) {
                    $out['strong'] = false;
                }
            }
        }
        if (preg_match('/(?:^|;)['.$ws.']*font-style['.$ws.']*:['.$ws.']*([^;!]+)/iu', $style, $match) === 1) {
            $fontStyle = mb_strtolower(Js::trim($match[1]), 'UTF-8');
            if ($fontStyle === 'italic' || str_starts_with($fontStyle, 'oblique')) {
                $out['em'] = true;
            } elseif ($fontStyle === 'normal') {
                $out['em'] = false;
            }
        }

        return $out;
    }

    /** Number(text): NaN (which compares false both ways) unless the whole text is a number. */
    private static function number(string $text): float
    {
        $trimmed = Js::trim($text);
        if ($trimmed === '') {
            return 0.0;
        }

        return is_numeric($trimmed) && preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/iD', $trimmed) === 1 ? (float) $trimmed : NAN;
    }

    /**
     * @param array<string, mixed> $element
     * @param array{strong?: bool, em?: bool} $marks
     * @return array{strong?: bool, em?: bool}
     */
    private static function marksOf(array $element, array $marks): array
    {
        $next = $marks;
        if ($element['name'] === 'strong' || $element['name'] === 'b') {
            $next['strong'] = true;
        }
        if ($element['name'] === 'em' || $element['name'] === 'i') {
            $next['em'] = true;
        }
        $style = $element['attrs']['style'] ?? null;
        $styled = self::styleMarks(is_string($style) ? $style : null);
        if (array_key_exists('strong', $styled)) {
            $next['strong'] = $styled['strong'];
        }
        if (array_key_exists('em', $styled)) {
            $next['em'] = $styled['em'];
        }

        return $next;
    }

    /** @param array{marks: array{strong?: bool, em?: bool}, href: ?string, pre: bool} $context */
    private static function leaf(string $kind, string $value, array $context): RichTextLeaf
    {
        return new RichTextLeaf($kind, $value, (bool) ($context['marks']['strong'] ?? false), (bool) ($context['marks']['em'] ?? false), $context['href']);
    }

    /** @param array{marks: array{strong?: bool, em?: bool}, href: ?string, pre: bool} $context */
    private static function walkText(string $value, RichTextFlow $flow, array $context): void
    {
        if ($context['pre']) {
            $lines = explode("\n", (string) preg_replace('/\r\n?/', "\n", $value));
            foreach ($lines as $index => $line) {
                if ($index > 0) {
                    $flow->inline(self::leaf('br', '', $context));
                }
                if ($line !== '') {
                    $flow->inline(self::leaf('text', (string) preg_replace('/[\t\f]/', ' ', $line), $context));
                }
            }

            return;
        }
        $text = (string) preg_replace('/[\t\n\r\f ]+/', ' ', $value);
        if ($text === '') {
            return;
        }
        // White space between blocks is formatting of the source, not text.
        if ($text === ' ' && $flow->run === []) {
            return;
        }
        $flow->inline(self::leaf('text', $text, $context));
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param array{marks: array{strong?: bool, em?: bool}, href: ?string, pre: bool} $context
     */
    private static function walkChildren(array $children, RichTextFlow $flow, array $context): void
    {
        foreach ($children as $child) {
            self::walk($child, $flow, $context);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array{marks: array{strong?: bool, em?: bool}, href: ?string, pre: bool} $context
     */
    private static function walk(array $node, RichTextFlow $flow, array $context): void
    {
        if ($node['type'] === 'text') {
            self::walkText($node['value'], $flow, $context);

            return;
        }
        $name = $node['name'];
        if (in_array($name, self::DROPPED, true)) {
            return;
        }
        if ($name === 'br') {
            $flow->inline(self::leaf('br', '', $context));

            return;
        }
        $inner = [...$context, 'marks' => self::marksOf($node, $context['marks'])];
        if ($name === 'a') {
            $href = self::href($node['attrs']['href'] ?? null);
            self::walkChildren($node['children'], $flow, $href !== null && $href !== '' && ($context['href'] === null || $context['href'] === '') ? [...$inner, 'href' => $href] : $inner);

            return;
        }
        if (in_array($name, self::BLOCKS, true)) {
            $flow->open($name);
            self::walkChildren($node['children'], $flow, $inner);
            $flow->close(true);

            return;
        }
        if (in_array($name, self::LISTS, true)) {
            $list = self::readList($node, $inner, $name);
            if ($list['items'] !== []) {
                $flow->block($list);
            }

            return;
        }
        if ($name === 'blockquote') {
            if ($flow->kind === 'item') {
                $flow->open('p');
                self::walkChildren($node['children'], $flow, $inner);
                $flow->close();

                return;
            }
            $quote = new RichTextFlow('flow');
            self::walkChildren($node['children'], $quote, $inner);
            $quote->flush();
            $flow->block(['type' => 'blockquote', 'blocks' => $quote->blocks]);

            return;
        }
        if ($name === 'li') {
            // An item outside a list is a paragraph of its own.
            $flow->open('p');
            self::walkChildren($node['children'], $flow, $inner);
            $flow->close();

            return;
        }
        if (in_array($name, self::BREAKING, true)) {
            $flow->open('p');
            self::walkChildren($node['children'], $flow, $name === 'pre' || $name === 'listing' ? [...$inner, 'pre' => true] : $inner);
            $flow->close();

            return;
        }
        // Any other element: its text, in place.
        self::walkChildren($node['children'], $flow, $inner);
    }

    /**
     * @param array<string, mixed> $node
     * @param array{marks: array{strong?: bool, em?: bool}, href: ?string, pre: bool} $context
     * @return array{children: list<array<string, mixed>>}
     */
    private static function readItem(array $node, array $context): array
    {
        $flow = new RichTextFlow('item');
        self::walkChildren($node['children'], $flow, $context);
        $flow->flush();

        return ['children' => $flow->blocks];
    }

    /**
     * @param array<string, mixed> $node
     * @param array{marks: array{strong?: bool, em?: bool}, href: ?string, pre: bool} $context
     * @return array{type: string, items: list<array{children: list<array<string, mixed>>}>}
     */
    private static function readList(array $node, array $context, string $type): array
    {
        $list = ['type' => $type, 'items' => []];
        /** @var RichTextFlow|null $loose */
        $loose = null;
        $endLoose = static function () use (&$loose, &$list): void {
            if ($loose !== null) {
                $loose->flush();
                $list['items'][] = ['children' => $loose->blocks];
                $loose = null;
            }
        };
        foreach ($node['children'] as $child) {
            if ($child['type'] === 'element' && $child['name'] === 'li') {
                $endLoose();
                $list['items'][] = self::readItem($child, $context);
                continue;
            }
            if ($child['type'] === 'element' && in_array($child['name'], self::LISTS, true)) {
                $endLoose();
                // A list right inside a list (what an indent command leaves) belongs to the item before it.
                $nested = self::readList($child, [...$context, 'marks' => self::marksOf($child, $context['marks'])], $child['name']);
                if ($nested['items'] === []) {
                    continue;
                }
                $last = count($list['items']) - 1;
                if ($last >= 0) {
                    $list['items'][$last]['children'][] = $nested;
                } else {
                    $list['items'][] = ['children' => [$nested]];
                }
                continue;
            }
            if ($child['type'] === 'text' && Js::trim($child['value']) === '') {
                continue;
            }
            if ($child['type'] === 'element' && in_array($child['name'], self::DROPPED, true)) {
                continue;
            }
            // Loose content in a list is an item.
            $loose ??= new RichTextFlow('item');
            self::walk($child, $loose, $context);
        }
        $endLoose();

        return $list;
    }

    // ---------------------------------------------------------------- tidying

    private static function sameFormat(RichTextLeaf $a, RichTextLeaf $b): bool
    {
        return $a->strong === $b->strong && $a->em === $b->em && $a->href === $b->href;
    }

    /**
     * Inline leaves made canonical: adjacent text of one format joined, runs of spaces made one, spaces at the edges
     * and around a line break removed, a link with nothing but spaces made text.
     *
     * @param list<RichTextLeaf> $leaves
     * @return list<RichTextLeaf>
     */
    private static function tidyLeaves(array $leaves): array
    {
        $out = [];
        foreach ($leaves as $item) {
            if ($item->kind === 'text' && $item->value === '') {
                continue;
            }
            $last = $out[count($out) - 1] ?? null;
            if ($item->kind === 'text' && $last?->kind === 'text' && self::sameFormat($last, $item)) {
                $last->value .= $item->value;
            } else {
                $out[] = clone $item;
            }
        }
        // One space between words, whatever the leaves.
        foreach ($out as $index => $item) {
            if ($item->kind !== 'text') {
                continue;
            }
            $item->value = (string) preg_replace('/ {2,}/', ' ', $item->value);
            $previous = $out[$index - 1] ?? null;
            if (str_starts_with($item->value, ' ') && ($previous === null || $previous->kind === 'br' || ($previous->kind === 'text' && str_ends_with($previous->value, ' ')))) {
                $item->value = substr($item->value, 1);
            }
            $next = $out[$index + 1] ?? null;
            if (str_ends_with($item->value, ' ') && ($next === null || $next->kind === 'br')) {
                $item->value = substr($item->value, 0, -1);
            }
        }
        $kept = array_values(array_filter($out, static fn (RichTextLeaf $item): bool => $item->kind === 'br' || $item->value !== ''));
        while ($kept !== [] && $kept[0]->kind === 'br') {
            array_shift($kept);
        }
        while ($kept !== [] && $kept[count($kept) - 1]->kind === 'br') {
            array_pop($kept);
        }
        $ws = Js::WHITE_SPACE;
        if ($kept !== [] && $kept[0]->kind === 'text') {
            $kept[0]->value = (string) preg_replace('/^['.$ws.']+/u', '', $kept[0]->value);
        }
        $end = $kept === [] ? null : $kept[count($kept) - 1];
        if ($end?->kind === 'text') {
            $end->value = (string) preg_replace('/['.$ws.']+$/uD', '', $end->value);
        }
        $kept = array_values(array_filter($kept, static fn (RichTextLeaf $item): bool => $item->kind === 'br' || $item->value !== ''));
        // A link of nothing but spaces is not a link.
        $groups = [];
        foreach ($kept as $item) {
            if ($item->href === null || $item->href === '') {
                continue;
            }
            $groups[$item->href] = ($groups[$item->href] ?? '').($item->kind === 'text' ? $item->value : 'x');
        }
        foreach ($kept as $item) {
            if ($item->href !== null && $item->href !== '' && Js::trim($groups[$item->href]) === '') {
                $item->href = null;
            }
        }
        // Join again: dropping spaces and links may have made neighbours alike.
        $joined = [];
        foreach ($kept as $item) {
            $last = $joined[count($joined) - 1] ?? null;
            if ($item->kind === 'text' && $last?->kind === 'text' && self::sameFormat($last, $item)) {
                $last->value .= $item->value;
            } else {
                $joined[] = $item;
            }
        }

        return $joined;
    }

    /** @param list<RichTextLeaf> $leaves */
    private static function hasText(array $leaves): bool
    {
        foreach ($leaves as $item) {
            if ($item->kind === 'text' && preg_replace('/['.Js::WHITE_SPACE.']+/u', '', $item->value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{children: list<array<string, mixed>>} $item
     * @return array{children: list<array<string, mixed>>}|null
     */
    private static function tidyItem(array $item): ?array
    {
        $children = [];
        foreach ($item['children'] as $part) {
            if ($part['type'] === 'inline') {
                $leaves = self::tidyLeaves($part['leaves']);
                if ($leaves === []) {
                    continue;
                }
                $last = count($children) - 1;
                if ($last >= 0 && $children[$last]['type'] === 'inline') {
                    $children[$last]['leaves'] = self::tidyLeaves([...$children[$last]['leaves'], new RichTextLeaf('br', '', false, false, null), ...$leaves]);
                } else {
                    $children[] = ['type' => 'inline', 'leaves' => $leaves];
                }
            } else {
                $list = self::tidyList($part);
                if ($list === null) {
                    continue;
                }
                $last = count($children) - 1;
                if ($last >= 0 && $children[$last]['type'] === $list['type']) {
                    array_push($children[$last]['items'], ...$list['items']);
                } else {
                    $children[] = $list;
                }
            }
        }
        $filtered = [];
        foreach ($children as $part) {
            if ($part['type'] !== 'inline' || self::hasText($part['leaves'])) {
                $filtered[] = $part;
            }
        }

        return $filtered === [] ? null : ['children' => $filtered];
    }

    /**
     * @param array<string, mixed> $list
     * @return array{type: string, items: list<array<string, mixed>>}|null
     */
    private static function tidyList(array $list): ?array
    {
        $items = array_values(array_filter(array_map(self::tidyItem(...), $list['items']), static fn (?array $item): bool => $item !== null));

        return $items === [] ? null : ['type' => $list['type'], 'items' => $items];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private static function tidyBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $tidy = null;
            if ($block['type'] === 'blockquote') {
                $inner = self::tidyBlocks($block['blocks']);
                if ($inner !== []) {
                    $tidy = ['type' => 'blockquote', 'blocks' => $inner];
                }
            } elseif (in_array($block['type'], self::LISTS, true)) {
                $tidy = self::tidyList($block);
            } else {
                $leaves = self::tidyLeaves($block['leaves']);
                if (self::hasText($leaves)) {
                    $tidy = ['type' => $block['type'], 'leaves' => $leaves];
                }
            }
            if ($tidy === null) {
                continue;
            }
            $last = count($out) - 1;
            // Adjacent lists of one kind are one list.
            if ($last >= 0 && in_array($tidy['type'], self::LISTS, true) && $out[$last]['type'] === $tidy['type']) {
                array_push($out[$last]['items'], ...$tidy['items']);
            } else {
                $out[] = $tidy;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<array<string, mixed>>
     */
    private static function cleanTree(array $tree): array
    {
        $flow = new RichTextFlow('flow');
        self::walkChildren($tree['children'], $flow, ['marks' => [], 'href' => null, 'pre' => false]);
        $flow->flush();

        return self::tidyBlocks($flow->blocks);
    }

    // ---------------------------------------------------------------- writing

    private static function escapeText(string $text): string
    {
        return str_replace(['&', '<', '>', "\u{00A0}"], ['&amp;', '&lt;', '&gt;', '&nbsp;'], $text);
    }

    private static function escapeAttribute(string $text): string
    {
        return str_replace(['&', '"', '<', '>', "\u{00A0}"], ['&amp;', '&quot;', '&lt;', '&gt;', '&nbsp;'], $text);
    }

    /** @param list<RichTextLeaf> $leaves */
    private static function writeLeaves(array $leaves): string
    {
        return self::writeGroup($leaves, 0);
    }

    /** @param list<RichTextLeaf> $items */
    private static function writeGroup(array $items, int $level): string
    {
        $key = ['href', 'strong', 'em'][$level] ?? null;
        $out = '';
        $at = 0;
        $count = count($items);
        while ($at < $count) {
            $value = $key !== null ? $items[$at]->{$key} : null;
            $end = $at + 1;
            while ($end < $count && $key !== null && $items[$end]->{$key} === $value) {
                $end++;
            }
            $group = array_slice($items, $at, $end - $at);
            if ($key === null) {
                foreach ($group as $item) {
                    $out .= $item->kind === 'br' ? '<br>' : self::escapeText($item->value);
                }
            } elseif ($key === 'href' && $value !== null && $value !== '') {
                $out .= '<a href="'.self::escapeAttribute($value).'">'.self::writeGroup($group, $level + 1).'</a>';
            } elseif ($key !== 'href' && $value === true) {
                $out .= '<'.$key.'>'.self::writeGroup($group, $level + 1).'</'.$key.'>';
            } else {
                $out .= self::writeGroup($group, $level + 1);
            }
            $at = $end;
        }

        return $out;
    }

    /** @param array<string, mixed> $list */
    private static function writeList(array $list): string
    {
        $items = '';
        foreach ($list['items'] as $item) {
            $items .= '<li>';
            foreach ($item['children'] as $part) {
                $items .= $part['type'] === 'inline' ? self::writeLeaves($part['leaves']) : self::writeList($part);
            }
            $items .= '</li>';
        }

        return '<'.$list['type'].'>'.$items.'</'.$list['type'].'>';
    }

    /** @param list<array<string, mixed>> $blocks */
    private static function serialize(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            if ($block['type'] === 'blockquote') {
                $out .= '<blockquote>'.self::serialize($block['blocks']).'</blockquote>';
            } elseif (in_array($block['type'], self::LISTS, true)) {
                $out .= self::writeList($block);
            } else {
                $out .= '<'.$block['type'].'>'.self::writeLeaves($block['leaves']).'</'.$block['type'].'>';
            }
        }

        return $out;
    }
}
