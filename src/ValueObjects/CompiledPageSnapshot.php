<?php

declare(strict_types=1);

namespace Larena\Layout\ValueObjects;

use Larena\Core\Support\SchemaName;

final readonly class CompiledPageSnapshot
{
    /** A compiled page and the head that activates it; both are stored (database rows or files). */
    public const SCHEMA = 'larena.layout.compiled_page_snapshot';
    public const ACTIVATION_SCHEMA = 'larena.layout.compiled_page_activation';
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $scopeRef,
        public string $pageId,
        public int $activationRevision,
        public string $snapshotDigest,
        public array $snapshot,
        public string $activatedBy,
    ) {}

    /** @param array<array-key, mixed> $snapshot */
    public static function isSnapshot(array $snapshot): bool
    {
        return SchemaName::is($snapshot, self::SCHEMA, self::SCHEMA_VERSION);
    }

    /** @param array<array-key, mixed> $head */
    public static function isActivation(array $head): bool
    {
        return SchemaName::is($head, self::ACTIVATION_SCHEMA, self::SCHEMA_VERSION);
    }
}
