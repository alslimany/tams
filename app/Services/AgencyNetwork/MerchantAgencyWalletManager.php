<?php

namespace App\Services\AgencyNetwork;

use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\NetworkMembership;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Bavix\Wallet\Models\Transaction;
use Bavix\Wallet\Models\Wallet;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class MerchantAgencyWalletManager
{
    public function walletSlug(int $networkMembershipId, string $currency = 'LYD'): string
    {
        return 'NETWORK_'.$networkMembershipId.'_'.strtoupper($currency);
    }

    public function resolveWalletHolder(User $fallback): User
    {
        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->orderBy('id')
            ->first() ?? $fallback;
    }

    public function getOrCreateWalletForMembership(NetworkMembership $membership, string $currency = 'LYD'): Wallet
    {
        $normalizedCurrency = strtoupper($currency);

        return $membership->getOrCreateCurrencyWallet($normalizedCurrency);
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdraw(User $issuer, string $agencyTenantId, string $currency, float $amount, ?int $networkMembershipId = null): void
    {
        $required = round($amount, 2);

        if ($required <= 0) {
            return;
        }

        $membership = $this->resolveMembership($agencyTenantId, $networkMembershipId);
        $wallet = $this->getOrCreateWalletForMembership($membership, $currency);
        $available = round((float) $wallet->balanceFloat, 2);

        if (! $wallet->canWithdrawFloat($required)) {
            throw new InsufficientWalletBalanceException(strtoupper($currency), $required, $available);
        }
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function assertCanWithdrawForSource(User $issuer, array $providerSource, string $currency, float $amount): void
    {
        $agencyTenantId = data_get($providerSource, 'source_agency_tenant_id');

        if (! is_string($agencyTenantId) || $agencyTenantId === '') {
            return;
        }

        $this->assertCanWithdraw(
            issuer: $issuer,
            agencyTenantId: $agencyTenantId,
            currency: $currency,
            amount: $amount,
            networkMembershipId: is_numeric(data_get($providerSource, 'network_membership_id')) ? (int) data_get($providerSource, 'network_membership_id') : null,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function depositForMembership(NetworkMembership $membership, float $amount, string $currency, array $metadata = []): ?Transaction
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($membership, $amount, $currency, $metadata): Transaction {
            $wallet = $this->getOrCreateWalletForMembership($membership, $currency);

            return $wallet->depositFloat(round($amount, 2), array_merge([
                'type' => 'merchant_agency_wallet_deposit',
                'currency' => strtoupper($currency),
                'source_agency_tenant_id' => $membership->agency_tenant_id,
                'agency_tenant_id' => $membership->agency_tenant_id,
                'merchant_tenant_id' => $membership->merchant_tenant_id,
                'network_membership_id' => $membership->id,
                'actor_tenant_id' => tenant()?->id,
                'description' => 'Agency-funded merchant network wallet deposit.',
            ], $metadata));
        });
    }

    /**
     * @throws InsufficientWalletBalanceException
     */
    public function withdrawForOrderItem(Order $order, OrderItem $item, User $issuer): ?Transaction
    {
        if ($item->wallet_transaction_id !== null) {
            return null;
        }

        $agencyTenantId = data_get($item->item_details, 'source_agency_tenant_id');

        if (! is_string($agencyTenantId) || $agencyTenantId === '') {
            return null;
        }

        $amount = round((float) ($item->total_amount ?? $item->total ?? 0), 2);

        if ($amount <= 0) {
            return null;
        }

        $currency = strtoupper((string) ($item->currency ?? $order->currency ?? 'LYD'));
        $membershipId = is_numeric(data_get($item->item_details, 'network_membership_id')) ? (int) data_get($item->item_details, 'network_membership_id') : null;
        $membership = $this->resolveMembership($agencyTenantId, $membershipId);
        $wallet = $this->getOrCreateWalletForMembership($membership, $currency);
        $available = round((float) $wallet->balanceFloat, 2);

        if (! $wallet->canWithdrawFloat($amount)) {
            throw new InsufficientWalletBalanceException($currency, $amount, $available);
        }

        return DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($wallet, $amount, $order, $item, $agencyTenantId, $currency, $membership, $issuer): Transaction {
            $withdrawal = $wallet->withdrawFloat($amount, [
                'type' => 'merchant_agency_wallet_issuance_payment',
                'currency' => $currency,
                'tenant_id' => tenant()?->id,
                'actor_user_id' => $issuer->id,
                'actor_user_email' => $issuer->email,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'order_number' => $order->number,
                'product_type' => (string) ($item->product_type ?: $item->type),
                'product_subtype' => (string) $item->product_subtype,
                'provider_reference' => (string) ($item->provider_reference ?: $item->ticket_number),
                'financial_source' => data_get($item->item_details, 'financial_source'),
                'provider_selector' => data_get($item->item_details, 'provider_selector'),
                'provider_source_type' => data_get($item->item_details, 'provider_source_type'),
                'source_agency_tenant_id' => $agencyTenantId,
                'agency_tenant_id' => $membership->agency_tenant_id,
                'merchant_tenant_id' => data_get($item->item_details, 'merchant_tenant_id'),
                'network_membership_id' => data_get($item->item_details, 'network_membership_id'),
                'provider_allocation_id' => data_get($item->item_details, 'provider_allocation_id'),
                'source_provider_model' => data_get($item->item_details, 'source_provider_model'),
                'source_provider_id' => data_get($item->item_details, 'source_provider_id'),
                'description' => 'Merchant agency network wallet issuance payment.',
            ]);

            $item->update(['wallet_transaction_id' => $withdrawal->uuid]);

            return $withdrawal;
        });
    }

    protected function resolveMembership(string $agencyTenantId, ?int $networkMembershipId): NetworkMembership
    {
        $query = NetworkMembership::query()
            ->where('agency_tenant_id', $agencyTenantId);

        if ($networkMembershipId !== null) {
            $query->whereKey($networkMembershipId);
        }

        $membership = $query->first();

        if (! $membership instanceof NetworkMembership) {
            throw (new ModelNotFoundException)->setModel(NetworkMembership::class, [$networkMembershipId]);
        }

        return $membership;
    }
}
