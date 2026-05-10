<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ProcessProviderWalletTransactions
{
    public function __construct(
        protected ProviderSourceResolver $providerSourceResolver,
    ) {}

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdraw(TenantProvider $provider, string $currency, float $amount): void
    {
        $required = round($amount, 2);

        if ($required <= 0) {
            return;
        }

        $wallet = $provider->getOrCreateCurrencyWallet($currency);
        $available = round((float) $wallet->balanceFloat, 2);

        if (! $wallet->canWithdrawFloat($required)) {
            throw new InsufficientWalletBalanceException(strtoupper($currency), $required, $available);
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Order $order): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (! $this->shouldProcessItem($item)) {
                continue;
            }

            $resolved = $this->resolveProviderAndTenantForItem($item);
            $provider = $resolved['provider'] ?? null;
            $tenantId = $resolved['tenant_id'] ?? null;

            if (! $provider instanceof TenantProvider) {
                continue;
            }

            if ($this->hasSourceProviderSelector($item)) {
                $withdrawal = $this->runForProviderTenant($tenantId, fn (): ?Transaction => DB::transaction(
                    fn (): ?Transaction => $this->withdrawForItem($order, $item, $provider)
                ));

                if ($withdrawal instanceof Transaction) {
                    $this->storeWithdrawalMetadata($item, $withdrawal);
                }

                continue;
            }

            $withdrawal = $this->runForProviderTenant($tenantId, fn (): ?Transaction => DB::transaction(
                fn (): ?Transaction => $this->withdrawForItem($order, $item, $provider)
            ));

            if ($withdrawal instanceof Transaction) {
                $this->storeWithdrawalMetadata($item, $withdrawal);
            }
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdrawForItem(OrderItem $item, string $currency, float $amount): void
    {
        $resolved = $this->resolveProviderAndTenantForItem($item);
        $provider = $resolved['provider'] ?? null;
        $tenantId = $resolved['tenant_id'] ?? null;

        if (! $provider instanceof TenantProvider) {
            return;
        }

        $this->runForProviderTenant($tenantId, fn (): mixed => $this->assertCanWithdraw($provider, $currency, $amount));
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdrawForSelector(?string $providerSelector, TenantProvider $fallbackProvider, string $currency, float $amount): void
    {
        if (! is_string($providerSelector) || $providerSelector === '') {
            $this->assertCanWithdraw($fallbackProvider, $currency, $amount);

            return;
        }

        $resolved = $this->providerSourceResolver->resolve($providerSelector);
        $provider = $resolved['provider'] ?? null;

        if (! $provider instanceof TenantProvider) {
            $this->assertCanWithdraw($fallbackProvider, $currency, $amount);

            return;
        }

        $tenantId = is_string($resolved['resolved_tenant_id'] ?? null) ? $resolved['resolved_tenant_id'] : tenant()?->id;

        $this->runForProviderTenant($tenantId, fn (): mixed => $this->assertCanWithdraw($provider, $currency, $amount));
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
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return $callback();
        }

        try {
            return $tenant->run($callback);
        } finally {
            if ($currentTenantId !== null) {
                $previousTenant = Tenant::query()->find($currentTenantId);

                if ($previousTenant instanceof Tenant) {
                    tenancy()->initialize($previousTenant);
                }
            } else {
                tenancy()->end();
            }
        }
    }

    /**
     * @return array{provider: ?TenantProvider, tenant_id: string|null}
     */
    protected function resolveProviderAndTenantForItem(OrderItem $item): array
    {
        $providerSelector = data_get($item->item_details, 'provider_selector');
        if (is_string($providerSelector) && $providerSelector !== '') {
            $resolved = $this->providerSourceResolver->resolve($providerSelector);
            $provider = $resolved['provider'] ?? null;

            if ($provider instanceof TenantProvider) {
                return [
                    'provider' => $provider,
                    'tenant_id' => is_string($resolved['resolved_tenant_id'] ?? null) ? $resolved['resolved_tenant_id'] : tenant()?->id,
                ];
            }
        }

        return [
            'provider' => $this->resolveProviderForItem($item),
            'tenant_id' => tenant()?->id,
        ];
    }

    protected function shouldProcessItem(OrderItem $item): bool
    {
        if ($this->hasSourceProviderSelector($item)) {
            return data_get($item->item_details, 'provider_wallet_transaction_id') === null;
        }

        if ($item->wallet_transaction_id !== null) {
            return false;
        }

        return (string) data_get($item->item_details, 'financial_source', '') === 'own_credentials';
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
     * @return array<string, mixed>
     */
    protected function metadataForWithdrawal(Order $order, OrderItem $item, TenantProvider $provider, string $reference): array
    {
        $passengerName = (string) data_get($item->item_details, 'passenger_name', 'Passenger');

        return [
            'type' => 'provider_issuance_cost',
            'provider_type' => 'airline',
            'provider_id' => $provider->id,
            'airline_code' => $provider->airline_code,
            'tenant_id' => tenant()?->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_type' => (string) ($item->product_type ?: $item->type),
            'provider_reference' => $reference,
            'external_reference' => $reference,
            'financial_source' => data_get($item->item_details, 'financial_source'),
            'provider_selector' => data_get($item->item_details, 'provider_selector'),
            'provider_source_type' => data_get($item->item_details, 'provider_source_type'),
            'source_agency_tenant_id' => data_get($item->item_details, 'source_agency_tenant_id'),
            'merchant_tenant_id' => data_get($item->item_details, 'merchant_tenant_id'),
            'network_membership_id' => data_get($item->item_details, 'network_membership_id'),
            'provider_allocation_id' => data_get($item->item_details, 'provider_allocation_id'),
            'source_provider_model' => data_get($item->item_details, 'source_provider_model'),
            'source_provider_id' => data_get($item->item_details, 'source_provider_id'),
            'description' => "Ticket for {$passengerName}",
        ];
    }

    protected function resolveProviderForItem(OrderItem $item): ?TenantProvider
    {
        $providerId = data_get($item->item_details, 'financial_provider_id');
        if (is_numeric($providerId)) {
            return TenantProvider::query()->whereKey((int) $providerId)->where('is_active', true)->first();
        }

        $airlineCode = (string) data_get($item->item_details, 'airline_code', data_get($item->product_details, 'airline_code', ''));
        if ($airlineCode === '') {
            return null;
        }

        return TenantProvider::query()
            ->where('airline_code', strtoupper($airlineCode))
            ->where('is_active', true)
            ->get()
            ->first(function (TenantProvider $provider) use ($item): bool {
                $providerCurrency = strtoupper((string) data_get($provider->credentials, 'currency', ''));

                return $providerCurrency === '' || $providerCurrency === strtoupper((string) $item->currency);
            });
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function executeForItem(Order $order, OrderItem $item, TenantProvider $provider): ?Transaction
    {
        $usesSelectedSourceProvider = $this->hasSourceProviderSelector($item);

        if (! $usesSelectedSourceProvider && $item->wallet_transaction_id !== null) {
            return null;
        }

        if ($usesSelectedSourceProvider && data_get($item->item_details, 'provider_wallet_transaction_id') !== null) {
            return null;
        }

        $withdrawal = $this->withdrawForItem($order, $item, $provider);

        if (! $withdrawal instanceof Transaction) {
            return null;
        }

        if ($usesSelectedSourceProvider && $item->wallet_transaction_id !== null) {
            $details = (array) $item->item_details;
            $details['provider_wallet_transaction_id'] = $withdrawal->uuid;
            $details['provider_wallet_withdrawal_amount'] = $this->walletTransactionFloatAmount($withdrawal);

            $item->update(['item_details' => $details]);
        } else {
            $this->storeWithdrawalMetadata($item, $withdrawal);
        }

        return $withdrawal;
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    protected function withdrawForItem(Order $order, OrderItem $item, TenantProvider $provider): ?Transaction
    {
        $amount = $this->providerWithdrawalAmount($item);
        if ($amount <= 0) {
            return null;
        }

        $wallet = $provider->getOrCreateCurrencyWallet((string) $item->currency);
        $reference = (string) $item->provider_reference;

        $this->assertCanWithdraw($provider, (string) $item->currency, $amount);

        return $wallet->withdrawFloat($amount, $this->metadataForWithdrawal($order, $item, $provider, $reference));
    }

    protected function providerWithdrawalAmount(OrderItem $item): float
    {
        $explicitAmount = data_get($item->item_details, 'provider_wallet_withdrawal_amount');

        if (is_numeric($explicitAmount)) {
            return round((float) $explicitAmount, 2);
        }

        $providerPayableAmount = data_get($item->item_details, 'provider_payable_amount');

        if (is_numeric($providerPayableAmount)) {
            return round((float) $providerPayableAmount, 2);
        }

        $discountMode = (string) data_get($item->item_details, 'provider_financial_mode', '');
        $netAfterCommission = $item->net_after_commission;

        if ($discountMode === 'discount' && is_numeric($netAfterCommission)) {
            return round((float) $netAfterCommission + (float) ($item->total_tax ?? 0), 2);
        }

        return round((float) ($item->total_amount ?? $item->total ?? 0), 2);
    }

    protected function storeWithdrawalMetadata(OrderItem $item, Transaction $withdrawal): void
    {
        $details = (array) $item->item_details;
        $details['provider_wallet_transaction_id'] = $withdrawal->uuid;
        $details['provider_wallet_withdrawal_amount'] = $this->walletTransactionFloatAmount($withdrawal);

        $item->update([
            'wallet_transaction_id' => $withdrawal->uuid,
            'item_details' => $details,
        ]);
    }

    protected function walletTransactionFloatAmount(Transaction $transaction): float
    {
        return round(abs((float) $transaction->amount) / 100, 2);
    }
}
