<?php

namespace App\Exceptions;

use Exception;

class InsufficientCustomerBalanceException extends Exception
{
    public function __construct(float $required = 0.0, float $available = 0.0)
    {
        parent::__construct(
            "Insufficient customer wallet balance. Required: {$required}, available: {$available}."
        );
    }
}
