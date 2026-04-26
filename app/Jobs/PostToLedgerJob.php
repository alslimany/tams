<?php

namespace App\Jobs;

use App\Actions\Finance\PostToLedger;
use App\Models\Tenant\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostToLedgerJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public string $orderId, public bool $includeOwnCredentials = true) {}

    public function handle(PostToLedger $postToLedger): void
    {
        $order = Order::query()->find($this->orderId);

        if (! $order instanceof Order) {
            Log::warning('PostToLedgerJob: Order not found', ['order_id' => $this->orderId]);

            return;
        }

        try {
            $postToLedger->execute($order, $this->includeOwnCredentials);
        } catch (Throwable $exception) {
            Log::error('PostToLedgerJob: Failed to post order to ledger', [
                'order_id' => $this->orderId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
