<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WalletLedgerController extends Controller
{
    public function index(): Response
    {
        $wallets = Wallet::all()->map(function (Wallet $wallet) {
            $type = match (true) {
                $wallet->slug === 'operating' => 'operating',
                $wallet->slug === 'merchant' => 'merchant',
                default => 'provider',
            };

            $ledgerAccount = match ($type) {
                'operating' => '1110',
                'merchant' => '1120',
                default => '1210',
            };

            $lastTx = Transaction::where('wallet_id', $wallet->id)
                ->latest('created_at')
                ->first();

            return [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'slug' => $wallet->slug,
                'type' => $type,
                'balance' => (float) $wallet->balance / (10 ** $wallet->decimal_places),
                'currency' => 'LYD',
                'ledgerAccount' => $ledgerAccount,
                'lastActivityAt' => $lastTx?->created_at?->toIso8601String(),
                'transactionCount' => Transaction::where('wallet_id', $wallet->id)->count(),
            ];
        })->sortBy(fn ($w) => match ($w['type']) {
            'operating' => 0,
            'merchant' => 1,
            default => 2,
        })->values()->all();

        return Inertia::render('Accounting/Wallets/Index', [
            'wallets' => $wallets,
        ]);
    }

    public function show(Request $request, Wallet $wallet): Response
    {
        $type = match (true) {
            $wallet->slug === 'operating' => 'operating',
            $wallet->slug === 'merchant' => 'merchant',
            default => 'provider',
        };

        $ledgerAccount = match ($type) {
            'operating' => '1110',
            'merchant' => '1120',
            default => '1210',
        };

        // Transactions (paginated)
        $query = Transaction::where('wallet_id', $wallet->id)
            ->latest('created_at');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->string('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->string('to'));
        }

        $paginated = $query->paginate(25)->through(function (Transaction $tx) use ($wallet) {
            $amount = (float) $tx->amount / (10 ** $wallet->decimal_places);
            $meta = $tx->meta ?? [];

            return [
                'id' => $tx->id,
                'uuid' => $tx->uuid,
                'type' => $tx->type,
                'amount' => abs($amount),
                'meta' => $meta,
                'confirmedAt' => $tx->created_at->toIso8601String(),
                'createdAt' => $tx->created_at->toIso8601String(),
                'journalEntryReference' => $meta['journal_entry_id'] ?? null,
                'orderReference' => $meta['order_id'] ?? null,
            ];
        });

        // Balance history — last 30 days
        $balanceHistory = collect(range(29, 0))->map(function (int $daysAgo) use ($wallet) {
            $date = Carbon::now()->subDays($daysAgo)->toDateString();
            $balance = Transaction::where('wallet_id', $wallet->id)
                ->whereDate('created_at', '<=', $date)
                ->sum('amount');

            return [
                'date' => $date,
                'balance' => round((float) $balance / (10 ** $wallet->decimal_places), 3),
            ];
        })->all();

        return Inertia::render('Accounting/Wallets/Show', [
            'wallet' => [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'slug' => $wallet->slug,
                'balance' => (float) $wallet->balance / (10 ** $wallet->decimal_places),
                'currency' => 'LYD',
                'ledgerAccount' => $ledgerAccount,
            ],
            'transactions' => $paginated,
            'balanceHistory' => $balanceHistory,
            'filters' => $request->only(['type', 'from', 'to']),
        ]);
    }
}
