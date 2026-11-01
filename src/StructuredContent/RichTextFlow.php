<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

/**
 * Where the html@1 cleaner collects content: a flow (the root, a quote) collects blocks, a list item its own
 * content. Text comes in as leaves into the current run; a block ends the run.
 */
final class RichTextFlow
{
    /** @var list<array<string, mixed>> */
    public array $blocks = [];

    /** @var list<RichTextLeaf> */
    public array $run = [];

    /** @var list<string> */
    private array $types = ['p'];

    /** @var list<int> */
    private array $starts = [0];

    private bool $needBreak = false;

    public function __construct(public readonly string $kind)
    {
    }

    public function inline(RichTextLeaf $item): void
    {
        if ($this->kind === 'item' && $this->needBreak && $this->run !== []) {
            if (! ($item->kind === 'text' && Js::trim($item->value) === '')) {
                $this->run[] = new RichTextLeaf('br', '', false, false, null);
                $this->needBreak = false;
            }
        }
        $this->run[] = $item;
    }

    public function flush(): void
    {
        if ($this->run === []) {
            return;
        }
        $this->blocks[] = $this->kind === 'item'
            ? ['type' => 'inline', 'leaves' => $this->run]
            : ['type' => $this->types[count($this->types) - 1], 'leaves' => $this->run];
        $this->run = [];
    }

    /** A paragraph or heading starts (or a block off the subset): the text so far is one block. */
    public function open(string $type): void
    {
        if ($this->kind === 'item') {
            $this->needBreak = true;

            return;
        }
        $this->flush();
        $this->types[] = $type;
        $this->starts[] = count($this->blocks);
    }

    public function close(bool $keepEmpty = false): void
    {
        if ($this->kind === 'item') {
            $this->needBreak = true;

            return;
        }
        $start = $this->starts[count($this->starts) - 1];
        if ($keepEmpty && $this->run === [] && count($this->blocks) === $start) {
            $this->blocks[] = ['type' => $this->types[count($this->types) - 1], 'leaves' => []];
        }
        $this->flush();
        if (count($this->types) > 1) {
            array_pop($this->types);
            array_pop($this->starts);
        }
    }

    /** @param array<string, mixed> $block */
    public function block(array $block): void
    {
        $this->flush();
        $this->blocks[] = $block;
        $this->needBreak = false;
    }
}
