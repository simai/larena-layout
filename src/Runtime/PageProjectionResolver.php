<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Larena\Layout\Contracts\PageBindingOwnerResolver;
use Larena\Layout\Contracts\PageDescriptorStore;
use Larena\Layout\Exceptions\LayoutRejected;
use Throwable;

final readonly class PageProjectionResolver
{
    public function __construct(private PageDescriptorStore $store, private PageBindingOwnerResolver $owners)
    {
    }

    /** @return array<string, mixed> */
    public function project(string $scopeRef, string $pageId, string $actor): array
    {
        $revision = $this->store->read($scopeRef, $pageId, $actor);
        if ($revision === null) {
            throw new LayoutRejected('layout_descriptor_missing');
        }
        $descriptor = $revision->descriptor;
        foreach ($descriptor['regions'] as &$region) {
            foreach ($region['sections'] as &$section) {
                foreach ($section['blocks'] as &$block) {
                    foreach ($block['bindings'] as &$binding) {
                        try {
                            $binding['value'] = $this->safeValue($this->owners->resolve($binding, $actor, $scopeRef), 0);
                        } catch (Throwable $exception) {
                            throw new LayoutRejected('layout_binding_unavailable', 'A page binding could not be resolved.', previous: $exception);
                        }
                    }
                    unset($binding);
                }
                unset($block);
            }
            unset($section);
        }
        unset($region);

        return [
            'schema' => 'larena.layout.page_projection',
            'schema_version' => 1,
            'descriptor_revision' => $revision->revision,
            'descriptor_hash' => $revision->semanticHash,
            'page_id' => $revision->pageId,
            'scope_ref' => $revision->scopeRef,
            'layout_id' => $descriptor['layout_id'],
            'regions' => $descriptor['regions'],
        ];
    }

    private function safeValue(mixed $value, int $depth): mixed
    {
        if ($depth > 8 || is_object($value) || is_resource($value)) {
            throw new LayoutRejected('layout_binding_value_unsafe');
        }
        if (is_string($value) && strlen($value) > 262_144) {
            throw new LayoutRejected('layout_binding_value_too_large');
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->safeValue($child, $depth + 1);
            }
        }
        return $value;
    }
}
