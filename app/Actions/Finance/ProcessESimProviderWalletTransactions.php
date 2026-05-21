<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Wallet;
use Illuminate\Support\Facades\DB;

class ProcessESimProviderWalletTransactions
{
    public function __construct(
        protected ProviderSourceResolver $providerSourceResolver,
    ) {}

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdraw(TenantEsimProvider $provider, string $currency, float $amount, ?int $walletId = null): void
    {
        $required = round($amount, 2);

        if ($required <= 0) {
            return;
        }

        $wallet = $walletId !== null ? Wallet::query()->find($walletId) : $provider->getOrCreateCurrencyWallet($currency);

        if (! $wallet instanceof Wallet) {
            $wallet = $provider->getOrCreateCurrencyWallet($currency);
        }

        $available = round((float) $wallet->balanceFloat, 2);

        if (! $wallet->canWithdrawFloat($required)) {
            throw new InsufficientWalletBalanceException(strtoupper($currency), $required, $available);
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Order $order, TenantEsimProvider $provider): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $resolved = $this->resolveProviderAndTenantForItem($item, $provider);
            $resolvedProvider = $resolved['provider'] ?? $provider;
            $tenantId = $resolved['tenant_id'] ?? tenant()?->id;

            if (! $resolvedProvider instanceof TenantEsimProvider) {
                continue;
            }

            $withdrawal = $this->runForProviderTenant($tenantId, fn (): ?Transaction => DB::transaction(function () use ($order, $item, $resolvedProvider): ?Transaction {
                $provider = TenantEsimProvider::query()->find($resolvedProvider->id) ?? $resolvedProvider;
                $currency = strtoupper((string) ($item->currency ?? $order->currency ?? 'USD'));
                $walletId = $provider->getOrCreateCurrencyWallet($currency)->id;

                return $this->executeForItem($order, $item, $provider, $walletId);
            }));

            if ($withdrawal instanceof Transaction && $this->hasSourceProviderSelector($item)) {
                $details = (array) $item->item_details;
                $details['provider_wallet_transaction_id'] = $withdrawal->uuid;
                $details['provider_wallet_withdrawal_amount'] = round(abs((float) $withdrawal->amount) / 100, 2);

                $item->update(['item_details' => $details]);
            }
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function executeForItem(Order $order, OrderItem $item, TenantEsimProvider $provider, ?int $walletId = null): ?Transaction
    {
        if ($this->hasSourceProviderSelector($item) && data_get($item->item_details, 'provider_wallet_transaction_id') !== null) {
            return null;
        }

        if (! $this->hasSourceProviderSelector($item) && $item->wallet_transaction_id !== null) {
            return null;
        }

        $amount = round((float) ($item->net_fare ?? $item->total_amount ?? $item->total ?? 0), 2);

        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper((string) ($item->currency ?? $order->currency ?? 'USD'));
        $this->assertCanWithdraw($provider, $currency, $amount, $walletId);

        $wallet = $walletId !== null ? Wallet::query()->find($walletId) : $provider->getOrCreateCurrencyWallet($currency);

        if (! $wallet instanceof Wallet) {
            $wallet = $provider->getOrCreateCurrencyWallet($currency);
        }

        $withdrawal = $wallet->withdrawFloat($amount, $this->metadataForWithdrawal($order, $item, $provider));

        $details = (array) $item->item_details;
        $details['provider_wallet_transaction_id'] = $withdrawal->uuid;
        $details['provider_wallet_withdrawal_amount'] = round(abs((float) $withdrawal->amount) / 100, 2);

        $updates = ['item_details' => $details];

        if (! $this->hasSourceProviderSelector($item)) {
            $updates['wallet_transaction_id'] = $withdrawal->uuid;
        }

        $item->update($updates);

        return $withdrawal;
    }

    /**
     * @param  array<string, mixed>  $providerSource
     *
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdrawForSource(array $providerSource, TenantEsimProvider $fallbackProvider, string $currency, float $amount): void
    {
        $providerSelector = data_get($providerSource, 'provider_selector');

        if (! is_string($providerSelector) || $providerSelector === '') {
            $this->assertCanWithdraw($fallbackProvider, $currency, $amount);

            return;
        }

        $resolved = $this->providerSourceResolver->resolve($providerSelector);
        $provider = $resolved['provider'] ?? null;

        if (! $provider instanceof TenantEsimProvider) {
            $this->assertCanWithdraw($fallbackProvider, $currency, $amount);

            return;
        }

        $tenantId = is_string($resolved['resolved_tenant_id'] ?? null) ? $resolved['resolved_tenant_id'] : tenant()?->id;

        $this->runForProviderTenant($tenantId, function () use ($provider, $currency, $amount): mixed {
            $providerForTenant = TenantEsimProvider::query()->find($provider->id) ?? $provider;
            $walletId = $providerForTenant->getOrCreateCurrencyWallet($currency)->id;

            return $this->assertCanWithdraw($providerForTenant, $currency, $amount, $walletId);
        });
    }

    /**
     * @return array{provider: ?TenantEsimProvider, tenant_id: string|null}
     */
    protected function resolveProviderAndTenantForItem(OrderItem $item, TenantEsimProvider $fallbackProvider): array
    {
        $providerSelector = data_get($item->item_details, 'provider_selector');

        if (is_string($providerSelector) && $providerSelector !== '') {
            $resolved = $this->providerSourceResolver->resolve($providerSelector);
            $provider = $resolved['provider'] ?? null;

            if ($provider instanceof TenantEsimProvider) {
                return [
                    'provider' => $provider,
                    'tenant_id' => is_string($resolved['resolved_tenant_id'] ?? null) ? $resolved['resolved_tenant_id'] : tenant()?->id,
                ];
            }
        }

        return [
            'provider' => $fallbackProvider,
            'tenant_id' => tenant()?->id,
        ];
    }

    protected function hasSourceProviderSelector(OrderItem $item): bool
    {
        $selector = data_get($item->item_details, 'provider_selector');
        $sourceType = (string) data_get($item->item_details, 'provider_source_type', data_get($item->item_details, 'source_type', ''));

        return is_string($selector)
            && $selector !== ''
            && in_array($sourceType, ['default_agency', 'agency_network'], true);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function runForProviderTenant(?string $tenantId, callable $callback): mixed
    {
        if ($tenantId === null || $tenantId === tenant()?->id) {
            return $callback();
        }

        $currentTenantId = tenant()?->id;
        $tenant = \App\Models\Tenant::query()->find($tenantId);

        if (! $tenant instanceof \App\Models\Tenant) {
            return $callback();
        }

        try {
            return $tenant->run($callback);
        } finally {
            if ($currentTenantId !== null) {
                $previousTenant = \App\Models\Tenant::query()->find($currentTenantId);

                if ($previousTenant instanceof \App\Models\Tenant) {
                    tenancy()->initialize($previousTenant);
                }
            } else {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataForWithdrawal(Order $order, OrderItem $item, TenantEsimProvider $provider): array
    {
        return [
            'type' => 'provider_issuance_cost',
            'provider_type' => 'esim',
            'esim_provider_type' => $provider->provider_type,
            'provider_id' => $provider->id,
            'tenant_id' => tenant()?->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_number' => $order->number,
            'product_type' => 'esim',
            'product_subtype' => (string) $item->product_subtype,
            'provider_reference' => (string) ($item->provider_reference ?: $item->ticket_number),
            'financial_source' => data_get($item->item_details, 'financial_source'),
            'provider_source_type' => data_get($item->item_details, 'provider_source_type'),
            'source_agency_tenant_id' => data_get($item->item_details, 'source_agency_tenant_id'),
            'merchant_tenant_id' => data_get($item->item_details, 'merchant_tenant_id'),
            'network_membership_id' => data_get($item->item_details, 'network_membership_id'),
            'provider_allocation_id' => data_get($item->item_details, 'provider_allocation_id'),
            'source_provider_model' => data_get($item->item_details, 'source_provider_model'),
            'source_provider_id' => data_get($item->item_details, 'source_provider_id'),
        ];
    }
}
