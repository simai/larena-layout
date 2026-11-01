<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/**
 * The declared-region rules of a ui-layout Document (the SIMAI Framework's regions.mjs): a region stands in its
 * region layout, names are unique in a layout, side-by-side regions go start, center, end, landmarks are used once
 * where the document has one and carry labels where they repeat.
 */
final class RegionRules
{
    public const ELEMENTS = [
        'banner' => 'header',
        'navigation' => 'nav',
        'main' => 'main',
        'complementary' => 'aside',
        'contentinfo' => 'footer',
        'region' => 'section',
        'none' => 'div',
    ];

    private const MAX_REGION_LAYOUT_NESTING = 4;

    private const DOCUMENT_LEVEL_LANDMARKS = ['banner', 'main', 'contentinfo'];

    private const PLACEMENT_RANK = ['start' => 0, 'center' => 1, 'end' => 2];

    /** @param list<array{code: string, path: string, message: string}> $diagnostics */
    public static function validate(mixed $root, TypeRegistry $registry, array &$diagnostics): void
    {
        $push = static function (string $code, string $path, string $message) use (&$diagnostics): void {
            $diagnostics[] = ['code' => $code, 'path' => $path, 'message' => $message];
        };
        /** @var array<string, list<array{path: string, label: mixed, labelled: bool}>> $landmarkUse */
        $landmarkUse = [];
        $visit = static function (mixed $node, string $path, array $context) use (&$visit, &$landmarkUse, $push, $registry): void {
            if (! Js::isObject($node)) {
                return;
            }
            $type = Js::get($node, 'type');
            if ($type === 'layout.region' && $context['parentType'] !== 'layout.regions') {
                $push('region_parent_invalid', $path, 'layout.region must be a direct child of layout.regions');
            }
            $next = [...$context, 'parentType' => $type];
            if ($type === 'layout.page') {
                $next['insidePage'] = true;
            }
            if ($type === 'layout.regions') {
                $next['regionDepth'] = $context['regionDepth'] + 1;
                if ($next['regionDepth'] > self::MAX_REGION_LAYOUT_NESTING) {
                    $push('region_nesting_limit', $path, 'Region layouts are nested too deeply');
                }
                self::regionList($node, $path, $push);
            }
            if ($type === 'layout.region') {
                self::regionNode($node, $path, $context, $registry, $push);
                $landmark = self::landmarkOf($node);
                if ($landmark !== null && $landmark !== 'none') {
                    $props = Js::get($node, 'props');
                    $landmarkUse[$landmark][] = ['path' => $path, 'label' => Js::get($props, 'label'), 'labelled' => Js::has($props, 'label')];
                    $next['insideLandmark'] = true;
                }
            }
            foreach (Js::entries(Js::get($node, 'slots')) as $slotName => $children) {
                if (is_array($children)) {
                    foreach ($children as $index => $child) {
                        $visit($child, $path.'.slots.'.$slotName.'['.$index.']', $next);
                    }
                }
            }
        };
        $visit($root, '$.root', ['parentType' => null, 'insidePage' => false, 'insideLandmark' => false, 'regionDepth' => 0]);
        foreach ($landmarkUse as $landmark => $uses) {
            if ($landmark === 'main' && count($uses) > 1) {
                foreach (array_slice($uses, 1) as $use) {
                    $push('region_landmark_duplicate', $use['path'].'.props.landmark', 'A document can contain only one main region');
                }
                continue;
            }
            if (count($uses) < 2) {
                continue;
            }
            $labels = [];
            foreach ($uses as $use) {
                if (! $use['labelled']) {
                    if ($landmark !== 'region') {
                        $push('region_label_required', $use['path'].'.props.label', 'Repeated '.$landmark.' regions require distinct labels');
                    }
                    continue;
                }
                $key = Js::stableStringify($use['label']);
                if (isset($labels[$key])) {
                    $push('region_label_duplicate', $use['path'].'.props.label', 'Repeated '.$landmark.' regions require distinct labels');
                } else {
                    $labels[$key] = true;
                }
            }
        }
    }

    private static function landmarkOf(mixed $node): ?string
    {
        $landmark = Js::get(Js::get($node, 'props'), 'landmark');

        return Js::get($node, 'type') === 'layout.region' && is_string($landmark) ? $landmark : null;
    }

    private static function regionList(\stdClass $node, string $path, \Closure $push): void
    {
        $regions = Js::get(Js::get($node, 'slots'), 'regions');
        $names = [];
        $bandRank = -1;
        foreach (is_array($regions) ? $regions : [] as $index => $region) {
            $regionPath = $path.'.slots.regions['.$index.']';
            if (Js::get($region, 'type') !== 'layout.region') {
                continue;
            }
            $props = Js::get($region, 'props');
            $name = Js::get($props, 'name');
            if (is_string($name)) {
                if (isset($names[$name])) {
                    $push('region_name_duplicate', $regionPath.'.props.name', 'Region '.$name.' is declared twice in one layout');
                }
                $names[$name] = true;
            }
            $placement = Js::get($props, 'placement') ?? 'block';
            if ($placement === 'block') {
                $bandRank = -1;
                continue;
            }
            $rank = is_string($placement) ? (self::PLACEMENT_RANK[$placement] ?? null) : null;
            if ($rank === null) {
                continue;
            }
            if ($rank < $bandRank) {
                $push('region_order_invalid', $regionPath.'.props.placement', 'Side-by-side regions must be declared in start, center, end order');
            }
            $bandRank = max($bandRank, $rank);
        }
    }

    /** @param array{parentType: mixed, insidePage: bool, insideLandmark: bool, regionDepth: int} $context */
    private static function regionNode(\stdClass $node, string $path, array $context, TypeRegistry $registry, \Closure $push): void
    {
        $props = Js::get($node, 'props');
        $props = Js::isObject($props) ? $props : new \stdClass();
        $landmark = self::landmarkOf($node);
        if ($landmark !== null && in_array($landmark, self::DOCUMENT_LEVEL_LANDMARKS, true) && ($context['insideLandmark'] || $context['insidePage'])) {
            $push('region_landmark_context', $path.'.props.landmark', $landmark.' is only allowed outside other landmarks and layout.page');
        }
        if ($landmark === 'region' && ! Js::has($props, 'label')) {
            $push('region_label_required', $path.'.props.label', 'A region landmark requires an accessible label');
        }
        if ($landmark === 'none' && Js::has($props, 'label')) {
            $push('region_label_forbidden', $path.'.props.label', 'A region without a landmark cannot carry an accessible label');
        }
        $children = Js::get(Js::get($node, 'slots'), 'default');
        $children = is_array($children) ? $children : [];
        $min = Js::isInteger(Js::get($props, 'min_items')) ? (int) Js::get($props, 'min_items') : 0;
        $max = Js::isInteger(Js::get($props, 'max_items')) ? (int) Js::get($props, 'max_items') : 500;
        if ($min > $max) {
            $push('region_bounds_invalid', $path.'.props', 'min_items must not exceed max_items');
        } elseif (count($children) < $min || count($children) > $max) {
            $push('region_cardinality', $path.'.slots.default', 'Region child count is outside its declared range');
        }
        $accepts = Js::get($props, 'accepts');
        if (is_array($accepts)) {
            foreach ($children as $index => $child) {
                $manifest = $registry->manifest(Js::get($child, 'type'));
                if ($manifest !== null && ! in_array($manifest['category'], $accepts, true)) {
                    $push('region_child_category_forbidden', $path.'.slots.default['.$index.']', $manifest['category'].' is not accepted by this region');
                }
            }
        }
    }
}
