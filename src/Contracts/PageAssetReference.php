<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageAssetReference
{
    public function __construct(
        public string $assetId,
        public string $logicalRef,
        public string $role,
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->assetId)
            && preg_match('/^[a-f0-9-]{20,80}$/i', $this->logicalRef) === 1
            && LayoutDescriptor::isStableKey($this->role);
    }

    /** @return array{asset_id:string,logical_ref:string,role:string} */
    public function toArray(): array
    {
        return [
            'asset_id' => $this->assetId,
            'logical_ref' => $this->logicalRef,
            'role' => $this->role,
        ];
    }
}
