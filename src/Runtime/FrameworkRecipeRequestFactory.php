<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\PageComposition;

final readonly class FrameworkRecipeRequestFactory
{
    private const CONTRACT_DIGEST = 'sha256:63daf55cb7d7c39d55f59a5ba3f5d7e511ace3f16d344879cbc877782ff3dcfd';

    /** @param list<array<string,mixed>> $resolvedBlocks @return array<string,mixed> */
    public function article(PageComposition $composition, array $resolvedBlocks, string $scope, string $headerMode): array
    {
        if (! $composition->isValid() || ! in_array($headerMode, ['compact', 'expanded'], true) || $scope === '') {
            throw new InvalidArgumentException('layout_framework_recipe_source_invalid');
        }
        $resolvedById = [];
        foreach ($resolvedBlocks as $block) {
            if (! is_string($block['instance_id'] ?? null) || isset($resolvedById[$block['instance_id']])) {
                throw new InvalidArgumentException('layout_framework_recipe_block_invalid');
            }
            $resolvedById[$block['instance_id']] = $block;
        }
        $text = null;
        foreach ($composition->blocks as $block) {
            if ($block->type === 'text' && $block->enabled) {
                $body = $resolvedById[$block->instanceId]['settings']['body'] ?? null;
                if (! is_string($body)) {
                    throw new InvalidArgumentException('layout_framework_recipe_content_missing');
                }
                $text = [['type' => 'text', 'value' => $body]];
                break;
            }
        }
        if ($text === null) {
            throw new InvalidArgumentException('layout_framework_recipe_content_missing');
        }
        $settingOrigin = ['scope' => $scope, 'owner' => 'larena/setting', 'ref' => 'header.mode', 'revision' => '1'];
        $contentOrigin = ['scope' => $scope, 'owner' => 'larena/content', 'ref' => 'article.demo', 'revision' => '1'];

        return [
            'recipe' => $this->recipe(),
            'inputs' => ['schema' => 'simai.composition.inputs.v1', 'scope' => $scope, 'values' => [
                'headerMode' => ['kind' => 'setting', 'value' => $headerMode, 'valueDigest' => $this->digest($headerMode), 'origin' => $settingOrigin],
                'body' => ['kind' => 'content', 'value' => $text, 'valueDigest' => $this->digest($text), 'origin' => $contentOrigin],
            ]],
            'trustedContext' => ['scope' => $scope],
            'executionContract' => ['contractDigest' => self::CONTRACT_DIGEST, 'registryDigest' => 'sha256:larena-registry', 'rendererDigest' => 'sha256:larena-renderer'],
            'sources' => [
                ['kind' => 'template', 'owner' => 'larena/layout', 'ref' => 'article', 'revision' => '1', 'source' => $this->pageTemplate()],
                ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.compact', 'revision' => '2', 'source' => $this->header('Краткая шапка')],
                ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.expanded', 'revision' => '2', 'source' => $this->header('Расширенная шапка')],
            ],
        ];
    }

    /**
     * Build the bounded first adapter from a persisted Larena page assembly to
     * the neutral Framework Recipe contract.
     *
     * @param array<string, mixed> $projection
     * @return array<string, mixed>
     */
    public function boundHeading(array $projection, string $scope): array
    {
        if (($projection['schema'] ?? null) !== 'larena.layout.page_projection'
            || ($projection['scope_ref'] ?? null) !== $scope
            || ! is_string($projection['page_id'] ?? null)
            || preg_match('/^[a-z][a-z0-9_.:-]{1,119}$/D', $projection['page_id']) !== 1
        ) {
            throw new InvalidArgumentException('layout_framework_recipe_projection_invalid');
        }

        $regions = $projection['regions'] ?? null;
        if (! is_array($regions) || count($regions) !== 1
            || ! is_array($regions[0]['sections'] ?? null) || count($regions[0]['sections']) !== 1
        ) {
            throw new InvalidArgumentException('layout_framework_recipe_projection_shape_unsupported');
        }
        $section = $regions[0]['sections'][0];
        $blocks = is_array($section) ? ($section['blocks'] ?? null) : null;
        if (! is_array($section) || ($section['component'] ?? null) !== 'layout.section'
            || ! is_array($blocks) || count($blocks) !== 1
        ) {
            throw new InvalidArgumentException('layout_framework_recipe_projection_shape_unsupported');
        }
        $block = $blocks[0];
        $bindings = is_array($block) ? ($block['bindings'] ?? null) : null;
        if (! is_array($block) || ($block['component'] ?? null) !== 'ui.input'
            || ! is_array($bindings) || count($bindings) !== 1
        ) {
            throw new InvalidArgumentException('layout_framework_recipe_projection_component_unsupported');
        }
        $binding = $bindings[0];
        $title = is_array($binding) ? ($binding['value']['values']['title'] ?? null) : null;
        if (($binding['kind'] ?? null) !== 'storage_record'
            || ($binding['selector'] ?? null) !== 'title'
            || ! is_string($binding['target'] ?? null)
            || ! is_int($binding['expected_revision'] ?? null)
            || ! is_string($title) || trim($title) === '' || mb_strlen($title) > 160
        ) {
            throw new InvalidArgumentException('layout_framework_recipe_projection_binding_unsupported');
        }

        $content = [['type' => 'text', 'value' => $title]];
        $origin = [
            'scope' => $scope,
            'owner' => 'larena/storage',
            'ref' => $binding['target'].'#title',
            'revision' => (string) $binding['expected_revision'],
        ];

        return [
            'recipe' => [
                'schema' => 'simai.composition.recipe.v1',
                'id' => 'larena.bound-heading-page',
                'profile' => 'ui-layout',
                'locale' => 'ru',
                'inputs' => [
                    'heading' => [
                        'kind' => 'content',
                        'schema' => ['type' => 'array'],
                        'expectedOrigin' => $origin,
                    ],
                ],
                'root' => [
                    'id' => 'page',
                    'ref' => [
                        'kind' => 'template', 'owner' => 'larena/layout',
                        'ref' => 'bound-heading-page', 'policy' => 'pinned', 'revision' => '1',
                    ],
                    'slots' => [
                        'main' => [[
                            'id' => (string) ($section['id'] ?? 'section.main'),
                            'node' => [
                                'type' => 'layout.section',
                                'slots' => ['default' => [[
                                    'id' => (string) ($block['id'] ?? 'block.title'),
                                    'node' => [
                                        'type' => 'content.heading',
                                        'data' => [
                                            'level' => ['literal' => 1],
                                            'content' => ['input' => 'heading'],
                                        ],
                                        'bindings' => [['input' => 'heading', 'target' => 'content']],
                                    ],
                                ]]],
                            ],
                        ]],
                    ],
                ],
            ],
            'inputs' => [
                'schema' => 'simai.composition.inputs.v1',
                'scope' => $scope,
                'values' => [
                    'heading' => [
                        'kind' => 'content', 'value' => $content,
                        'valueDigest' => $this->digest($content), 'origin' => $origin,
                    ],
                ],
            ],
            'trustedContext' => ['scope' => $scope],
            'executionContract' => [
                'contractDigest' => self::CONTRACT_DIGEST,
                'registryDigest' => 'sha256:larena-registry',
                'rendererDigest' => 'sha256:larena-renderer',
            ],
            'sources' => [[
                'kind' => 'template', 'owner' => 'larena/layout',
                'ref' => 'bound-heading-page', 'revision' => '1',
                'source' => [
                    'schema' => 'simai.composition.recipe-manifest.v1',
                    'id' => 'bound-heading-page',
                    'parameters' => [],
                    'slots' => ['main' => ['min' => 1, 'max' => 1, 'types' => ['layout.section']]],
                    'body' => [
                        'id' => 'shell',
                        'node' => [
                            'type' => 'layout.page',
                            'slots' => ['default' => [['id' => 'main-slot', 'insertSlot' => 'main']]],
                        ],
                    ],
                ],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function recipe(): array
    {
        return [
            'schema' => 'simai.composition.recipe.v1', 'id' => 'larena.article-demo', 'profile' => 'ui-layout', 'locale' => 'ru',
            'inputs' => [
                'headerMode' => ['kind' => 'setting', 'schema' => ['type' => 'string', 'enum' => ['compact', 'expanded']], 'expectedOrigin' => ['owner' => 'larena/setting', 'ref' => 'header.mode', 'revision' => '1']],
                'body' => ['kind' => 'content', 'schema' => ['type' => 'array'], 'expectedOrigin' => ['owner' => 'larena/content', 'ref' => 'article.demo', 'revision' => '1']],
            ],
            'root' => ['id' => 'page', 'ref' => ['kind' => 'template', 'owner' => 'larena/layout', 'ref' => 'article', 'policy' => 'pinned', 'revision' => '1'], 'slots' => [
                'header' => [['id' => 'header-choice', 'select' => ['value' => ['input' => 'headerMode'], 'cases' => [
                    'compact' => ['id' => 'compact', 'ref' => ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.compact', 'policy' => 'pinned', 'revision' => '2']],
                    'expanded' => ['id' => 'expanded', 'ref' => ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.expanded', 'policy' => 'pinned', 'revision' => '2']],
                ]]]],
                'main' => [['id' => 'article-body', 'node' => ['type' => 'content.paragraph', 'data' => ['content' => ['input' => 'body']], 'bindings' => [['input' => 'body', 'target' => 'content']]]]],
            ]],
        ];
    }

    private function digest(mixed $value): string
    {
        return 'sha256:'.hash('sha256', json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonical($child);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function pageTemplate(): array
    {
        return ['schema' => 'simai.composition.recipe-manifest.v1', 'id' => 'article', 'parameters' => [], 'slots' => [
            'header' => ['min' => 1, 'max' => 1, 'types' => ['layout.section']], 'main' => ['min' => 1, 'max' => 1, 'types' => ['content.paragraph']],
        ], 'body' => ['id' => 'shell', 'node' => ['type' => 'layout.page', 'slots' => ['default' => [
            ['id' => 'header-slot', 'insertSlot' => 'header'], ['id' => 'main-slot', 'insertSlot' => 'main'],
        ]]]]];
    }

    /** @return array<string,mixed> */
    private function header(string $title): array
    {
        return ['id' => 'header', 'node' => ['type' => 'layout.section', 'slots' => ['default' => [[
            'id' => 'title', 'node' => ['type' => 'content.heading', 'data' => ['level' => ['literal' => 1], 'content' => ['literal' => [['type' => 'text', 'value' => $title]]]]],
        ]]]]];
    }
}
