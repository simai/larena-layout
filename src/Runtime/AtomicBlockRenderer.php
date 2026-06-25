<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\AtomicBlockPlacement;
use Larena\Layout\Contracts\ContentModel;

final class AtomicBlockRenderer
{
    public function render(AtomicBlockPlacement $placement, ContentModel $content): string
    {
        $value = $this->valueForPlacement($placement, $content);
        $payload = is_scalar($value)
            ? (string) $value
            : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (trim($payload) === '') {
            throw new InvalidArgumentException('layout_atomic_block_empty_payload:' . $placement->instanceId);
        }

        return '<div data-larena-atomic-id="' . $this->escape($placement->instanceId) . '"'
            . ' data-larena-atomic-block="' . $this->escape($placement->blockKey) . '"'
            . ' data-larena-slot="' . $this->escape($placement->slotKey) . '">'
            . $this->escape($payload)
            . '</div>';
    }

    private function valueForPlacement(AtomicBlockPlacement $placement, ContentModel $content): mixed
    {
        if ($placement->dataMode === 'provider') {
            $sourceKey = $placement->sourceKey ?? $placement->blockKey;
            if (!array_key_exists($sourceKey, $content->values)) {
                throw new InvalidArgumentException('layout_atomic_block_unknown_source:' . $sourceKey);
            }

            return $content->values[$sourceKey];
        }

        $runtimeKey = $placement->runtimeKey ?? '';
        if (!array_key_exists($runtimeKey, $content->values)) {
            throw new InvalidArgumentException('layout_atomic_block_unknown_runtime_key:' . $runtimeKey);
        }

        return $content->values[$runtimeKey];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
