<?php

declare(strict_types=1);

namespace Larena\Layout\Exceptions;

use RuntimeException;
use Throwable;

final class LayoutRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = 'The Layout request was rejected.',
        ?Throwable $previous = null,
    )
    {
        parent::__construct($message, 0, $previous);
    }
}
