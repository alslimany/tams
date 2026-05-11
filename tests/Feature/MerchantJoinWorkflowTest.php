<?php

use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\TenantProvider;
use App\Models\User;
use App\Notifications\MerchantNetworkInvitation;
use App\Notifications\MerchantNetworkJoined;
use App\Services\AgencyNetwork\MerchantProviderAllocationResolver;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

function createNetworkTenant(string $prefix, string $companyName): Tenant
{
    $tenant = Tenant::create([
        'id' => $prefix.'-'.Str::random(4),
        'company_name' => $companyName,
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    return $tenant;
}

function tenantUrl(Tenant $tenant, string $routeName, mixed $parameters = []): string
{
    return 'http://'.$tenant->domains()->first()->domain.route($routeName, $parameters, false);
}

test('tenants receive sequential agency numbers', function () {
    $first = createNetworkTenant('agency-number-a', 'Agency Number A');
    $second = createNetworkTenant('agency-number-b', 'Agency Number B');

    expect($first->agency_number)->toStartWith('AG-')
        ->and($second->agency_number)->toStartWith('AG-')
        ->and($first->agency_number)->not->toBe($second->agency_number);
});

test('agency creates invitation with offered provider apis without copying credentials', function () {
    Notification::fake();

    $agency = createNetworkTenant('invite-agency', 'Invite Agency');
    tenancy()->initialize($agency);

    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya Airline',
        'account_name' => 'Default Account',
        'credentials' => ['username' => 'secret-user', 'password' => 'secret-pass'],
        'is_active' => true,
        'domestic_commission_rate' => 5,
        'international_commission_rate' => 7,
    ]);

    $this->actingAs($admin)
        ->post(tenantUrl($agency, 'network.invite'), [
            'merchant_email' => 'merchant@example.test',
            'merchant_contact_name' => 'Merchant Admin',
            'provider_keys' => ['airline:'.$provider->id],
            'provider_terms' => [
                'airline:'.$provider->id => [
                    'domestic_discount_rate' => 3,
                    'international_discount_rate' => 5,
                ],
            ],
        ])
        ->assertRedirect();

    tenancy()->end();

    $membership = NetworkMembership::query()->firstOrFail();
    $allocation = ProviderAllocation::query()->firstOrFail();

    expect($membership->agency_tenant_id)->toBe($agency->id)
        ->and($membership->merchant_tenant_id)->toBeNull()
        ->and($membership->merchant_email)->toBe('merchant@example.test')
        ->and($membership->status)->toBe(NetworkMembership::StatusPending)
        ->and($allocation->is_offered_by_agency)->toBeTrue()
        ->and($allocation->is_enabled_by_merchant)->toBeFalse()
        ->and((float) $allocation->commission_rate)->toBe(5.0)
        ->and((float) data_get($allocation->metadata, 'financial_terms.merchant_rates.domestic_discount_rate'))->toBe(3.0)
        ->and((float) data_get($allocation->metadata, 'financial_terms.merchant_rates.international_discount_rate'))->toBe(5.0)
        ->and((float) data_get($allocation->metadata, 'financial_terms.agency_profit_rates.domestic_discount_rate'))->toBe(2.0)
        ->and((float) data_get($allocation->metadata, 'financial_terms.agency_profit_rates.international_discount_rate'))->toBe(2.0)
        ->and($allocation->metadata)->not->toHaveKey('credentials')
        ->and($allocation->metadata)->not->toHaveKey('password');

    Notification::assertSentOnDemand(
        MerchantNetworkInvitation::class,
        fn (MerchantNetworkInvitation $notification, array $channels, object $notifiable): bool => $notification->membership->is($membership)
            && $notifiable->routes['mail'] === ['merchant@example.test' => 'Merchant Admin']
    );
});

test('agency cannot offer merchant rates above agency provider rates', function () {
    Notification::fake();

    $agency = createNetworkTenant('rate-agency', 'Rate Agency');
    tenancy()->initialize($agency);

    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya Airline',
        'account_name' => 'Default Account',
        'credentials' => ['username' => 'secret-user', 'password' => 'secret-pass'],
        'is_active' => true,
        'domestic_commission_rate' => 5,
        'international_commission_rate' => 7,
    ]);

    $this->actingAs($admin)
        ->post(tenantUrl($agency, 'network.invite'), [
            'merchant_email' => 'merchant@example.test',
            'provider_keys' => ['airline:'.$provider->id],
            'provider_terms' => [
                'airline:'.$provider->id => [
                    'domestic_discount_rate' => 6,
                    'international_discount_rate' => 5,
                ],
            ],
        ])
        ->assertStatus(422);

    tenancy()->end();
});

