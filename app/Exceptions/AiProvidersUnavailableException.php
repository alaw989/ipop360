<?php

namespace App\Exceptions;

/**
 * Every configured AI provider is rate-limited, down, or rejecting our key,
 * so no request was answered. Not the restaurant's fault: callers leave the
 * row for the next pass rather than marking it as tried.
 */
class AiProvidersUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('All AI providers rate-limited or failed');
    }
}
