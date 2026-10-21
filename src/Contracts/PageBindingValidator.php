<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

/**
 * The host's check of a binding when a page is saved: what Layout cannot know itself,
 * such as whether a `storage_list` query names only public fields of its storage.
 *
 * Called with every normalized binding of a page before it is written; a binding the
 * host refuses throws LayoutRejected and nothing is written.
 */
interface PageBindingValidator
{
    /** @param array<string, mixed> $binding */
    public function validate(array $binding, string $scopeRef, string $actor): void;
}
