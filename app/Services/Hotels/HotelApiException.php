<?php

namespace App\Services\Hotels;

use RuntimeException;

class HotelApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message = '', int $code = 0, protected array $context = [])
    {
        parent::__construct($message, $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
