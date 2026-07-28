<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageComposition
{
    public const SCHEMA = 'larena.layout.page_composition';

    /** @var list<PageBlockInstance> */
    public array $blocks;

    /** @param list<PageSectionInstance> $sections */
    public function __construct(
        public string $layoutId = 'docara.default',
        public array $sections = [],
        public string $schema = self::SCHEMA,
    ) {
        $blocks = [];
        foreach ($sections as $section) {
            foreach ($section->blocks as $block) {
                $blocks[] = $block;
            }
        }
        $this->blocks = $blocks;
    }

    public function isValid(): bool
    {
        if ($this->schema !== self::SCHEMA
            || !LayoutDescriptor::isStableKey($this->layoutId)
            || count($this->sections) > 20
            || count($this->blocks) > 30) {
            return false;
        }

        $sectionIds = [];
        $blockIds = [];
        foreach ($this->sections as $section) {
            if (!$section->isValid() || in_array($section->instanceId, $sectionIds, true)) {
                return false;
            }
            $sectionIds[] = $section->instanceId;
            foreach ($section->blocks as $block) {
                if (in_array($block->instanceId, $blockIds, true)) {
                    return false;
                }
                $blockIds[] = $block->instanceId;
            }
        }

        return true;
    }

    /** @return array{schema:string,layout_id:string,sections:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'layout_id' => $this->layoutId,
            'sections' => array_map(static fn (PageSectionInstance $section): array => $section->toArray(), $this->sections),
        ];
    }
}
