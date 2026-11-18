<?php

namespace App\Exceptions;

use RuntimeException;

final class ProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $message,
        public readonly bool $retryable = false,
        public readonly bool $uncertain = false,
        public readonly int $retryAfter = 0,
    ) {
        parent::__construct($message);
    }
}
