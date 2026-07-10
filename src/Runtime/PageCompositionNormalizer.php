<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\PageBlockDefinition;
use Larena\Layout\Contracts\PageBlockFieldDefinition;
use Larena\Layout\Contracts\PageBlockInstance;
use Larena\Layout\Contracts\PageComposition;

final readonly class PageCompositionNormalizer
{
    public function __construct(private PageBlockCatalog $catalog = new PageBlockCatalog())
    {
    }

    /** @param array<int, mixed> $input */
    public function normalize(array $input): PageComposition
    {
        if (count($input) > 30) {
            throw new InvalidArgumentException('layout_page_composition_too_many_blocks');
        }

        $blocks = [];
        $ids = [];
        foreach ($input as $index => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('layout_page_block_invalid:' . $index);
            }
            $instanceId = trim((string) ($raw['instance_id'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_-]{2,80}$/', $instanceId) !== 1 || in_array($instanceId, $ids, true)) {
                throw new InvalidArgumentException('layout_page_block_invalid_or_duplicate_instance:' . $index);
            }
            $ids[] = $instanceId;
            $definition = $this->catalog->require(trim((string) ($raw['type'] ?? '')));
            $settings = $this->settings($definition, is_array($raw['settings'] ?? null) ? $raw['settings'] : []);
            $blocks[] = new PageBlockInstance(
                $instanceId,
                $definition->key,
                filter_var($raw['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                max(0, (int) ($raw['sort'] ?? (($index + 1) * 100))),
                $settings,
                $definition->smartView,
            );
        }

        usort($blocks, static fn (PageBlockInstance $left, PageBlockInstance $right): int => $left->sort <=> $right->sort);
        $composition = new PageComposition($blocks);
        if (!$composition->isValid()) {
            throw new InvalidArgumentException('layout_page_composition_invalid');
        }

        return $composition;
    }

    /** @param array<string,mixed> $raw @return array<string,string> */
    private function settings(PageBlockDefinition $definition, array $raw): array
    {
        $allowed = array_map(static fn (PageBlockFieldDefinition $field): string => $field->key, $definition->fields);
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('layout_page_block_unknown_setting:' . (string) $key);
            }
        }

        $settings = [];
        foreach ($definition->fields as $field) {
            $value = trim((string) ($raw[$field->key] ?? $field->default));
            if ($field->required && $value === '') {
                throw new InvalidArgumentException('layout_page_block_required_setting:' . $field->key);
            }
            if ($field->maxLength > 0 && mb_strlen($value) > $field->maxLength) {
                throw new InvalidArgumentException('layout_page_block_setting_too_long:' . $field->key);
            }
            if ($field->type === 'select' && !in_array($value, $field->options, true)) {
                throw new InvalidArgumentException('layout_page_block_invalid_option:' . $field->key);
            }
            if ($field->type === 'url' && $value !== '' && !$this->safeUrl($value)) {
                throw new InvalidArgumentException('layout_page_block_unsafe_url:' . $field->key);
            }
            $settings[$field->key] = $value;
        }

        foreach ($definition->pairedFields as [$left, $right]) {
            if (($settings[$left] === '') !== ($settings[$right] === '')) {
                throw new InvalidArgumentException('layout_page_block_paired_settings:' . $left . ':' . $right);
            }
        }

        return $settings;
    }

    private function safeUrl(string $value): bool
    {
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return true;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['https', 'mailto'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
