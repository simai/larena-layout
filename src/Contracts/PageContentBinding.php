<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

final readonly class PageContentBinding
{
    public function __construct(
        public string $bindingId,
        public string $contentType,
        public string $contentRef,
        public string $field,
        public string $valueType,
        public ?int $expectedRevision = null,
    ) {
    }

    public function isValid(): bool
    {
        return LayoutDescriptor::isStableKey($this->bindingId)
            && LayoutDescriptor::isStableKey($this->contentType)
            && trim($this->contentRef) !== ''
            && strlen($this->contentRef) <= 191
            && LayoutDescriptor::isStableKey($this->field)
            && in_array($this->valueType, ['string', 'text', 'integer', 'number', 'boolean', 'date', 'datetime', 'json', 'file_ref'], true)
            && ($this->expectedRevision === null || $this->expectedRevision >= 1);
    }

    /** @return array{binding_id:string,content_type:string,content_ref:string,field:string,value_type:string,expected_revision:?int} */
    public function toArray(): array
    {
        return [
            'binding_id' => $this->bindingId,
            'content_type' => $this->contentType,
            'content_ref' => $this->contentRef,
            'field' => $this->field,
            'value_type' => $this->valueType,
            'expected_revision' => $this->expectedRevision,
        ];
    }
}