test('merchant joins invitation and enables only selected offered provider apis', function () {
    Notification::fake();

    $agency = createNetworkTenant('select-agency', 'Select Agency');
    $merchant = createNetworkTenant('select-merchant', 'Select Merchant');

    tenancy()->initialize($agency);
    $agencyAdmin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    tenancy()->end();

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_email' => 'merchant@example.test',
        'status' => NetworkMembership::StatusPending,
        'expires_at' => now()->addDays(14),
        'invited_at' => now(),
    ]);

    $airline = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 10,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => false,
    ]);

    $insurance = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'albaraka',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => 11,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => false,
    ]);

    tenancy()->initialize($merchant);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->actingAs($admin)
        ->post(tenantUrl($merchant, 'network.join'), [
            'invitation_code' => $membership->invitation_code,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(tenantUrl($merchant, 'network.accept', $membership), [
            'allocation_ids' => [$airline->id],
        ])
        ->assertRedirect();

    tenancy()->end();

    expect($membership->fresh())
        ->merchant_tenant_id->toBe($merchant->id)
        ->status->toBe(NetworkMembership::StatusActive)
        ->accepted_at->not->toBeNull()
        ->and($airline->fresh()->is_enabled_by_merchant)->toBeTrue()
        ->and($airline->fresh()->enabled_at)->not->toBeNull()
        ->and($insurance->fresh()->is_enabled_by_merchant)->toBeFalse();

    Notification::assertSentTo(
        $agencyAdmin,
        MerchantNetworkJoined::class,
        fn (MerchantNetworkJoined $notification): bool => $notification->membership->is($membership)
            && $notification->merchant->is($merchant)
    );
});

test('merchant cannot enable duplicate logical provider from another agency network', function () {
    $firstAgency = createNetworkTenant('duplicate-join-a', 'Duplicate Join A');
    $secondAgency = createNetworkTenant('duplicate-join-b', 'Duplicate Join B');
    $merchant = createNetworkTenant('duplicate-join-merchant', 'Duplicate Join Merchant');

    $activeMembership = NetworkMembership::query()->create([
        'agency_tenant_id' => $firstAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $activeMembership->id,
        'agency_tenant_id' => $firstAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 1,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
        'enabled_at' => now(),
    ]);

    $pendingMembership = NetworkMembership::query()->create([
        'agency_tenant_id' => $secondAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusPending,
        'expires_at' => now()->addDays(14),
    ]);

    $duplicateAllocation = ProviderAllocation::query()->create([
        'network_membership_id' => $pendingMembership->id,
        'agency_tenant_id' => $secondAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 2,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => false,
    ]);

    tenancy()->initialize($merchant);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->actingAs($admin)
        ->post(tenantUrl($merchant, 'network.accept', $pendingMembership), [
            'allocation_ids' => [$duplicateAllocation->id],
        ])
        ->assertInvalid(['allocation_ids']);

    tenancy()->end();

    expect($pendingMembership->fresh()->status)->toBe(NetworkMembership::StatusPending)
        ->and($duplicateAllocation->fresh()->is_enabled_by_merchant)->toBeFalse();
});

test('agency can suspend and revoke merchant memberships', function () {
    $agency = createNetworkTenant('control-agency', 'Control Agency');
    $merchant = createNetworkTenant('control-merchant', 'Control Merchant');

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    tenancy()->initialize($agency);
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $this->actingAs($admin)
        ->patch(tenantUrl($agency, 'network.suspend', $membership))
        ->assertRedirect();

    expect($membership->fresh())
        ->status->toBe(NetworkMembership::StatusSuspended)
        ->suspended_at->not->toBeNull();

    $this->actingAs($admin)
        ->patch(tenantUrl($agency, 'network.revoke', $membership))
        ->assertRedirect();

    tenancy()->end();

    expect($membership->fresh())
        ->status->toBe(NetworkMembership::StatusRevoked)
        ->revoked_at->not->toBeNull();
});

test('merchant resolver excludes offered providers not enabled by merchant', function () {
    $agency = createNetworkTenant('resolver-enabled-agency', 'Resolver Enabled Agency');
    $merchant = createNetworkTenant('resolver-enabled-merchant', 'Resolver Enabled Merchant');

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 1,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
        'enabled_at' => now(),
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'hotel',
        'provider_driver' => '3t',
        'provider_identity' => '3t',
        'source_provider_model' => TenantHotelProvider::class,
        'source_provider_id' => 2,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => false,
    ]);

    $allocations = app(MerchantProviderAllocationResolver::class)->forMerchant($merchant->id);

    expect($allocations)->toHaveCount(1)
        ->and($allocations->first()->provider_type)->toBe('airline');
});

test('merchant allocation metadata exposes shared financial terms', function () {
    $agency = createNetworkTenant('terms-agency', 'Terms Agency');
    $merchant = createNetworkTenant('terms-merchant', 'Terms Merchant');

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'hotel',
        'provider_driver' => '3t',
        'provider_identity' => '3t',
        'source_provider_model' => TenantHotelProvider::class,
        'source_provider_id' => 2,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
        'markup_rate' => 1,
        'metadata' => [
            'financial_terms' => [
                'mode' => 'markup',
                'agency_rates' => ['hotel_markup_rate' => 2],
                'merchant_rates' => ['hotel_markup_rate' => 1],
                'agency_profit_rates' => ['hotel_markup_rate' => 1],
            ],
        ],
    ]);

    $metadata = app(MerchantProviderAllocationResolver::class)->metadataForMerchant($merchant->id)->first();

    expect($metadata['markup_rate'])->toBe('1.00')
        ->and(data_get($metadata, 'financial_terms.merchant_rates.hotel_markup_rate'))->toBe(1);
});
