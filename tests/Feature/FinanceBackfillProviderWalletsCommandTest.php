<?php

use App\Models\Tenant;
use App\Models\Tenant\AirlineAccount;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\Tenant\TenantInsuranceProviderAccount;
use App\Models\TenantProvider;
use Bavix\Wallet\Models\Transaction as WalletTransaction;
use Illuminate\Support\Str;

test('finance:backfill-provider-wallets backfills airline legacy balances idempotently', function () {
    $tenant = Tenant::create([
        'id' => 'provider-wallet-air-'.Str::random(4),
        'company_name' => 'Provider Wallet Airline Backfill Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);

    $account = AirlineAccount::query()->create([
        'tenant_provider_id' => $provider->id,
        'currency' => 'LYD',
        'balance' => 250.75,
    ]);

    $this->artisan('finance:backfill-provider-wallets', [
        '--type' => 'airline',
    ])->assertSuccessful();

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(250.75);

    $transaction = WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('type', 'deposit')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and(data_get($transaction->meta, 'type'))->toBe('legacy_balance_backfill')
        ->and(data_get($transaction->meta, 'legacy_table'))->toBe('airline_accounts')
        ->and(data_get($transaction->meta, 'legacy_id'))->toBe((string) $account->id);

    $this->artisan('finance:backfill-provider-wallets', [
        '--type' => 'airline',
    ])->assertSuccessful();

    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(250.75)
        ->and(WalletTransaction::query()->where('wallet_id', $wallet->id)->where('type', 'deposit')->count())->toBe(1)
        ->and(AirlineAccount::query()->whereKey($account->id)->exists())->toBeTrue();

    tenancy()->end();
    $tenant->delete();
});

test('finance:backfill-provider-wallets backfills insurance legacy balances idempotently', function () {
    $tenant = Tenant::create([
        'id' => 'provider-wallet-ins-'.Str::random(4),
        'company_name' => 'Provider Wallet Insurance Backfill Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'credentials' => [],
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 10,
        'commission_orange' => 8,
    ]);

    $account = TenantInsuranceProviderAccount::query()->create([
        'tenant_insurance_provider_id' => $provider->id,
        'currency' => 'LYD',
        'balance' => 430.50,
    ]);

    $this->artisan('finance:backfill-provider-wallets', [
        '--type' => 'insurance',
    ])->assertSuccessful();

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');
    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(430.50);

    $transaction = WalletTransaction::query()
        ->where('wallet_id', $wallet->id)
        ->where('type', 'deposit')
        ->first();

    expect($transaction)->not->toBeNull()
        ->and(data_get($transaction->meta, 'type'))->toBe('legacy_balance_backfill')
        ->and(data_get($transaction->meta, 'legacy_table'))->toBe('tenant_insurance_provider_accounts')
        ->and(data_get($transaction->meta, 'legacy_id'))->toBe((string) $account->id);

    $this->artisan('finance:backfill-provider-wallets', [
        '--type' => 'insurance',
    ])->assertSuccessful();

    $wallet->refresh();

    expect(round((float) $wallet->balanceFloat, 2))->toBe(430.50)
        ->and(WalletTransaction::query()->where('wallet_id', $wallet->id)->where('type', 'deposit')->count())->toBe(1)
        ->and(TenantInsuranceProviderAccount::query()->whereKey($account->id)->exists())->toBeTrue();

    tenancy()->end();
    $tenant->delete();
});

test('finance:backfill-provider-wallets dry run does not write wallet transactions', function () {
    $tenant = Tenant::create([
        'id' => 'provider-wallet-dry-'.Str::random(4),
        'company_name' => 'Provider Wallet Dry Run Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Default',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);

    AirlineAccount::query()->create([
        'tenant_provider_id' => $provider->id,
        'currency' => 'LYD',
        'balance' => 100,
    ]);

    $this->artisan('finance:backfill-provider-wallets', [
        '--type' => 'airline',
        '--dry-run' => true,
    ])->assertSuccessful();

    $wallet = $provider->getOrCreateCurrencyWallet('LYD');

    expect(round((float) $wallet->balanceFloat, 2))->toBe(0.0)
        ->and(WalletTransaction::query()->where('wallet_id', $wallet->id)->exists())->toBeFalse();

    tenancy()->end();
    $tenant->delete();
});
