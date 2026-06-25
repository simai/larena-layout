<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\ComposedSection;
use Larena\Layout\Contracts\ContentModel;
use Larena\Layout\Contracts\SectionDefinition;
use Larena\Layout\Contracts\SectionInstance;

final class SectionComposer
{
    public function __construct(
        private readonly AtomicBlockRenderer $atomicBlockRenderer = new AtomicBlockRenderer(),
    ) {
    }

    public function compose(
        SectionInstance $instance,
        SectionDefinition $definition,
        ContentModel $content,
    ): ComposedSection {
        if (!$instance->isValid() || !$definition->isValid() || !$content->isValid()) {
            throw new InvalidArgumentException('layout_section_composer_invalid_input');
        }

        if ($instance->sectionKey !== $definition->sectionKey) {
            throw new InvalidArgumentException('layout_section_composer_definition_mismatch:' . $instance->sectionKey);
        }

        if (!in_array($instance->regionKey, $definition->allowedRegions, true)) {
            throw new InvalidArgumentException('layout_section_composer_region_not_allowed:' . $instance->regionKey);
        }

        $htmlById = [];
        foreach ($instance->composition as $placement) {
            if (!in_array($placement->blockKey, $definition->allowedAtomicBlocks, true)) {
                throw new InvalidArgumentException('layout_section_composer_unknown_atomic_block:' . $placement->blockKey);
            }

            $htmlById[$placement->instanceId] = $this->atomicBlockRenderer->render($placement, $content);
        }

        return new ComposedSection(
            $instance,
            $htmlById,
            '<section data-larena-section-id="' . $this->escape($instance->instanceId) . '"'
                . ' data-larena-section-code="' . $this->escape($instance->sectionKey) . '">'
                . implode('', $htmlById)
                . '</section>',
            [
                'section-composer:in-memory',
                'section:' . $instance->instanceId,
                'atomic-blocks:' . count($htmlById),
            ],
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
