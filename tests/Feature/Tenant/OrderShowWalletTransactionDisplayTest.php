<?php

use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\AirlineTransaction;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

test('order show exposes wallet transactions and does not expose legacy airline transactions', function () {
    $tenant = Tenant::create([
        'id' => 'order-wallet-tx-'.Str::random(4),
        'company_name' => 'Order Wallet Transaction Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->depositFloat(500, ['type' => 'seed']);
    $walletTransaction = $wallet->withdrawFloat(150, [
        'type' => 'provider_issuance_cost',
        'provider_type' => 'airline',
        'provider_id' => $provider->id,
    ]);
    $providerVoidTransaction = $wallet->depositFloat(150, [
        'type' => 'provider_void_refund',
        'provider_type' => 'airline',
        'provider_id' => $provider->id,
    ]);

    $legacyAccount = AirlineAccount::query()->create([
        'tenant_provider_id' => $provider->id,
        'currency' => 'LYD',
        'balance' => 0,
    ]);

    $legacyTransaction = AirlineTransaction::query()->create([
        'airline_account_id' => $legacyAccount->id,
        'type' => 'ticket_cost',
        'amount' => -150,
        'balance_after' => -150,
    ]);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-WALLET-TX',
        'status' => 'voided',
        'issued_at' => now(),
        'subtotal' => 150,
        'tax_total' => 0,
        'grand_total' => 150,
        'amount_paid' => 150,
        'currency' => 'LYD',
        'payment_method' => 'own_credentials',
        'payment_reference' => 'WALTX1',
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'ticket',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'WALTX1',
        'item_details' => [
            'airline_code' => 'YI',
            'financial_source' => 'own_credentials',
            'provider_wallet_transaction_id' => $walletTransaction->uuid,
            'provider_wallet_void_transaction_id' => $providerVoidTransaction->uuid,
        ],
        'net_fare' => 150,
        'price' => 150,
        'taxes' => [],
        'total_tax' => 0,
        'total' => 150,
        'total_amount' => 150,
        'currency' => 'LYD',
        'status' => 'voided',
        'wallet_transaction_id' => $walletTransaction->uuid,
        'airline_transaction_id' => $legacyTransaction->id,
        'paid' => 150,
        'remaining' => 0,
    ]);

    $this->actingAs($user);

    $baseUrl = 'http://'.$tenant->domains->first()->domain;

    $response = $this->get($baseUrl.route('orders.show', ['order' => $order], false))
        ->assertOk();

    $transactions = $response->inertiaProps('itemTransactions');

    expect($transactions)->toHaveCount(1)
        ->and($transactions[0])->toHaveKey('wallet_transaction')
        ->and($transactions[0])->toHaveKey('provider_wallet_transaction')
        ->and($transactions[0])->toHaveKey('provider_wallet_void_transaction')
        ->and($transactions[0])->not->toHaveKey('airline_transaction')
        ->and($transactions[0]['wallet_transaction']['uuid'])->toBe($walletTransaction->uuid)
        ->and($transactions[0]['wallet_transaction']['meta']['type'])->toBe('provider_issuance_cost')
        ->and($transactions[0]['provider_wallet_transaction']['uuid'])->toBe($walletTransaction->uuid)
        ->and($transactions[0]['provider_wallet_void_transaction']['uuid'])->toBe($providerVoidTransaction->uuid);

    tenancy()->end();
    $tenant->delete();
});
