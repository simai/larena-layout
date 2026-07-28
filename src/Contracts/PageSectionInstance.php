<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageSectionInstance
{
    /**
     * @param array<string, mixed> $parameters
     * @param list<PageBlockInstance> $blocks
     */
    public function __construct(
        public string $sectionId,
        public string $instanceId,
        public string $regionId,
        public int $sort,
        public bool $enabled,
        public array $parameters,
        public array $blocks,
    ) {
    }

    public function isValid(): bool
    {
        if (!LayoutDescriptor::isStableKey($this->sectionId)
            || preg_match('/^[a-z][a-z0-9_-]{2,80}$/', $this->instanceId) !== 1
            || !LayoutDescriptor::isStableKey($this->regionId)
            || $this->sort < 0
            || count($this->blocks) > 30) {
            return false;
        }

        $ids = [];
        foreach ($this->blocks as $block) {
            if (!$block->isValid() || in_array($block->instanceId, $ids, true)) {
                return false;
            }
            $ids[] = $block->instanceId;
        }

        return true;
    }

    /** @return array{section_id:string,instance_id:string,region_id:string,sort:int,enabled:bool,parameters:array<string,mixed>,blocks:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return [
            'section_id' => $this->sectionId,
            'instance_id' => $this->instanceId,
            'region_id' => $this->regionId,
            'sort' => $this->sort,
            'enabled' => $this->enabled,
            'parameters' => $this->parameters,
            'blocks' => array_map(static fn (PageBlockInstance $block): array => $block->toArray(), $this->blocks),
        ];
    }
}
