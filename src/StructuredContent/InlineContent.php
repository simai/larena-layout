<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/**
 * Inline content as structured content holds it: text runs with strong, em and code marks, links whose children are
 * text runs, and the closed list of inline atoms. Drawn as the SIMAI Framework's renderer draws it: a run's marks
 * nest in the order listed, the first innermost; an atom draws its element with its declared props as attributes.
 */
final class InlineContent
{
    private const TOKEN = '/^[a-z0-9][a-z0-9_\/-]{0,63}$/uD';

    private const MAX_PROP_LENGTH = 1000;

    // Each atom draws one element; its props are a text, a token (a name from a fixed set) or a boolean.
    private const COMPONENTS = [
        'badge' => ['tag' => 'sf-badge', 'required' => [], 'props' => ['text' => 'text', 'icon' => 'token', 'appearance' => 'token', 'scheme' => 'token', 'status' => 'token', 'size' => 'token', 'compact' => 'boolean']],
        'kbd' => ['tag' => 'kbd', 'required' => ['text'], 'props' => ['text' => 'text']],
        'icon' => ['tag' => 'sf-icon', 'required' => ['icon'], 'props' => ['icon' => 'token', 'label' => 'text', 'size' => 'token', 'filled' => 'boolean']],
        'button' => ['tag' => 'sf-button', 'required' => [], 'props' => ['text' => 'text', 'icon' => 'token', 'appearance' => 'token', 'scheme' => 'token', 'size' => 'token']],
    ];

    public static function render(mixed $content): string
    {
        if (! is_array($content)) {
            return '';
        }
        $out = '';
        foreach ($content as $inline) {
            $type = Js::get($inline, 'type');
            if ($type === 'link') {
                $out .= '<a href="'.Js::escapeHtml(Js::get($inline, 'href')).'">'.self::render(Js::get($inline, 'children')).'</a>';
                continue;
            }
            if ($type !== 'text') {
                // An atom draws its own element; anything else (validation refuses it) draws nothing.
                $view = self::componentView($inline);
                if ($view === null) {
                    continue;
                }
                $attributes = '';
                foreach ($view['attributes'] as [$name, $value]) {
                    $attributes .= $value === '' ? ' '.$name : ' '.$name.'="'.Js::escapeHtml($value).'"';
                }
                $out .= '<'.$view['tag'].$attributes.'>'.Js::escapeHtml($view['text']).'</'.$view['tag'].'>';
                continue;
            }
            $output = Js::escapeHtml(Js::get($inline, 'value'));
            $marks = Js::get($inline, 'marks');
            foreach (is_array($marks) ? $marks : [] as $mark) {
                $output = match ($mark) {
                    'strong' => '<strong>'.$output.'</strong>',
                    'em' => '<em>'.$output.'</em>',
                    default => '<code>'.$output.'</code>',
                };
            }
            $out .= $output;
        }

        return $out;
    }

    /** @return array{tag: string, attributes: list<array{0: string, 1: mixed}>, text: mixed}|null */
    private static function componentView(mixed $node): ?array
    {
        if (! self::isComponent($node)) {
            return null;
        }
        $entry = self::COMPONENTS[Js::get($node, 'name')];
        $props = Js::entries(Js::get($node, 'props'));
        if (Js::get($node, 'name') === 'kbd') {
            return ['tag' => $entry['tag'], 'attributes' => [], 'text' => $props['text'] ?? null];
        }
        $attributes = [];
        foreach (array_keys($entry['props']) as $key) {
            if (array_key_exists($key, $props) && $props[$key] !== false) {
                $attributes[] = [$key, $props[$key] === true ? '' : $props[$key]];
            }
        }

        return ['tag' => $entry['tag'], 'attributes' => $attributes, 'text' => ''];
    }

    private static function isComponent(mixed $node): bool
    {
        $name = Js::get($node, 'name');
        if (Js::get($node, 'type') !== 'component' || ! is_string($name) || ! array_key_exists($name, self::COMPONENTS)) {
            return false;
        }
        $entry = self::COMPONENTS[$name];
        foreach (array_keys(Js::entries($node)) as $key) {
            if (! in_array($key, ['type', 'name', 'props'], true)) {
                return false;
            }
        }
        $props = Js::get($node, 'props') ?? new \stdClass();
        if (! Js::isObject($props)) {
            return false;
        }
        $values = Js::entries($props);
        foreach ($entry['required'] as $key) {
            if (! array_key_exists($key, $values)) {
                return false;
            }
        }
        foreach ($values as $key => $value) {
            $kind = $entry['props'][$key] ?? null;
            $ok = match ($kind) {
                'boolean' => is_bool($value),
                'token' => is_string($value) && preg_match(self::TOKEN, $value) === 1,
                'text' => is_string($value) && Js::length($value) <= self::MAX_PROP_LENGTH,
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
