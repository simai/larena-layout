<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/** What drawing a Document gives: its HTML, the assets the drawn types need, and what drawing found. */
final class RenderedContent
{
    /**
     * @param list<string> $assets
     * @param list<array{code: string, path: string, message: string}> $diagnostics
     */
    public function __construct(
        public readonly string $html,
        public readonly array $assets,
        public readonly array $diagnostics,
    ) {
    }
}
