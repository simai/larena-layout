<?php

declare(strict_types=1);

namespace Larena\Layout\Exceptions;

use RuntimeException;

final class LayoutRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'The Layout request was rejected.',
    )
    {
        parent::__construct($message);
    }
}
