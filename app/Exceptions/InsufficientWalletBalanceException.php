<?php

namespace App\Exceptions;

use Exception;

class InsufficientWalletBalanceException extends Exception
{
    public function __construct(string $currency, float $required, float $available)
    {
        parent::__construct(
            "Insufficient {$currency} wallet balance. Required: {$required}, available: {$available}."
        );
    }
}
