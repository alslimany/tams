<?php

namespace App\Services\Notifications;

class AdvLyException extends \RuntimeException
{
    public function __construct(
        public readonly string $apiMessage,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("AdvLy API error: {$apiMessage}", $code, $previous);
    }

    public function isNoFeature(): bool
    {
        return $this->apiMessage === 'ERROR_NO_FEATURE';
    }
}
