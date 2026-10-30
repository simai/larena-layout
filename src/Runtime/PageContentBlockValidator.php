<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use Closure;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Property\Contracts\PropertyContentBlockValidator;

/**
 * The blocks of a Property content@1 value (a site page's `blocks`, owner decision q-4ede0beabe), checked against
 * Layout's block components: a block type is a block component a page assembly may hold
 * (PageDescriptorComponentRegistry, as the host binds it with PageAssemblyDescriptorNormalizer), and its params are
 * checked as that block's props in a page assembly are.
 *
 * - every known type, version BLOCK_VERSION only (`layout_block_version_unknown`); params by the assembly's props rules
 *   (PageAssemblyDescriptorNormalizer::props(): safe keys and values, bounded depth and size), a refusal names the
 *   top-level param (`layout_block_param_invalid:<param>`, or `layout_block_params_invalid` for the whole object);
 * - `larena.list`: what its `storage_list` binding carries in an assembly, as params: `target` (required, a storage
 *   role or schema, StorageListBindingQuery::TARGET_PATTERN) and `query` (optional, StorageListBindingQuery's shape;
 *   `layout_block_param_invalid:target`, `…:query`); its other params (`title`, `title_field`, `summary_field`) are
 *   props, as in an assembly.
 *
 * An unknown type is Property's `property_content_block_unknown:<type>`. Whether a list's fields exist and are public
 * is the storage's answer when the page is saved or shown, as for an assembly (PageBindingValidator).
 */
final readonly class PageContentBlockValidator implements PropertyContentBlockValidator
{
    public const BLOCK_VERSION = PageAssemblyContentBlocks::BLOCK_VERSION;

    public const LIST_TYPE = StorageListPageBlocks::COMPONENT;

    /** @param Closure(): PageAssemblyDescriptorNormalizer $normalizer The host's normalizer, asked on each check. */
    public function __construct(private Closure $normalizer)
    {
    }

    public static function of(PageAssemblyDescriptorNormalizer $normalizer): self
    {
        return new self(static fn (): PageAssemblyDescriptorNormalizer => $normalizer);
    }

    public function knows(string $type): bool
    {
        return ($this->normalizer)()->components()->hasBlock($type);
    }

    public function validateBlock(string $type, int $version, array $params): array
    {
        $normalizer = ($this->normalizer)();
        if (!$normalizer->components()->hasBlock($type)) {
            return ['property_content_block_unknown:' . $type];
        }
        if ($version !== self::BLOCK_VERSION) {
            return ['layout_block_version_unknown'];
        }
        if ($params !== [] && array_is_list($params)) {
            return ['layout_block_params_invalid'];
        }

        $errors = [];
        $props = $params;
        if ($type === self::LIST_TYPE) {
            $props = array_diff_key($params, array_flip(PageAssemblyContentBlocks::LIST_BINDING_PARAMS));
            $target = $params['target'] ?? null;
            if (!is_string($target) || preg_match(StorageListBindingQuery::TARGET_PATTERN, $target) !== 1) {
                $errors[] = 'layout_block_param_invalid:target';
            }
            if (array_key_exists('query', $params)) {
                try {
                    StorageListBindingQuery::normalize($params['query']);
                } catch (LayoutRejected) {
                    $errors[] = 'layout_block_param_invalid:query';
                }
            }
        }
        try {
            $normalizer->props($props, 'params');
        } catch (LayoutRejected $rejected) {
            $errors[] = self::paramError($rejected->reasonCode);
        }

        return $errors;
    }

    /** `…:params.items.0.title` names the param `items`; a refusal without a path names the whole params. */
    private static function paramError(string $reason): string
    {
        $path = str_contains($reason, ':') ? substr($reason, strpos($reason, ':') + 1) : '';
        $segments = explode('.', $path);

        return count($segments) >= 2 && $segments[0] === 'params' && $segments[1] !== ''
            ? 'layout_block_param_invalid:' . $segments[1]
            : 'layout_block_params_invalid';
    }
}
