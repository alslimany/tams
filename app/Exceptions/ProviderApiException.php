<?php

namespace App\Exceptions;

use Exception;

class ProviderApiException extends Exception
{
    public function __construct(string $provider = '', string $reason = '')
    {
        $message = $provider
            ? "Provider API error [{$provider}]: {$reason}"
            : "Provider API error: {$reason}";

        parent::__construct($message);
    }
}
