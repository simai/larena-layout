<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageBlockFieldDefinition
{
    /** @param list<string> $options */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $type,
        public bool $required = false,
        public int $maxLength = 0,
        public array $options = [],
        public string $default = '',
    ) {
    }

    public function isValid(): bool
    {
        if (!LayoutDescriptor::isStableKey($this->key)
            || trim($this->labelKey) === ''
            || !in_array($this->type, ['string', 'text', 'select', 'file', 'url'], true)
            || $this->maxLength < 0) {
            return false;
        }

        if ($this->type === 'select' && ($this->options === [] || !in_array($this->default, $this->options, true))) {
            return false;
        }

        return $this->type === 'select' || $this->options === [];
    }
}
