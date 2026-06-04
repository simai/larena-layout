<?php

declare(strict_types=1);

namespace Larena\Layout\Contracts;

use Larena\Layout\Enums\LayoutProfileCode;

final readonly class LayoutProfile
{
    /**
     * @param list<string> $allowedRegions
     */
    public function __construct(
        public LayoutProfileCode $code,
        public array $allowedRegions,
        public bool $requiresAccessPolicy,
        public bool $requiresVisibilityPolicy,
    ) {
    }

    /**
     * @param list<string> $allowedRegions
     */
    public static function make(LayoutProfileCode $code, array $allowedRegions): self
    {
        return new self($code, $allowedRegions, $code->requiresAccessPolicy(), $code === LayoutProfileCode::PublicPage);
    }

    public function isValid(): bool
    {
        return $this->allowedRegions !== []
            && (!$this->code->requiresAccessPolicy() || $this->requiresAccessPolicy);
    }
}
