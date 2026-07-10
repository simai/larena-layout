<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageBlockDefinition
{
    /**
     * @param list<PageBlockFieldDefinition> $fields
     * @param list<array{0:string,1:string}> $pairedFields
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $smartView,
        public array $fields,
        public array $pairedFields = [],
    ) {
    }

    public function isValid(): bool
    {
        if (!LayoutDescriptor::isStableKey($this->key)
            || trim($this->labelKey) === ''
            || !LayoutDescriptor::isStableKey($this->smartView)
            || $this->fields === []) {
            return false;
        }

        $keys = [];
        foreach ($this->fields as $field) {
            if (!$field->isValid() || in_array($field->key, $keys, true)) {
                return false;
            }
            $keys[] = $field->key;
        }

        foreach ($this->pairedFields as $pair) {
            if (!in_array($pair[0], $keys, true) || !in_array($pair[1], $keys, true)) {
                return false;
            }
        }

        return true;
    }
}
