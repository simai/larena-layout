<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Exceptions\LayoutRejected;

/** Projects per-region inheritance intent stored in a canonical Layout artifact extension. */
final readonly class ArtifactRegionInheritanceModes
{
    public const EXTENSION = 'larena.layout:region_modes';

    /**
     * @param array<string,mixed> $artifact
     * @param array<string,string> $defaults
     * @param list<string> $registeredRegions
     * @return array<string,array{mode:string,origin:string}>
     */
    public function effective(array $artifact, array $defaults, array $registeredRegions): array
    {
        $this->assertDefaults($defaults, $registeredRegions);
        $extensions = $artifact['extensions'] ?? null;
        if (! is_array($extensions) || ($extensions !== [] && array_is_list($extensions))) {
            throw new LayoutRejected('layout_region_modes_extensions_invalid');
        }
        $overrides = $extensions[self::EXTENSION] ?? [];
        if (! is_array($overrides) || ($overrides !== [] && array_is_list($overrides))) {
            throw new LayoutRejected('layout_region_modes_invalid');
        }
        foreach ($overrides as $region => $mode) {
            if (! is_string($region) || ! in_array($region, $registeredRegions, true)) {
                throw new LayoutRejected('layout_region_modes_region_unknown');
            }
            $this->mode($mode);
        }

        $effective = [];
        foreach ($registeredRegions as $region) {
            $effective[$region] = array_key_exists($region, $overrides)
                ? ['mode' => $overrides[$region], 'origin' => 'artifact']
                : ['mode' => $defaults[$region], 'origin' => 'registration'];
        }

        return $effective;
    }

    /**
     * Apply a local override. A null mode is reset: it removes the override and
     * restores the server-registered default without mutating any parent layer.
     *
     * @param array<string,mixed> $artifact
     * @param list<string> $registeredRegions
     * @return array<string,mixed>
     */
    public function withOverride(array $artifact, string $region, ?string $mode, array $registeredRegions): array
    {
        if (! in_array($region, $registeredRegions, true)) {
            throw new LayoutRejected('layout_region_modes_region_unknown');
        }
        if ($mode !== null) {
            $this->mode($mode);
        }
        $extensions = $artifact['extensions'] ?? null;
        if (! is_array($extensions) || ($extensions !== [] && array_is_list($extensions))) {
            throw new LayoutRejected('layout_region_modes_extensions_invalid');
        }
        $overrides = $extensions[self::EXTENSION] ?? [];
        if (! is_array($overrides) || ($overrides !== [] && array_is_list($overrides))) {
            throw new LayoutRejected('layout_region_modes_invalid');
        }
        if ($mode === null) {
            unset($overrides[$region]);
        } else {
            $overrides[$region] = $mode;
        }
        ksort($overrides, SORT_STRING);
        if ($overrides === []) {
            unset($extensions[self::EXTENSION]);
        } else {
            $extensions[self::EXTENSION] = $overrides;
        }
        ksort($extensions, SORT_STRING);
        $artifact['extensions'] = $extensions;

        return $artifact;
    }

    /** @param array<string,string> $defaults @param list<string> $registeredRegions */
    private function assertDefaults(array $defaults, array $registeredRegions): void
    {
        if (! array_is_list($registeredRegions) || $registeredRegions === [] || count($registeredRegions) !== count(array_unique($registeredRegions))) {
            throw new LayoutRejected('layout_region_modes_registration_invalid');
        }
        $keys = array_keys($defaults);
        sort($keys, SORT_STRING);
        $expected = $registeredRegions;
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new LayoutRejected('layout_region_modes_registration_invalid');
        }
        foreach ($defaults as $mode) {
            $this->mode($mode);
        }
    }

    private function mode(mixed $mode): string
    {
        if (! is_string($mode) || ! in_array($mode, ['inherit', 'replace', 'empty'], true)) {
            throw new LayoutRejected('layout_region_mode_invalid');
        }

        return $mode;
    }
}
