<?php

namespace App\Services\Orders;

use App\Models\Tenant\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderNumberGenerator
{
    public function generate(): string
    {
        $sequence = $this->nextSequence();

        return strtoupper(Str::random(3))
            .str_pad((string) $sequence, 4, '0', STR_PAD_LEFT)
            .strtoupper(Str::random(2));
    }

    protected function nextSequence(): int
    {
        // Locking avoids duplicate numbers under concurrent issuance requests.
        return DB::transaction(function (): int {
            $latest = Order::query()
                ->lockForUpdate()
                ->latest('created_at')
                ->value('number');

            if (! is_string($latest) || preg_match('/^[A-Z]{3}(\d{4})[A-Z]{2}$/', $latest, $matches) !== 1) {
                return 1;
            }

            return ((int) $matches[1]) + 1;
        }, 1);
    }
}
