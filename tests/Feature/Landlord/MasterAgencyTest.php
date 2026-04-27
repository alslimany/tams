<?php

use App\Models\AgencyWalletTransaction;
use App\Models\LandlordUser;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $this->actingAs($this->landlord, 'landlord');
});

describe('Tenant Model - Default Agency', function () {
    test('isDefaultAgency returns true when tenant is default agency', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Default Agency',
            'owner_name' => 'Admin',
            'owner_email' => 'admin@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
            'master_commission_rate' => 5.00,
        ]);

        expect($tenant->isDefaultAgency())->toBeTrue();
        expect($tenant->getMasterCommissionRate())->toBe(5.0);
    });

    test('isDefaultAgency returns false when tenant is not default agency', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Regular Agency',
            'owner_name' => 'User',
            'owner_email' => 'user@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        expect($tenant->isDefaultAgency())->toBeFalse();
        expect($tenant->getMasterCommissionRate())->toBe(0.0);
    });

    test('getDefaultAgency returns the active default agency', function () {
        $defaultAgency = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Default Agency',
            'owner_name' => 'Admin',
            'owner_email' => 'admin@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
            'master_commission_rate' => 7.50,
        ]);

        $result = Tenant::getDefaultAgency();

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($defaultAgency->id);
        expect($result->getMasterCommissionRate())->toBe(7.5);
    });

    test('getDefaultAgency returns null when no default agency exists', function () {
        expect(Tenant::getDefaultAgency())->toBeNull();
    });

    test('getDefaultAgency skips suspended default agency', function () {
        Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Suspended Agency',
            'owner_name' => 'Admin',
            'owner_email' => 'admin@agency.com',
            'status' => 'suspended',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
            'master_commission_rate' => 5.00,
        ]);

        expect(Tenant::getDefaultAgency())->toBeNull();
    });
});

describe('AgencyWalletController - Top Up', function () {
    test('landlord can top up agency wallet', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $tenant->run(function (): void {
            User::query()->create([
                'name' => 'Tenant Admin',
                'email' => 'tenant-admin@example.com',
                'password' => Hash::make('secret-password'),
                'role' => 'admin',
                'is_active' => true,
            ]);
        });

        $response = $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'LYD',
            'amount' => 1000.00,
            'description' => 'Initial funding',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('agency_wallet_transactions', [
            'tenant_id' => $tenant->id,
            'type' => 'topup_from_admin',
            'currency' => 'LYD',
            'amount' => 1000.00,
            'balance_after' => 1000.00,
            'admin_id' => $this->landlord->id,
        ]);

        $tenantWalletBalance = $tenant->run(function (): int {
            $walletHolder = User::query()->where('role', 'admin')->firstOrFail();
            $wallet = $walletHolder->getOrCreateCurrencyWallet('LYD');

            return (int) $wallet->balance;
        });

        expect($tenantWalletBalance)->toBe(100000);
    });

    test('top up accumulates balance correctly', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        // First top-up
        $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'LYD',
            'amount' => 500.00,
        ]);

        // Second top-up
        $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'LYD',
            'amount' => 300.00,
        ]);

        $this->assertDatabaseHas('agency_wallet_transactions', [
            'tenant_id' => $tenant->id,
            'currency' => 'LYD',
            'balance_after' => 800.00,
        ]);
    });

    test('top up validates currency', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $response = $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'GBP',
            'amount' => 100.00,
        ]);

        $response->assertSessionHasErrors('currency');
    });

    test('top up validates amount is positive', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $response = $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'LYD',
            'amount' => -50.00,
        ]);

        $response->assertSessionHasErrors('amount');
    });

    test('top up tracks balance per currency independently', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'LYD',
            'amount' => 1000.00,
        ]);

        $this->post(route('landlord.tenants.wallet.topup', $tenant), [
            'currency' => 'USD',
            'amount' => 500.00,
        ]);

        $this->assertDatabaseHas('agency_wallet_transactions', [
            'tenant_id' => $tenant->id,
            'currency' => 'LYD',
            'balance_after' => 1000.00,
        ]);

        $this->assertDatabaseHas('agency_wallet_transactions', [
            'tenant_id' => $tenant->id,
            'currency' => 'USD',
            'balance_after' => 500.00,
        ]);
    });
});

