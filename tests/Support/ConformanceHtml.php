<?php

declare(strict_types=1);

namespace Larena\Layout\Tests\Support;

/**
 * The structured-content conformance kit's comparison rule (README.md, "Comparing HTML"), read in PHP: two HTML
 * fragments are equal when their canonical forms are equal. Like the kit's own reference reading, it reads the
 * well-formed markup a renderer writes and does not repair broken markup as a browser would. normalization.json
 * holds the pairs this reading must decide as the rule does (StructuredContentComparisonTest).
 */
final class ConformanceHtml
{
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const RAW = ['script', 'style'];

    // Phrasing elements: white space next to them is text. Every other element stands as a block.
    private const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'img', 'kbd', 'mark', 'q',
        's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
        'sf-badge', 'sf-button', 'sf-icon',
    ];

    private const NAMED = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{00A0}"];

    private const WHITE = '/[\t\n\f\r ]+/';

    public static function canonical(string $html): string
    {
        return self::serialize(self::settle(self::parse($html), false));
    }

    public static function same(string $left, string $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    /**
     * @param list<array{code: string, path: string}> $diagnostics
     * @return list<string> "code path", sorted: what the kit compares
     */
    public static function diagnosticKeys(array $diagnostics): array
    {
        $keys = array_map(static fn (array $entry): string => $entry['code'].' '.$entry['path'], $diagnostics);
        sort($keys, SORT_STRING);

        return $keys;
    }

    private static function decode(string $text): string
    {
        return (string) preg_replace_callback('/&(?:#(\d+)|#[xX]([0-9a-fA-F]+)|([a-zA-Z]+));/', static function (array $match): string {
            /** @var array<int, string> $match */
            if (($match[1] ?? '') !== '') {
                return (string) mb_chr((int) $match[1], 'UTF-8');
            }
            if (($match[2] ?? '') !== '') {
                return (string) mb_chr((int) hexdec($match[2]), 'UTF-8');
            }

            return self::NAMED[$match[3]] ?? $match[0];
        }, $text);
    }

    /** @return array{type: string, name: string, attributes: list<array{0: string, 1: string}>, children: list<array<string, mixed>>} */
    private static function parse(string $html): array
    {
        /** @var array{type: string, name: string, attributes: list<array{0: string, 1: string}>, children: list<array<string, mixed>>} $root */
        $root = ['type' => 'element', 'name' => '#fragment', 'attributes' => [], 'children' => []];
        $stack = [&$root];
        $text = static function (string $value) use (&$stack): void {
            $top = &$stack[count($stack) - 1];
            $last = count($top['children']) - 1;
            if ($last >= 0 && $top['children'][$last]['type'] === 'text') {
                $top['children'][$last]['value'] .= $value;
            } else {
                $top['children'][] = ['type' => 'text', 'value' => $value];
            }
        };
        $length = strlen($html);
        $at = 0;
        while ($at < $length) {
            $open = strpos($html, '<', $at);
            if ($open === false) {
                $text(self::decode(substr($html, $at)));
                break;
            }
            if ($open > $at) {
                $text(self::decode(substr($html, $at, $open - $at)));
            }
            if (substr_compare($html, '<!--', $open, 4) === 0) {
                $close = strpos($html, '-->', $open + 4);
                $at = $close === false ? $length : $close + 3;
                continue;
            }
            if (substr_compare($html, '<!', $open, 2) === 0 || substr_compare($html, '<?', $open, 2) === 0) {
                $close = strpos($html, '>', $open);
                $at = $close === false ? $length : $close + 1;
                continue;
            }
            $rest = substr($html, $open);
            if (preg_match('/^<\/([a-zA-Z][^\s\/>]*)[^>]*>/u', $rest, $end) === 1) {
                $name = strtolower($end[1]);
                $index = -1;
                foreach ($stack as $position => $node) {
                    if ($node['name'] === $name) {
                        $index = $position;
                    }
                }
                if ($index > 0) {
                    array_splice($stack, $index);
                }
                $at = $open + strlen($end[0]);
                continue;
            }
            if (preg_match('/^<([a-zA-Z][^\s\/>]*)/u', $rest, $start) !== 1) {
                $text('<');
                $at = $open + 1;
                continue;
            }
            $element = ['type' => 'element', 'name' => strtolower($start[1]), 'attributes' => [], 'children' => []];
            $cursor = $open + strlen($start[0]);
            $attribute = '/^[\t\n\f\r ]*(?:(\/?>)|([^\s"\'>\/=]+)(?:[\t\n\f\r ]*=[\t\n\f\r ]*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?|\/)/u';
            while (true) {
                if (preg_match($attribute, substr($html, $cursor), $match, PREG_UNMATCHED_AS_NULL) !== 1) {
                    $cursor = $length;
                    break;
                }
                /** @var array<int, string|null> $match */
                $cursor += strlen((string) $match[0]);
                if (($match[1] ?? null) !== null && $match[1] !== '') {
                    break;
                }
                if (($match[2] ?? null) === null || $match[2] === '') {
                    continue;
                }
                $name = strtolower($match[2]);
                // The first of two attributes of one name wins, as a parser keeps it.
                $known = false;
                foreach ($element['attributes'] as [$existing]) {
                    $known = $known || $existing === $name;
                }
                if (! $known) {
                    $element['attributes'][] = [$name, self::decode($match[3] ?? $match[4] ?? $match[5] ?? '')];
                }
            }
            $top = &$stack[count($stack) - 1];
            $top['children'][] = $element;
            $added = &$top['children'][count($top['children']) - 1];
            unset($top);
            $at = $cursor;
            if (in_array($element['name'], self::VOID, true)) {
                unset($added);
                continue;
            }
            if (in_array($element['name'], self::RAW, true)) {
                $close = strpos(strtolower($html), '</'.$element['name'], $at);
                $body = substr($html, $at, ($close === false ? $length : $close) - $at);
                if ($body !== '') {
                    $added['children'][] = ['type' => 'text', 'value' => $body];
                }
                $at = $close === false ? $length : (int) strpos($html, '>', $close) + 1;
                unset($added);
                continue;
            }
            // A parser drops one line feed right after <pre>.
            if ($element['name'] === 'pre' && ($html[$at] ?? '') === "\n") {
                $at++;
            }
            $stack[] = &$added;
            unset($added);
        }

        return $root;
    }

    /** @param array<string, mixed>|null $node */
    private static function isBlock(?array $node): bool
    {
        return $node !== null && $node['type'] === 'element' && ! in_array($node['name'], self::INLINE, true);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function settle(array $node, bool $inPre): array
    {
        if ($node['type'] !== 'element') {
            return $node;
        }
        $pre = $inPre || $node['name'] === 'pre' || in_array($node['name'], self::RAW, true);
        $children = array_map(static fn (array $child): array => self::settle($child, $pre), $node['children']);
        if ($pre) {
            return [...$node, 'children' => $children];
        }
        $settled = [];
        $count = count($children);
        foreach ($children as $index => $child) {
            if ($child['type'] !== 'text') {
                $settled[] = $child;
                continue;
            }
            $value = (string) preg_replace(self::WHITE, ' ', $child['value']);
            $before = $index > 0 ? $children[$index - 1] : $node;
            $after = $index < $count - 1 ? $children[$index + 1] : $node;
            if (self::isBlock($before)) {
                $value = (string) preg_replace('/^ /', '', $value);
            }
            if (self::isBlock($after)) {
                $value = (string) preg_replace('/ $/D', '', $value);
            }
            if ($value !== '') {
                $settled[] = ['type' => 'text', 'value' => $value];
            }
        }

        return [...$node, 'children' => $settled];
    }

    private static function attributeValue(string $name, string $value): string
    {
        if ($name === 'class') {
            $tokens = array_values(array_unique(array_filter((array) preg_split(self::WHITE, $value), static fn ($token): bool => $token !== '')));
            sort($tokens, SORT_STRING);

            return implode(' ', $tokens);
        }
        if ($name === 'style') {
            $declarations = [];
            foreach (explode(';', $value) as $declaration) {
                $declaration = trim((string) preg_replace(self::WHITE, ' ', $declaration));
                if ($declaration === '') {
                    continue;
                }
                $colon = strpos($declaration, ':');
                $declarations[] = $colon === false
                    ? strtolower($declaration)
                    : strtolower(trim(substr($declaration, 0, $colon))).': '.trim(substr($declaration, $colon + 1));
            }
            sort($declarations, SORT_STRING);

            return implode('; ', $declarations);
        }

        return $value;
    }

    /** @param array<string, mixed> $node */
    private static function serialize(array $node, bool $inRaw = false): string
    {
        if ($node['type'] === 'text') {
            return $inRaw ? $node['value'] : str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $node['value']);
        }
        $inner = '';
        foreach ($node['children'] as $child) {
            $inner .= self::serialize($child, in_array($node['name'], self::RAW, true));
        }
        if ($node['name'] === '#fragment') {
            return $inner;
        }
        $attributes = $node['attributes'];
        usort($attributes, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));
        $written = '';
        foreach ($attributes as [$name, $value]) {
            $written .= ' '.$name.'="'.str_replace(['&', '"'], ['&amp;', '&quot;'], self::attributeValue($name, $value)).'"';
        }

        return in_array($node['name'], self::VOID, true) ? '<'.$node['name'].$written.'>' : '<'.$node['name'].$written.'>'.$inner.'</'.$node['name'].'>';
    }
}
