<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\PageComposition;

final readonly class FrameworkRecipeRequestFactory
{
    private const CONTRACT_DIGEST = 'sha256:894de36b030bebdcc539c3616f29f0ca97d20f5aed07b9cd07d0d5448eda1cc2';

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
                ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.compact', 'revision' => '1', 'source' => $this->header('Краткая шапка')],
                ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.expanded', 'revision' => '1', 'source' => $this->header('Расширенная шапка')],
            ],
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
                    'compact' => ['id' => 'compact', 'ref' => ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.compact', 'policy' => 'pinned', 'revision' => '1']],
                    'expanded' => ['id' => 'expanded', 'ref' => ['kind' => 'fragment', 'owner' => 'larena/layout', 'ref' => 'header.expanded', 'policy' => 'pinned', 'revision' => '1']],
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
            'id' => 'title', 'node' => ['type' => 'content.heading', 'data' => ['level' => ['literal' => 2], 'content' => ['literal' => [['type' => 'text', 'value' => $title]]]]],
        ]]]]];
    }
}
