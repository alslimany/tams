<?php

namespace App\Listeners;

use App\Services\Accounting\LedgerPostingService;
use Bavix\Wallet\Internal\Events\TransactionCreatedEventInterface;
use Bavix\Wallet\Internal\Listeners\TransactionBeginningListener;
use Bavix\Wallet\Internal\Listeners\TransactionCommittedListener;
use Bavix\Wallet\Internal\Listeners\TransactionCommittingListener;
use Bavix\Wallet\Internal\Listeners\TransactionRolledBackListener;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostLedgerEntryOnWalletTransaction
{
    /**
     * Defer this listener until after the surrounding DB transaction commits.
     * Without this, calling withdrawFloat() inside a DB::transaction() would
     * cause abivia's JournalEntryController to open a nested transaction on the
     * same connection, resulting in a deadlock/hang on SQLite and MySQL.
     */
    public bool $afterCommit = true;

    public function __construct(
        private readonly LedgerPostingService $ledgerService,
    ) {}

    public function handle(TransactionCreatedEventInterface $event): void
    {
        $transaction = Transaction::find($event->getId());

        if ($transaction === null) {
            return;
        }

        $meta = $transaction->meta ?? [];

        // Only post ledger entries for transactions that carry accounting metadata
        if (empty($meta['ledger_accounts'])) {
            return;
        }

        try {
            // Abivia's JournalEntryController::add() opens its own DB transaction.
            // Bavix listens to TransactionBeginning/Committing/Committed events and
            // uses them to manage its internal wallet state. If those listeners fire
            // during abivia's transaction, bavix re-dispatches the wallet event,
            // causing an infinite loop. We temporarily silence bavix's DB transaction
            // listeners for the duration of the abivia write, then restore them.
            $this->withoutBavixDbListeners(
                fn () => $this->ledgerService->postFromWalletTransaction($transaction)
            );
        } catch (Throwable $e) {
            // Log but do not re-throw — a ledger posting failure must not roll back
            // the wallet transaction itself. Unposted entries can be reconciled later.
            Log::error('LedgerBridge: failed to post journal entry for wallet transaction', [
                'transaction_id' => $transaction->id,
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run $callback with bavix's DB transaction lifecycle listeners temporarily removed.
     *
     * Bavix registers listeners on TransactionBeginning/Committing/Committed/RolledBack
     * to manage its internal regulator state. When abivia opens its own DB transaction
     * inside this listener, those bavix listeners fire and attempt to re-flush the wallet
     * event queue, causing an infinite dispatch loop. Silencing them for the duration of
     * the abivia write is safe because the bavix wallet transaction has already committed
     * before this listener runs (bavix uses push/flush, not synchronous dispatch).
     */
    private function withoutBavixDbListeners(callable $callback): mixed
    {
        Event::forget(TransactionBeginning::class);
        Event::forget(TransactionCommitting::class);
        Event::forget(TransactionCommitted::class);
        Event::forget(TransactionRolledBack::class);

        try {
            return $callback();
        } finally {
            Event::listen(TransactionBeginning::class, TransactionBeginningListener::class);
            Event::listen(TransactionCommitting::class, TransactionCommittingListener::class);
            Event::listen(TransactionCommitted::class, TransactionCommittedListener::class);
            Event::listen(TransactionRolledBack::class, TransactionRolledBackListener::class);
        }
    }
}
