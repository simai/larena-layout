<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/** A leaf of inline content the html@1 cleaner works on: text or a line break, with its marks and link. */
final class RichTextLeaf
{
    public function __construct(
        public string $kind,
        public string $value,
        public bool $strong,
        public bool $em,
        public ?string $href,
    ) {
    }
}
