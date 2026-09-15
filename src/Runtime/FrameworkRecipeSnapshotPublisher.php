<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\CompiledPageSnapshotStore;
use Larena\Layout\ValueObjects\CompiledPageSnapshot;

final readonly class FrameworkRecipeSnapshotPublisher
{
    public function __construct(
        private FrameworkRecipeCompiler $compiler,
        private CompiledPageSnapshotStore $snapshots,
    ) {}

    /** @param array<string, mixed> $request */
    public function publish(
        string $scopeRef,
        string $pageId,
        string $sourceRevision,
        array $request,
        ?int $expectedActivationRevision,
        string $actor,
    ): CompiledPageSnapshot {
        $compiled = $this->compiler->compile($request);

        return $this->snapshots->activate($scopeRef, $pageId, $sourceRevision, $compiled, $expectedActivationRevision, $actor);
    }
}
