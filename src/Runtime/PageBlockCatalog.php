<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\PageBlockDefinition;
use Larena\Layout\Contracts\PageBlockFieldDefinition;

final class PageBlockCatalog
{
    /** @var array<string, PageBlockDefinition> */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [];
        foreach ($this->defaults() as $definition) {
            if (!$definition->isValid()) {
                throw new InvalidArgumentException('layout_page_block_definition_invalid:' . $definition->key);
            }
            $this->definitions[$definition->key] = $definition;
        }
    }

    /** @return list<PageBlockDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function require(string $type): PageBlockDefinition
    {
        return $this->definitions[$type] ?? throw new InvalidArgumentException('layout_page_block_unknown_type:' . $type);
    }

    /** @return list<array<string,mixed>> */
    public function editorSchema(): array
    {
        return array_map(static fn (PageBlockDefinition $definition): array => [
            'key' => $definition->key,
            'label_key' => $definition->labelKey,
            'smart_view' => $definition->smartView,
            'fields' => array_map(static fn (PageBlockFieldDefinition $field): array => [
                'key' => $field->key,
                'label_key' => $field->labelKey,
                'type' => $field->type,
                'required' => $field->required,
                'max_length' => $field->maxLength,
                'options' => $field->options,
                'default' => $field->default,
            ], $definition->fields),
        ], $this->all());
    }

    /** @return list<PageBlockDefinition> */
    private function defaults(): array
    {
        return [
            new PageBlockDefinition('text', 'blocks.types.text', 'docara.text', [
                new PageBlockFieldDefinition('heading', 'blocks.fields.heading', 'string', false, 160),
                new PageBlockFieldDefinition('body', 'blocks.fields.body', 'text', true, 12000),
                new PageBlockFieldDefinition('alignment', 'blocks.fields.alignment', 'select', true, 0, ['left', 'center'], 'left'),
            ]),
            new PageBlockDefinition('image', 'blocks.types.image', 'docara.image', [
                new PageBlockFieldDefinition('file_ref', 'blocks.fields.image', 'file', true, 100),
                new PageBlockFieldDefinition('alt', 'blocks.fields.alt', 'string', true, 255),
                new PageBlockFieldDefinition('caption', 'blocks.fields.caption', 'string', false, 500),
            ]),
            new PageBlockDefinition('hero', 'blocks.types.hero', 'docara.hero', [
                new PageBlockFieldDefinition('eyebrow', 'blocks.fields.eyebrow', 'string', false, 120),
                new PageBlockFieldDefinition('title', 'blocks.fields.title', 'string', true, 220),
                new PageBlockFieldDefinition('body', 'blocks.fields.body', 'text', false, 3000),
                new PageBlockFieldDefinition('image_file_ref', 'blocks.fields.image', 'file', false, 100),
                new PageBlockFieldDefinition('cta_label', 'blocks.fields.cta_label', 'string', false, 120),
                new PageBlockFieldDefinition('cta_url', 'blocks.fields.cta_url', 'url', false, 500),
                new PageBlockFieldDefinition('style', 'blocks.fields.style', 'select', true, 0, ['default', 'accent'], 'default'),
            ], [['cta_label', 'cta_url']]),
            new PageBlockDefinition('columns', 'blocks.types.columns', 'docara.columns', [
                new PageBlockFieldDefinition('left_title', 'blocks.fields.left_title', 'string', false, 160),
                new PageBlockFieldDefinition('left_body', 'blocks.fields.left_body', 'text', true, 6000),
                new PageBlockFieldDefinition('right_title', 'blocks.fields.right_title', 'string', false, 160),
                new PageBlockFieldDefinition('right_body', 'blocks.fields.right_body', 'text', true, 6000),
            ]),
            new PageBlockDefinition('cta', 'blocks.types.cta', 'docara.cta', [
                new PageBlockFieldDefinition('title', 'blocks.fields.title', 'string', true, 220),
                new PageBlockFieldDefinition('body', 'blocks.fields.body', 'text', false, 2000),
                new PageBlockFieldDefinition('label', 'blocks.fields.cta_label', 'string', true, 120),
                new PageBlockFieldDefinition('url', 'blocks.fields.cta_url', 'url', true, 500),
                new PageBlockFieldDefinition('style', 'blocks.fields.style', 'select', true, 0, ['primary', 'secondary'], 'primary'),
            ]),
        ];
    }
}
