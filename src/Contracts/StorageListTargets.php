<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

/**
 * What a list block on a site page may list, as the host's storage answers it.
 *
 * Layout owns the `storage_list` binding but knows no storage: the host says where the
 * site's page assemblies live and which storages of the site a list may name, with their
 * public fields only. An editor offers exactly these; the save-time check
 * (PageBindingValidator) stays the final word.
 */
interface StorageListTargets
{
    /** The layout scope that holds the site's page assemblies (`site.{key}`); null when the site has none. */
    public function pageScope(): ?string;

    /**
     * The storages a site page's list may name, each with its public fields: which of them a
     * list may sort on, filter on (equality or range) and search in.
     *
     * @return list<array{target: string, label: string, fields: list<array{key: string, label: string, type: string, sortable: bool, filterable: bool, searchable: bool}>}>
     */
    public function targets(string $actor): array;
}
