<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageComposition
{
    /** @param list<PageBlockInstance> $blocks */
    public function __construct(public array $blocks, public string $schema = 'larena.layout.page_composition.v1')
    {
    }

    public function isValid(): bool
    {
        if ($this->schema !== 'larena.layout.page_composition.v1' || count($this->blocks) > 30) {
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

    /** @return array{schema:string,blocks:list<array<string,mixed>>} */
    public function toArray(): array
    {
        return ['schema' => $this->schema, 'blocks' => array_map(static fn (PageBlockInstance $block): array => $block->toArray(), $this->blocks)];
    }
}
