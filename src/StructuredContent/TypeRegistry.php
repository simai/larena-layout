<?php

declare(strict_types=1);

namespace Larena\Layout\StructuredContent;

use RuntimeException;

/**
 * The SIMAI Framework's built-in Composition types: their manifests (data and props schemas, slots, presentation,
 * renderer, assets) as the Framework ships them, read from resources/structured-content/built-in-types.json. That
 * file is the Framework's BUILTIN_TYPE_MANIFESTS at the ui-source revision the conformance kit pins
 * (tests/Fixtures/structured-content-conformance/PIN.json).
 */
final class TypeRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $types;

    /** @var array<string, string> an item type and the one parent it stands in */
    private array $itemParents;

    private static ?self $builtIn = null;

    /**
     * @param array<string, array<string, mixed>> $types
     * @param array<string, string> $itemParents
     */
    private function __construct(array $types, array $itemParents)
    {
        $this->types = $types;
        $this->itemParents = $itemParents;
    }

    public static function builtIn(): self
    {
        if (self::$builtIn !== null) {
            return self::$builtIn;
        }
        $path = dirname(__DIR__, 2).'/resources/structured-content/built-in-types.json';
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['types'] ?? null) || ! is_array($data['content_item_parents'] ?? null)) {
            throw new RuntimeException('structured_content_types_unreadable');
        }
        $types = [];
        foreach ($data['types'] as $manifest) {
            if (! is_array($manifest) || ! is_string($manifest['type'] ?? null) || isset($types[$manifest['type']])) {
                throw new RuntimeException('structured_content_types_invalid');
            }
            $types[$manifest['type']] = $manifest;
        }

        return self::$builtIn = new self($types, $data['content_item_parents']);
    }

    /** @return array<string, mixed>|null */
    public function manifest(mixed $type): ?array
    {
        return is_string($type) ? ($this->types[$type] ?? null) : null;
    }

    public function itemParent(mixed $type): ?string
    {
        return is_string($type) ? ($this->itemParents[$type] ?? null) : null;
    }
}
