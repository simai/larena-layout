<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Exceptions\LayoutRejected;

final readonly class PageDescriptorComponentRegistry
{
    /**
     * @param list<string> $sectionComponents
     * @param list<string> $blockComponents
     */
    public function __construct(
        private array $sectionComponents = ['larena.section'],
        private array $blockComponents = ['larena.content', 'larena.asset', 'larena.record', 'larena.tree'],
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
        if (!in_array($component, $this->blockComponents, true)) {
            throw new LayoutRejected('layout_descriptor_block_component_unknown');
        }
    }
}
