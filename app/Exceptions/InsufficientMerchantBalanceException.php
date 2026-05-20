<?php

namespace App\Exceptions;

use Exception;

class InsufficientMerchantBalanceException extends Exception
{
    public function __construct(float $required = 0.0, float $available = 0.0)
    {
        parent::__construct(
            "Insufficient merchant wallet balance. Required: {$required}, available: {$available}."
        );
    }
}
