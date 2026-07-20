<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\TenantProvider;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderWalletController extends Controller
{
    public function index(): Response
    {
        $providers = TenantProvider::all()->map(function (TenantProvider $provider) {
            $wallets = $provider->wallets->map(function (Wallet $wallet) {
                $balance = (float) $wallet->balance / (10 ** $wallet->decimal_places);
                $currency = $wallet->meta['currency'] ?? 'LYD';

                $todayCount = Transaction::where('wallet_id', $wallet->id)
                    ->whereDate('created_at', Carbon::today())
                    ->count();

                return [
                    'id' => $wallet->id,
                    'name' => $wallet->name,
                    'slug' => $wallet->slug,
                    'balance' => $balance,
                    'currency' => $currency,
                    'todayTransactionCount' => $todayCount,
                ];
            })->values()->all();

            return [
                'id' => $provider->id,
                'name' => $provider->airline_name ?? $provider->account_name,
                'code' => $provider->airline_code,
                'type' => $provider->provider_type,
                'isActive' => $provider->is_active,
                'wallets' => $wallets,
            ];
        })->values()->all();

        return Inertia::render('Accounting/Providers/Index', [
            'providers' => $providers,
        ]);
    }

    public function show(Request $request, TenantProvider $provider): Response
    {
        $wallets = $provider->wallets->map(function (Wallet $wallet) use ($request) {
            $balance = (float) $wallet->balance / (10 ** $wallet->decimal_places);
            $currency = $wallet->meta['currency'] ?? 'LYD';

            // Transactions (paginated per wallet)
            $query = Transaction::where('wallet_id', $wallet->id)->latest('created_at');

            if ($request->filled('from')) {
                $query->whereDate('created_at', '>=', $request->string('from'));
            }
            if ($request->filled('to')) {
                $query->whereDate('created_at', '<=', $request->string('to'));
            }

            $transactions = $query->paginate(25)->through(function (Transaction $tx) use ($wallet) {
                $meta = $tx->meta ?? [];

                return [
                    'id' => $tx->id,
                    'uuid' => $tx->uuid,
                    'type' => $tx->type,
                    'amount' => abs((float) $tx->amount / (10 ** $wallet->decimal_places)),
                    'meta' => $meta,
                    'confirmedAt' => $tx->created_at->toIso8601String(),
                    'createdAt' => $tx->created_at->toIso8601String(),
                    'journalEntryReference' => $meta['journal_entry_id'] ?? null,
                    'orderReference' => $meta['order_id'] ?? null,
                ];
            });

            // 30-day balance history
            $balanceHistory = collect(range(29, 0))->map(function (int $daysAgo) use ($wallet) {
                $date = Carbon::now()->subDays($daysAgo)->toDateString();
                $sum = Transaction::where('wallet_id', $wallet->id)
                    ->whereDate('created_at', '<=', $date)
                    ->sum('amount');

                return [
                    'date' => $date,
                    'balance' => round((float) $sum / (10 ** $wallet->decimal_places), 3),
                ];
            })->all();

            return [
                'id' => $wallet->id,
                'name' => $wallet->name,
                'slug' => $wallet->slug,
                'balance' => $balance,
                'currency' => $currency,
                'transactions' => $transactions,
                'balanceHistory' => $balanceHistory,
            ];
        })->values()->all();

        return Inertia::render('Accounting/Providers/Show', [
            'provider' => [
                'id' => $provider->id,
                'name' => $provider->airline_name ?? $provider->account_name,
                'code' => $provider->airline_code,
                'type' => $provider->provider_type,
                'isActive' => $provider->is_active,
            ],
            'wallets' => $wallets,
            'filters' => $request->only(['from', 'to']),
        ]);
    }
}
