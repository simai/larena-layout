<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Exceptions\LayoutRejected;

final readonly class PageDescriptorComponentRegistry
{
    /** A block of rich text: its `body`, made safe when the site shows it. */
    public const TEXT = 'larena.text';

    /** A block of links to the site's pages: `root` (`top` or `current`), `depth` (1 to 3) and a `title`. */
    public const MENU = 'larena.menu';

    /**
     * @param list<string> $sectionComponents
     * @param list<string> $blockComponents
     */
    public function __construct(
        private array $sectionComponents = ['larena.section'],
        private array $blockComponents = ['larena.content', 'larena.asset', 'larena.record', 'larena.tree', 'larena.list', self::TEXT, self::MENU],
    ) {
    }

    public function assertSection(string $component): void
    {
        if (!in_array($component, $this->sectionComponents, true)) {
            throw new LayoutRejected('layout_descriptor_section_component_unknown');
        }
    }

    public function assertBlock(string $component): void
    {
        if (!$this->hasBlock($component)) {
            throw new LayoutRejected('layout_descriptor_block_component_unknown');
        }
    }

    /** Whether a page may hold a block of this component (a content@1 block type, PageContentBlockValidator). */
    public function hasBlock(string $component): bool
    {
        return in_array($component, $this->blockComponents, true);
    }
}
