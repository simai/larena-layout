<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageBlockInstance
{
    /**
     * @param array<string, mixed> $settings
     * @param list<PageContentBinding> $contentBindings
     * @param list<PageAssetReference> $assetRefs
     */
    public function __construct(
        public string $instanceId,
        public string $type,
        public bool $enabled,
        public int $sort,
        public array $settings,
        public string $smartView,
        public array $contentBindings = [],
        public array $assetRefs = [],
    ) {
    }

    public function isValid(): bool
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,80}$/', $this->instanceId) !== 1
            || !LayoutDescriptor::isStableKey($this->type)
            || !LayoutDescriptor::isStableKey($this->smartView)
            || $this->sort < 0) {
            return false;
        }

        $bindingIds = [];
        foreach ($this->contentBindings as $binding) {
            if (!$binding->isValid() || in_array($binding->bindingId, $bindingIds, true)) {
                return false;
            }
            $bindingIds[] = $binding->bindingId;
        }

        $assetIds = [];
        foreach ($this->assetRefs as $asset) {
            if (!$asset->isValid() || in_array($asset->assetId, $assetIds, true)) {
                return false;
            }
            $assetIds[] = $asset->assetId;
        }

        return true;
    }

    /** @return array{block_id:string,instance_id:string,enabled:bool,sort:int,parameters:array<string,mixed>,smart_view:string,content_bindings:list<array<string,mixed>>,asset_refs:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'block_id' => $this->type,
            'instance_id' => $this->instanceId,
            'enabled' => $this->enabled,
            'sort' => $this->sort,
            'parameters' => $this->settings,
            'smart_view' => $this->smartView,
            'content_bindings' => array_map(static fn (PageContentBinding $binding): array => $binding->toArray(), $this->contentBindings),
            'asset_refs' => array_map(static fn (PageAssetReference $asset): array => $asset->toArray(), $this->assetRefs),
        ];
    }
}
