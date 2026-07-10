<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageBlockInstance
{
    /** @param array<string, string> $settings */
    public function __construct(
        public string $instanceId,
        public string $type,
        public bool $enabled,
        public int $sort,
        public array $settings,
        public string $smartView,
    ) {
    }

    public function isValid(): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{2,80}$/', $this->instanceId) === 1
            && LayoutDescriptor::isStableKey($this->type)
            && LayoutDescriptor::isStableKey($this->smartView)
            && $this->sort >= 0;
    }

    /** @return array{instance_id:string,type:string,enabled:bool,sort:int,settings:array<string,string>,smart_view:string} */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'type' => $this->type,
            'enabled' => $this->enabled,
            'sort' => $this->sort,
            'settings' => $this->settings,
            'smart_view' => $this->smartView,
        ];
    }
}
