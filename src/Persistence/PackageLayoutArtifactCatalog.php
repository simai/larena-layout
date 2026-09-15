<?php

declare(strict_types=1);

namespace Larena\Layout\Persistence;

use JsonException;
use Larena\Layout\Contracts\LayoutArtifactCatalog;
use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Runtime\LayoutArtifactNormalizer;
use Larena\Layout\ValueObjects\LayoutArtifactRevision;

final class PackageLayoutArtifactCatalog implements LayoutArtifactCatalog
{
    /** @var array<string,array<int,LayoutArtifactRevision>> */
    private array $revisions = [];

    /** @var array<string,int> */
    private array $published = [];

    public function __construct(string $catalogPath, private readonly PageDescriptorAuthorizationPolicy $authorization)
    {
        $this->load($catalogPath);
    }

    public function read(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision
    {
        $this->authorize($scopeRef, $actor);
        $revisions = $this->revisions[$this->key($scopeRef, $artifactId)] ?? [];
        return $revisions === [] ? null : $revisions[max(array_keys($revisions))];
    }

    public function readRevision(string $scopeRef, string $artifactId, int $revision, string $actor): ?LayoutArtifactRevision
    {
        $this->authorize($scopeRef, $actor);
        return $this->revisions[$this->key($scopeRef, $artifactId)][$revision] ?? null;
    }

    public function published(string $scopeRef, string $artifactId, string $actor): ?LayoutArtifactRevision
    {
        $this->authorize($scopeRef, $actor);
        $key = $this->key($scopeRef, $artifactId);
        $revision = $this->published[$key] ?? null;
        return $revision === null ? null : ($this->revisions[$key][$revision] ?? null);
    }

    public function history(string $scopeRef, string $artifactId, string $actor): array
    {
        $this->authorize($scopeRef, $actor);
        $result = array_values($this->revisions[$this->key($scopeRef, $artifactId)] ?? []);
        usort($result, static fn (LayoutArtifactRevision $left, LayoutArtifactRevision $right): int => $right->revision <=> $left->revision);
        return $result;
    }

    public function search(string $scopeRef, string $actor, ?string $kind = null, ?string $parentId = null, ?string $childId = null): array
    {
        $this->authorize($scopeRef, $actor);
        $result = [];
        foreach ($this->revisions as $revisions) {
            foreach ($revisions as $revision) {
                if ($revision->scopeRef !== $scopeRef || ($kind !== null && $revision->kind !== $kind)) {
                    continue;
                }
                if ($parentId !== null && $revision->artifactId !== $parentId) {
                    continue;
                }
                if ($childId !== null && !$this->containsChild($revision, $childId)) {
                    continue;
                }
                $result[] = $revision;
            }
        }
        usort($result, static fn (LayoutArtifactRevision $left, LayoutArtifactRevision $right): int => [$left->artifactId, $left->revision] <=> [$right->artifactId, $right->revision]);
        return $result;
    }

    public function parents(string $scopeRef, string $artifactId, string $actor): array
    {
        $this->authorize($scopeRef, $actor);
        $result = [];
        foreach ($this->revisions as $revisions) {
            foreach ($revisions as $revision) {
                if ($revision->scopeRef !== $scopeRef) {
                    continue;
                }
                foreach ($revision->artifact['placements'] as $placement) {
                    if ($placement['artifact_ref'] === $artifactId) {
                        $result[] = [
                            'parent_artifact_id' => $revision->artifactId,
                            'parent_revision' => $revision->revision,
                            'instance_id' => $placement['instance_id'],
                            'slot' => $placement['slot'],
                            'sort' => $placement['sort'],
                            'enabled' => $placement['enabled'],
                            'child_revision' => $placement['expected_revision'] ?? ($this->published[$this->key($scopeRef, $artifactId)] ?? 0),
                        ];
                    }
                }
            }
        }
        return $result;
    }

    public function children(string $scopeRef, string $artifactId, string $actor): array
    {
        $revision = $this->published($scopeRef, $artifactId, $actor) ?? $this->read($scopeRef, $artifactId, $actor);
        if ($revision === null) {
            return [];
        }
        return array_map(fn (array $placement): array => [
            'child_artifact_id' => $placement['artifact_ref'],
            'child_revision' => $placement['expected_revision'] ?? ($this->published[$this->key($scopeRef, $placement['artifact_ref'])] ?? 0),
            'instance_id' => $placement['instance_id'],
            'slot' => $placement['slot'],
            'sort' => $placement['sort'],
            'enabled' => $placement['enabled'],
        ], $revision->artifact['placements']);
    }

    private function load(string $catalogPath): void
    {
        $catalogReal = realpath($catalogPath);
        if (!is_string($catalogReal) || !is_file($catalogReal) || is_link($catalogPath)) {
            throw new LayoutRejected('layout_package_catalog_invalid');
        }
        try {
            $catalog = json_decode((string) file_get_contents($catalogReal), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new LayoutRejected('layout_package_catalog_invalid');
        }
        if (!is_array($catalog) || array_keys($catalog) !== ['schema', 'owner', 'artifacts']
            || ($catalog['schema'] ?? null) !== 'larena.layout.package_artifact_catalog.v1'
            || !is_string($catalog['owner'] ?? null) || preg_match('/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/D', $catalog['owner']) !== 1
            || !is_array($catalog['artifacts'] ?? null) || !array_is_list($catalog['artifacts'])) {
            throw new LayoutRejected('layout_package_catalog_invalid');
        }
        $root = dirname($catalogReal);
        $normalizer = new LayoutArtifactNormalizer();
        foreach ($catalog['artifacts'] as $entry) {
            if (!is_array($entry) || array_keys($entry) !== ['path', 'revision', 'published']
                || !is_string($entry['path'] ?? null) || preg_match('#^[a-z0-9][a-z0-9._/-]*\.json$#D', $entry['path']) !== 1
                || str_contains($entry['path'], '..') || !is_int($entry['revision'] ?? null) || $entry['revision'] < 1
                || !is_bool($entry['published'] ?? null)) {
                throw new LayoutRejected('layout_package_catalog_entry_invalid');
            }
            $path = $root.'/'.$entry['path'];
            $real = realpath($path);
            if (!is_string($real) || !is_file($real) || is_link($path) || !str_starts_with($real, $root.'/')) {
                throw new LayoutRejected('layout_package_artifact_path_invalid');
            }
            try {
                $decoded = json_decode((string) file_get_contents($real), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new LayoutRejected('layout_package_artifact_json_invalid');
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new LayoutRejected('layout_package_artifact_json_invalid');
            }
            $artifact = $normalizer->normalize($decoded);
            $key = $this->key($artifact['scope_ref'], $artifact['artifact_id']);
            if (isset($this->revisions[$key][$entry['revision']])) {
                throw new LayoutRejected('layout_package_artifact_revision_duplicate');
            }
            $revision = new LayoutArtifactRevision(
                $artifact['artifact_id'], $artifact['scope_ref'], $artifact['kind'], $entry['revision'],
                $artifact, $normalizer->hash($artifact), $entry['published'],
            );
            $this->revisions[$key][$entry['revision']] = $revision;
            if ($entry['published']) {
                if (isset($this->published[$key])) {
                    throw new LayoutRejected('layout_package_artifact_published_duplicate');
                }
                $this->published[$key] = $entry['revision'];
            }
        }
    }

    private function authorize(string $scopeRef, string $actor): void
    {
        $this->authorization->assertAllowed($actor, PageDescriptorAuthorizationPolicy::READ, $scopeRef);
    }

    private function key(string $scopeRef, string $artifactId): string
    {
        return $scopeRef."\0".$artifactId;
    }

    private function containsChild(LayoutArtifactRevision $revision, string $childId): bool
    {
        foreach ($revision->artifact['placements'] as $placement) {
            if ($placement['artifact_ref'] === $childId) {
                return true;
            }
        }
        return false;
    }
}
