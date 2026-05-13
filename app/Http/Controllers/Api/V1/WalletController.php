<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Wallet balance for the authenticated user.
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallets = $user->wallets()->get(['id', 'slug', 'balance', 'meta']);

        $balances = $wallets->map(function ($wallet) {
            return [
                'currency' => $wallet->slug,
                'balance' => (float) $wallet->balance,
            ];
        })->values();

        return $this->success($balances);
    }

    /**
     * Wallet transactions (paginated).
     */
    public function transactions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currency' => ['nullable', 'string', 'size:3'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $transactions = WalletTransaction::query()
            ->when(isset($validated['currency']), function ($q) use ($validated) {
                $q->where('meta->currency', strtoupper($validated['currency']));
            })
            ->when(isset($validated['date_from']), function ($q) use ($validated) {
                $q->whereDate('created_at', '>=', $validated['date_from']);
            })
            ->when(isset($validated['date_to']), function ($q) use ($validated) {
                $q->whereDate('created_at', '<=', $validated['date_to']);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->success($transactions);
    }
}
