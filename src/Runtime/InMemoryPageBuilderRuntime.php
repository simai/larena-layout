<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\LayoutDescriptor;
use Larena\Layout\Contracts\PageBuilderPreview;
use Larena\Layout\Contracts\PageDescriptor;
use Larena\Layout\Contracts\SectionDefinition;
use Larena\Layout\Contracts\SectionInstance;

final class InMemoryPageBuilderRuntime
{
    public function __construct(
        private readonly InMemoryDataRuntime $dataRuntime = new InMemoryDataRuntime(),
        private readonly SectionComposer $sectionComposer = new SectionComposer(),
    ) {
    }

    /**
     * @param list<SectionInstance> $instances
     * @param array<string, SectionDefinition> $definitionsByKey
     * @param array<string, array<string, mixed>> $dataFixtures
     */
    public function preview(
        PageDescriptor $page,
        LayoutDescriptor $layout,
        array $instances,
        array $definitionsByKey,
        array $dataFixtures,
    ): PageBuilderPreview {
        $this->assertPageMatchesLayout($page, $layout);
        $this->assertUniqueSectionInstances($instances);

        $sections = [];
        foreach ($instances as $instance) {
            if (!$instance->isValid()) {
                throw new InvalidArgumentException('layout_page_builder_invalid_section_instance:' . $instance->instanceId);
            }

            $definition = $definitionsByKey[$instance->sectionKey] ?? null;
            if (!$definition instanceof SectionDefinition || !$definition->isValid()) {
                throw new InvalidArgumentException('layout_page_builder_unknown_section_definition:' . $instance->sectionKey);
            }

            $content = $this->dataRuntime->resolve($instance, $definition, $dataFixtures);
            $sections[] = $this->sectionComposer->compose($instance, $definition, $content);
        }

        return new PageBuilderPreview(
            $page,
            $sections,
            '<main data-larena-page-builder-preview="' . $this->escape($page->pageKey) . '">'
                . implode('', array_map(static fn ($section): string => $section->html, $sections))
                . '</main>',
            [
                'mode' => 'draft_preview',
                'storage_enabled' => false,
                'publish_enabled' => false,
                'writes_enabled' => false,
            ],
            [
                'page-builder-runtime:in-memory',
                'page:' . $page->pageKey,
                'layout:' . $layout->layoutKey,
                'sections:' . count($sections),
                'draft-preview-boundary:read-only',
            ],
        );
    }

    private function assertPageMatchesLayout(PageDescriptor $page, LayoutDescriptor $layout): void
    {
        if (!$page->isValid() || !$layout->isValid()) {
            throw new InvalidArgumentException('layout_page_builder_invalid_descriptor');
        }

        if ($page->layoutBinding->layoutKey !== $layout->layoutKey) {
            throw new InvalidArgumentException('layout_page_builder_layout_binding_mismatch');
        }
    }

    /**
     * @param list<SectionInstance> $instances
     */
    private function assertUniqueSectionInstances(array $instances): void
    {
        if ($instances === []) {
            throw new InvalidArgumentException('layout_page_builder_sections_required');
        }

        $ids = [];
        foreach ($instances as $instance) {
            if (in_array($instance->instanceId, $ids, true)) {
                throw new InvalidArgumentException('layout_page_builder_duplicate_section_instance:' . $instance->instanceId);
            }

            $ids[] = $instance->instanceId;
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