describe('AgencyWalletController - Default Agency', function () {
    test('landlord can set a tenant as default agency', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Master Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $tenant->run(function (): void {
            TenantProvider::query()->create([
                'provider_type' => 'videcom',
                'airline_code' => 'YI',
                'airline_name' => 'Oya',
                'account_name' => 'Default',
                'credentials' => ['base_url' => 'http://example.test', 'currency' => 'LYD'],
                'is_active' => true,
            ]);
        });

        $response = $this->patch(route('landlord.tenants.default-agency', $tenant), [
            'is_default_agency' => true,
            'master_commission_rate' => 5.00,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $tenant->refresh();
        expect($tenant->isDefaultAgency())->toBeTrue();
        expect($tenant->getMasterCommissionRate())->toBe(5.0);
    });

    test('cannot set tenant as default agency without active provider credentials', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'No Provider Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $response = $this->patch(route('landlord.tenants.default-agency', $tenant), [
            'is_default_agency' => true,
            'master_commission_rate' => 5.00,
        ]);

        $response->assertSessionHasErrors('is_default_agency');

        $tenant->refresh();
        expect($tenant->isDefaultAgency())->toBeFalse();
    });

    test('setting a new default agency removes the previous one', function () {
        $firstDefault = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'First Default',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@first.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
            'master_commission_rate' => 3.00,
        ]);

        $newDefault = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'New Default',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@new.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $newDefault->run(function (): void {
            TenantProvider::query()->create([
                'provider_type' => 'videcom',
                'airline_code' => 'YI',
                'airline_name' => 'Oya',
                'account_name' => 'Default',
                'credentials' => ['base_url' => 'http://example.test', 'currency' => 'LYD'],
                'is_active' => true,
            ]);
        });

        $this->patch(route('landlord.tenants.default-agency', $newDefault), [
            'is_default_agency' => true,
            'master_commission_rate' => 7.50,
        ]);

        $firstDefault->refresh();
        $newDefault->refresh();

        expect($firstDefault->isDefaultAgency())->toBeFalse();
        expect($newDefault->isDefaultAgency())->toBeTrue();
        expect($newDefault->getMasterCommissionRate())->toBe(7.5);
    });

    test('landlord can remove default agency designation', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Former Default',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
            'master_commission_rate' => 5.00,
        ]);

        $this->patch(route('landlord.tenants.default-agency', $tenant), [
            'is_default_agency' => false,
        ]);

        $tenant->refresh();
        expect($tenant->isDefaultAgency())->toBeFalse();
        expect($tenant->getMasterCommissionRate())->toBe(0.0);
    });
});

describe('AgencyWalletController - Credentials Permission', function () {
    test('landlord can switch agency to master supply', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'settings' => ['finance' => ['use_own_airline_credentials' => true]],
        ]);

        $response = $this->patch(route('landlord.tenants.credentials-permission', $tenant), [
            'use_own_airline_credentials' => false,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $tenant->refresh();
        expect($tenant->usesOwnAirlineCredentials())->toBeFalse();
    });

    test('landlord can switch agency to own credentials', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'settings' => ['finance' => ['use_own_airline_credentials' => false]],
        ]);

        $this->patch(route('landlord.tenants.credentials-permission', $tenant), [
            'use_own_airline_credentials' => true,
        ]);

        $tenant->refresh();
        expect($tenant->usesOwnAirlineCredentials())->toBeTrue();
    });
});

