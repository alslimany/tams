<?php

namespace App\Actions\Finance;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ProcessInsuranceProviderWalletTransactions
{
    public function __construct(
        protected ProviderSourceResolver $providerSourceResolver,
    ) {}

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdraw(TenantInsuranceProvider $provider, string $currency, float $amount): void
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
     * @param  array<string, mixed>  $providerSource
     *
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdrawForSource(array $providerSource, TenantInsuranceProvider $fallbackProvider, string $currency, float $amount): void
    {
        $providerSelector = data_get($providerSource, 'provider_selector');

        if (! is_string($providerSelector) || $providerSelector === '') {
            $this->assertCanWithdraw($fallbackProvider, $currency, $amount);

            return;
        }

        $resolved = $this->providerSourceResolver->resolve($providerSelector);
        $provider = $resolved['provider'] ?? null;

        if (! $provider instanceof TenantInsuranceProvider) {
            $this->assertCanWithdraw($fallbackProvider, $currency, $amount);

            return;
        }

        $tenantId = is_string($resolved['resolved_tenant_id'] ?? null) ? $resolved['resolved_tenant_id'] : tenant()?->id;

        $this->runForProviderTenant($tenantId, fn (): mixed => $this->assertCanWithdraw($provider, $currency, $amount));
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function execute(Order $order, TenantInsuranceProvider $provider): void
    {
        $order->loadMissing('items');

        foreach ($order->items as $item) {
            $resolved = $this->resolveProviderAndTenantForItem($item, $provider);
            $resolvedProvider = $resolved['provider'] ?? $provider;
            $tenantId = $resolved['tenant_id'] ?? tenant()?->id;

            if (! $resolvedProvider instanceof TenantInsuranceProvider) {
                continue;
            }

            $withdrawal = $this->runForProviderTenant($tenantId, fn (): ?Transaction => DB::transaction(
                fn (): ?Transaction => $this->executeForItem($order, $item, $resolvedProvider)
            ));

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
    public function executeForItem(Order $order, OrderItem $item, TenantInsuranceProvider $provider): ?Transaction
    {
        if ($this->hasSourceProviderSelector($item) && data_get($item->item_details, 'provider_wallet_transaction_id') !== null) {
            return null;
        }

        if (! $this->hasSourceProviderSelector($item) && $item->wallet_transaction_id !== null) {
            return null;
        }

        $amount = round((float) ($item->total_amount ?? $item->total ?? 0), 2);
        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper((string) ($item->currency ?? $order->currency ?? 'LYD'));
        $this->assertCanWithdraw($provider, $currency, $amount);

        $wallet = $provider->getOrCreateCurrencyWallet($currency);
        $withdrawal = $wallet->withdrawFloat($amount, $this->metadataForWithdrawal($order, $item, $provider));

        if (! $this->hasSourceProviderSelector($item)) {
            $item->update(['wallet_transaction_id' => $withdrawal->uuid]);
        }

        return $withdrawal;
    }

    /**
     * @return array{provider: ?TenantInsuranceProvider, tenant_id: string|null}
     */
    protected function resolveProviderAndTenantForItem(OrderItem $item, TenantInsuranceProvider $fallbackProvider): array
    {
        $providerSelector = data_get($item->item_details, 'provider_selector');

        if (is_string($providerSelector) && $providerSelector !== '') {
            $resolved = $this->providerSourceResolver->resolve($providerSelector);
            $provider = $resolved['provider'] ?? null;

            if ($provider instanceof TenantInsuranceProvider) {
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
    protected function metadataForWithdrawal(Order $order, OrderItem $item, TenantInsuranceProvider $provider): array
    {
        $reference = (string) ($item->provider_reference ?: $item->ticket_number);

        return [
            'type' => 'provider_issuance_cost',
            'provider_type' => 'insurance',
            'insurance_provider_type' => $provider->provider_type,
            'provider_id' => $provider->id,
            'tenant_id' => tenant()?->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_number' => $order->number,
            'product_type' => (string) ($item->product_type ?: 'insurance'),
            'product_subtype' => (string) $item->product_subtype,
            'provider_reference' => $reference,
            'external_reference' => $reference,
            'financial_source' => data_get($item->item_details, 'financial_source'),
            'provider_source_type' => data_get($item->item_details, 'provider_source_type'),
            'source_agency_tenant_id' => data_get($item->item_details, 'source_agency_tenant_id'),
            'merchant_tenant_id' => data_get($item->item_details, 'merchant_tenant_id'),
            'network_membership_id' => data_get($item->item_details, 'network_membership_id'),
            'provider_allocation_id' => data_get($item->item_details, 'provider_allocation_id'),
            'description' => 'Insurance policy issuance deduction.',
        ];
    }
}
