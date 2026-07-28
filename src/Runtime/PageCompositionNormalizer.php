<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\PageBlockDefinition;
use Larena\Layout\Contracts\PageBlockFieldDefinition;
use Larena\Layout\Contracts\PageBlockInstance;
use Larena\Layout\Contracts\PageAssetReference;
use Larena\Layout\Contracts\PageComposition;
use Larena\Layout\Contracts\PageContentBinding;
use Larena\Layout\Contracts\PageSectionInstance;

final readonly class PageCompositionNormalizer
{
    public function __construct(private PageBlockCatalog $catalog = new PageBlockCatalog())
    {
    }

    /**
     * Compatibility entry point for the retired v1 flat block list.
     * New callers must use normalizeDocument().
     *
     * @param array<int, mixed> $input
     */
    public function normalize(array $input): PageComposition
    {
        return $this->composition('docara.default', [[
            'section_id' => 'docara.main',
            'instance_id' => 'section_main',
            'region_id' => 'main',
            'sort' => 100,
            'enabled' => true,
            'parameters' => [],
            'blocks' => $input,
        ]], true);
    }

    /** @param array<string, mixed> $document */
    public function normalizeDocument(array $document): PageComposition
    {
        $schema = $document['schema'] ?? null;
        if ($schema === PageComposition::SCHEMA_V1) {
            if (!is_array($document['blocks'] ?? null)) {
                throw new InvalidArgumentException('layout_page_composition_v1_blocks_invalid');
            }
            return $this->normalize($document['blocks']);
        }
        if ($schema !== PageComposition::SCHEMA_V2
            || !is_array($document['sections'] ?? null)) {
            throw new InvalidArgumentException('layout_page_composition_schema_invalid');
        }

        return $this->composition(
            trim((string) ($document['layout_id'] ?? '')),
            $document['sections'],
            false,
        );
    }

    /** @param array<int, mixed> $rawSections */
    private function composition(string $layoutId, array $rawSections, bool $legacyBlocks): PageComposition
    {
        if (!\Larena\Layout\Contracts\LayoutDescriptor::isStableKey($layoutId)) {
            throw new InvalidArgumentException('layout_page_composition_layout_id_invalid');
        }
        if (count($rawSections) > 20) {
            throw new InvalidArgumentException('layout_page_composition_too_many_sections');
        }

        $sections = [];
        $sectionIds = [];
        $blockIds = [];
        foreach ($rawSections as $sectionIndex => $rawSection) {
            if (!is_array($rawSection)) {
                throw new InvalidArgumentException('layout_page_section_invalid:' . $sectionIndex);
            }
            $sectionInstanceId = trim((string) ($rawSection['instance_id'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_-]{2,80}$/', $sectionInstanceId) !== 1
                || in_array($sectionInstanceId, $sectionIds, true)) {
                throw new InvalidArgumentException('layout_page_section_invalid_or_duplicate_instance:' . $sectionIndex);
            }
            $sectionIds[] = $sectionInstanceId;
            $rawBlocks = $rawSection['blocks'] ?? null;
            if (!is_array($rawBlocks)) {
                throw new InvalidArgumentException('layout_page_section_blocks_invalid:' . $sectionIndex);
            }
            $blocks = $this->blocks($rawBlocks, $blockIds, $legacyBlocks);
            $parameters = is_array($rawSection['parameters'] ?? null) ? $rawSection['parameters'] : [];
            $this->assertSafeValue($parameters, 'section.parameters');
            $sections[] = new PageSectionInstance(
                trim((string) ($rawSection['section_id'] ?? '')),
                $sectionInstanceId,
                trim((string) ($rawSection['region_id'] ?? '')),
                max(0, (int) ($rawSection['sort'] ?? (($sectionIndex + 1) * 100))),
                filter_var($rawSection['enabled'] ?? true, FILTER_VALIDATE_BOOL),
                $parameters,
                $blocks,
            );
        }

        usort($sections, static fn (PageSectionInstance $left, PageSectionInstance $right): int => $left->sort <=> $right->sort);
        $composition = new PageComposition($layoutId, $sections);
        if (!$composition->isValid()) {
            throw new InvalidArgumentException('layout_page_composition_invalid');
        }
        return $composition;
    }

    /**
     * @param array<int, mixed> $input
     * @param list<string> $documentBlockIds
     * @return list<PageBlockInstance>
     */
    private function blocks(array $input, array &$documentBlockIds, bool $legacy): array
    {
        if (count($input) > 30) {
            throw new InvalidArgumentException('layout_page_composition_too_many_blocks');
        }

        $blocks = [];
        foreach ($input as $index => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('layout_page_block_invalid:' . $index);
            }
            $instanceId = trim((string) ($raw['instance_id'] ?? ''));
            if (preg_match('/^[a-z][a-z0-9_-]{2,80}$/', $instanceId) !== 1 || in_array($instanceId, $documentBlockIds, true)) {
                throw new InvalidArgumentException('layout_page_block_invalid_or_duplicate_instance:' . $index);
            }
            $documentBlockIds[] = $instanceId;
            $blockId = trim((string) ($raw[$legacy ? 'type' : 'block_id'] ?? ''));
            $definition = $this->catalog->require($blockId);
            $bindings = $legacy ? [] : $this->contentBindings($raw['content_bindings'] ?? []);
            $assets = $legacy
                ? []
                : $this->assetRefs($raw['asset_refs'] ?? []);
            $rawParameters = $raw[$legacy ? 'settings' : 'parameters'] ?? null;
            $settings = $this->settings(
                $definition,
                is_array($rawParameters) ? $rawParameters : [],
                $legacy,
                array_map(static fn (PageContentBinding $binding): string => $binding->bindingId, $bindings),
                array_map(static fn (PageAssetReference $asset): string => $asset->role, $assets),
            );
            if ($legacy) {
                $assets = $this->legacyAssets($definition, $settings);
            }
            $smartView = $legacy ? $definition->smartView : trim((string) ($raw['smart_view'] ?? ''));
            if ($smartView !== $definition->smartView) {
                throw new InvalidArgumentException('layout_page_block_smart_view_mismatch:' . $instanceId);
            }
            $blocks[] = new PageBlockInstance(
                $instanceId,
                $definition->key,
                filter_var($raw['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                max(0, (int) ($raw['sort'] ?? (($index + 1) * 100))),
                $settings,
                $definition->smartView,
                $bindings,
                $assets,
            );
        }

        usort($blocks, static fn (PageBlockInstance $left, PageBlockInstance $right): int => $left->sort <=> $right->sort);
        return $blocks;
    }

    /**
     * @param array<string,mixed> $raw
     * @param list<string> $boundFields
     * @param list<string> $assetRoles
     * @return array<string,mixed>
     */
    private function settings(
        PageBlockDefinition $definition,
        array $raw,
        bool $legacy,
        array $boundFields,
        array $assetRoles,
    ): array
    {
        $allowed = array_map(
            static fn (PageBlockFieldDefinition $field): string => $field->key,
            array_values(array_filter(
                $definition->fields,
                static fn (PageBlockFieldDefinition $field): bool => $legacy || $field->storage === 'parameter',
            )),
        );
        foreach (array_keys($raw) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('layout_page_block_unknown_setting:' . (string) $key);
            }
        }

        $settings = [];
        foreach ($definition->fields as $field) {
            if (!$legacy && $field->storage !== 'parameter') {
                $satisfied = $field->storage === 'content'
                    ? in_array($field->key, $boundFields, true)
                    : in_array($field->key, $assetRoles, true);
                if ($field->required && !$satisfied) {
                    throw new InvalidArgumentException('layout_page_block_required_reference:' . $field->key);
                }
                continue;
            }
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
            $leftPresent = ($settings[$left] ?? '') !== '' || in_array($left, $boundFields, true);
            $rightPresent = ($settings[$right] ?? '') !== '' || in_array($right, $boundFields, true);
            if ($leftPresent !== $rightPresent) {
                throw new InvalidArgumentException('layout_page_block_paired_settings:' . $left . ':' . $right);
            }
        }

        $this->assertSafeValue($settings, 'block.parameters');

        return $settings;
    }

    /** @return list<PageContentBinding> */
    private function contentBindings(mixed $input): array
    {
        if (!is_array($input) || count($input) > 20) {
            throw new InvalidArgumentException('layout_page_block_content_bindings_invalid');
        }
        $bindings = [];
        foreach ($input as $index => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('layout_page_block_content_binding_invalid:' . $index);
            }
            $binding = new PageContentBinding(
                trim((string) ($raw['binding_id'] ?? '')),
                trim((string) ($raw['content_type'] ?? '')),
                trim((string) ($raw['content_ref'] ?? '')),
                trim((string) ($raw['field'] ?? '')),
                trim((string) ($raw['value_type'] ?? '')),
                isset($raw['expected_revision']) ? (int) $raw['expected_revision'] : null,
            );
            if (!$binding->isValid()) {
                throw new InvalidArgumentException('layout_page_block_content_binding_invalid:' . $index);
            }
            $bindings[] = $binding;
        }
        return $bindings;
    }

    /** @return list<PageAssetReference> */
    private function assetRefs(mixed $input): array
    {
        if (!is_array($input) || count($input) > 20) {
            throw new InvalidArgumentException('layout_page_block_asset_refs_invalid');
        }
        $assets = [];
        foreach ($input as $index => $raw) {
            if (!is_array($raw)) {
                throw new InvalidArgumentException('layout_page_block_asset_ref_invalid:' . $index);
            }
            $asset = new PageAssetReference(
                trim((string) ($raw['asset_id'] ?? '')),
                trim((string) ($raw['logical_ref'] ?? '')),
                trim((string) ($raw['role'] ?? '')),
            );
            if (!$asset->isValid()) {
                throw new InvalidArgumentException('layout_page_block_asset_ref_invalid:' . $index);
            }
            $assets[] = $asset;
        }
        return $assets;
    }

    /** @param array<string,mixed> $settings @return list<PageAssetReference> */
    private function legacyAssets(PageBlockDefinition $definition, array $settings): array
    {
        $assets = [];
        foreach ($definition->fields as $field) {
            if ($field->type !== 'file' || ($settings[$field->key] ?? '') === '') {
                continue;
            }
            $assets[] = new PageAssetReference(
                str_replace('_file_ref', '', $field->key),
                (string) $settings[$field->key],
                $field->key,
            );
        }
        return $assets;
    }

    private function assertSafeValue(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $name = strtolower((string) $key);
                if (preg_match('/(^|_)(html|css|javascript|js|php|script|inline_style|password|token|secret|credential|absolute_path|public_asset_url)($|_)/', $name) === 1) {
                    throw new InvalidArgumentException('layout_page_executable_or_secret_key_rejected:' . $path . '.' . $name);
                }
                $this->assertSafeValue($child, $path . '.' . $name);
            }
            return;
        }
        if (!is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('layout_page_parameter_type_invalid:' . $path);
        }
        if (is_string($value)
            && (preg_match('/<\/?[a-z!][^>]*>/i', $value) === 1
                || preg_match('/<\?php|javascript\s*:|data\s*:\s*text\/html/i', $value) === 1)) {
            throw new InvalidArgumentException('layout_page_executable_payload_rejected:' . $path);
        }
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
