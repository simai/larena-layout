<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

final readonly class LegacyPageDescriptorAdapter
{
    public function __construct(
        private PageDescriptorNormalizer $legacy = new PageDescriptorNormalizer(),
        private PageAssemblyDescriptorNormalizer $assembly = new PageAssemblyDescriptorNormalizer(),
    ) {
    }

    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public function adapt(array $descriptor, string $siteId): array
    {
        $legacy = $this->legacy->normalize($descriptor);
        $regions = [];
        foreach ($legacy['regions'] as $region) {
            $sections = [];
            foreach ($region['sections'] as $section) {
                $blocks = [];
                foreach ($section['blocks'] as $block) {
                    $blocks[] = [
                        'id' => $block['id'],
                        'component' => $block['component'],
                        'view' => 'default',
                        'preset' => null,
                        'modifiers' => [],
                        'props' => [],
                        'sort' => $block['sort'],
                        'bindings' => $block['bindings'],
                    ];
                }
                $sections[] = [
                    'id' => $section['id'],
                    'component' => $section['component'],
                    'view' => 'default',
                    'preset' => null,
                    'modifiers' => [],
                    'props' => [],
                    'sort' => $section['sort'],
                    'blocks' => $blocks,
                ];
            }
            $regions[] = [
                'id' => $region['id'],
                'sort' => $region['sort'],
                'sections' => $sections,
            ];
        }

        return $this->assembly->normalize([
            'schema' => PageAssemblyDescriptorNormalizer::SCHEMA,
            'site_id' => $siteId,
            'page_id' => $legacy['page_id'],
            'scope_ref' => $legacy['scope_ref'],
            'layout_id' => $legacy['layout_id'],
            'regions' => $regions,
        ]);
    }
}