describe('AgencyWalletTransaction Model', function () {
    test('recordTopUp creates a transaction correctly', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $transaction = AgencyWalletTransaction::recordTopUp(
            tenantId: $tenant->id,
            currency: 'LYD',
            amount: 500.00,
            balanceAfter: 500.00,
            description: 'Test top-up',
            adminId: $this->landlord->id,
        );

        expect($transaction->tenant_id)->toBe($tenant->id);
        expect($transaction->type)->toBe('topup_from_admin');
        expect($transaction->currency)->toBe('LYD');
        expect((float) $transaction->amount)->toBe(500.0);
        expect((float) $transaction->balance_after)->toBe(500.0);
        expect($transaction->admin_id)->toBe($this->landlord->id);
    });

    test('recordTicketDeduction creates a transaction correctly', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Test Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $defaultAgency = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Default Agency',
            'owner_name' => 'Admin',
            'owner_email' => 'admin@default.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
        ]);

        $transaction = AgencyWalletTransaction::recordTicketDeduction(
            tenantId: $tenant->id,
            defaultAgencyTenantId: $defaultAgency->id,
            currency: 'LYD',
            amount: 150.00,
            balanceAfter: 350.00,
            orderId: 'order-uuid-123',
        );

        expect($transaction->tenant_id)->toBe($tenant->id);
        expect($transaction->default_agency_tenant_id)->toBe($defaultAgency->id);
        expect($transaction->type)->toBe('ticket_cost_deduction');
        expect($transaction->reference_type)->toBe('order_id');
        expect($transaction->reference_id)->toBe('order-uuid-123');
    });

    test('recordCommissionPayable creates a transaction correctly', function () {
        $defaultAgency = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Default Agency',
            'owner_name' => 'Admin',
            'owner_email' => 'admin@default.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
        ]);

        $transaction = AgencyWalletTransaction::recordCommissionPayable(
            tenantId: $defaultAgency->id,
            defaultAgencyTenantId: $defaultAgency->id,
            currency: 'LYD',
            amount: 7.50,
            balanceAfter: 107.50,
            orderId: 'order-uuid-456',
        );

        expect($transaction->tenant_id)->toBe($defaultAgency->id);
        expect($transaction->type)->toBe('commission_payable');
        expect($transaction->reference_id)->toBe('order-uuid-456');
    });
});

describe('TenantManagementController - Show includes wallet data', function () {
    test('tenant show page includes wallet balances and transactions', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Wallet Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

        AgencyWalletTransaction::recordTopUp(
            tenantId: $tenant->id,
            currency: 'LYD',
            amount: 1000.00,
            balanceAfter: 1000.00,
            adminId: $this->landlord->id,
        );

        $response = $this->get(route('landlord.tenants.show', $tenant));
        $response->assertSuccessful();

        $response->assertInertia(fn ($page) => $page
            ->where('tenantRecord.wallet_balances.LYD', 1000)
            ->where('tenantRecord.wallet_balances.USD', 0)
            ->where('tenantRecord.wallet_balances.EUR', 0)
            ->has('tenantRecord.recent_wallet_transactions', 1)
        );
    });

    test('tenant show page includes default agency fields', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Master Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
            'master_commission_rate' => 5.00,
        ]);

        $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

        $response = $this->get(route('landlord.tenants.show', $tenant));
        $response->assertSuccessful();

        $response->assertInertia(fn ($page) => $page
            ->where('tenantRecord.is_default_agency', true)
            ->where('tenantRecord.master_commission_rate', 5)
        );
    });
});

describe('TenantManagementController - Index includes default agency badge', function () {
    test('tenant index page includes is_default_agency flag', function () {
        $defaultAgency = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Default Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@default.com',
            'status' => 'active',
            'subscription_status' => 'trial',
            'is_default_agency' => true,
        ]);

        $defaultAgency->domains()->create(['domain' => $defaultAgency->id.'.localhost']);

        $regularAgency = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Regular Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@regular.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $regularAgency->domains()->create(['domain' => $regularAgency->id.'.localhost']);

        $response = $this->get(route('landlord.tenants.index'));
        $response->assertSuccessful();

        $response->assertInertia(fn ($page) => $page
            ->has('tenants', 2)
        );
    });

    test('tenant index page includes agency settings fields', function () {
        $tenant = Tenant::create([
            'id' => 'agency-'.Str::random(4),
            'company_name' => 'Configured Agency',
            'owner_name' => 'Owner',
            'owner_email' => 'owner@agency.com',
            'status' => 'active',
            'subscription_status' => 'trial',
        ]);

        $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

        $tenant->run(function (): void {
            \App\Models\Tenant\AgencySetting::current()->update([
                'can_use_own_airline_credentials' => false,
                'force_use_default_agency' => true,
                'default_agency_tenant_id' => 'default-tenant',
                'master_commission_percent' => 6.5,
            ]);
        });

        $response = $this->get(route('landlord.tenants.index'));
        $response->assertSuccessful();

        $response->assertInertia(fn ($page) => $page
            ->has('tenants', 1)
            ->where('tenants.0.can_use_own_airline_credentials', false)
            ->where('tenants.0.force_use_default_agency', true)
            ->where('tenants.0.master_commission_percent', 6.5)
        );
    });
});
