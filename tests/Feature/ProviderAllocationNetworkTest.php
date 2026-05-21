<?php

use App\Actions\Finance\ApplyFinancialSourceAndCommission;
use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\TenantProvider;
use App\Models\User;
use App\Services\AgencyNetwork\MerchantProviderAllocationResolver;
use App\Services\AgencyNetwork\ProviderSourceResolver;
use App\Services\AgencyNetwork\ProviderSourceSelector;
use App\Services\Airline\AgencyProviderResolver;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Support\Str;

function createCentralTenant(string $prefix, string $companyName): Tenant
{
    return Tenant::create([
        'id' => $prefix.'-'.Str::random(4),
        'company_name' => $companyName,
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);
}

function createActiveMembership(Tenant $agency, Tenant $merchant): NetworkMembership
{
    return NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusActive,
        'accepted_at' => now(),
    ]);
}

test('network membership is stored centrally with invitation identifiers', function () {
    $agency = createCentralTenant('network-agency', 'Network Agency');
    $merchant = createCentralTenant('network-merchant', 'Network Merchant');

    $membership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusPending,
        'expires_at' => now()->addDays(7),
    ]);

    expect($membership->getConnectionName())->toBe(config('tenancy.database.central_connection'))
        ->and($membership->invitation_token)->not->toBeNull()
        ->and($membership->invitation_code)->not->toBeNull()
        ->and($membership->agency->is($agency))->toBeTrue()
        ->and($membership->merchant->is($merchant))->toBeTrue();
});

test('provider allocation stores central references without copying provider credentials', function () {
    $agency = createCentralTenant('alloc-agency', 'Allocation Agency');
    $merchant = createCentralTenant('alloc-merchant', 'Allocation Merchant');

    tenancy()->initialize($agency);

    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'yi',
        'airline_name' => 'Yemenia',
        'account_name' => 'Agency Main',
        'credentials' => ['username' => 'secret-user', 'password' => 'secret-pass', 'currency' => 'LYD'],
        'is_active' => true,
    ]);

    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);

    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'Airline',
        'provider_driver' => 'Videcom',
        'provider_identity' => 'yi',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
        'metadata' => [
            'display_name' => 'Yemenia',
            'airline_code' => 'YI',
        ],
    ]);

    expect($allocation->getConnectionName())->toBe(config('tenancy.database.central_connection'))
        ->and($allocation->provider_type)->toBe('airline')
        ->and($allocation->provider_driver)->toBe('videcom')
        ->and($allocation->provider_identity)->toBe('YI')
        ->and($allocation->source_provider_model)->toBe(TenantProvider::class)
        ->and($allocation->source_provider_id)->toBe($provider->id)
        ->and($allocation->metadata)->not->toHaveKey('credentials')
        ->and($allocation->metadata)->not->toHaveKey('password');
});

test('merchant cannot have duplicate active allocations for the same provider identity', function () {
    $agency = createCentralTenant('dup-agency-a', 'Duplicate Agency A');
    $otherAgency = createCentralTenant('dup-agency-b', 'Duplicate Agency B');
    $merchant = createCentralTenant('dup-merchant', 'Duplicate Merchant');

    $membership = createActiveMembership($agency, $merchant);
    $otherMembership = createActiveMembership($otherAgency, $merchant);

    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 10,
        'status' => ProviderAllocation::StatusActive,
    ]);

    expect(fn () => ProviderAllocation::query()->create([
        'network_membership_id' => $otherMembership->id,
        'agency_tenant_id' => $otherAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'AIRLINE',
        'provider_driver' => 'VIDECOM',
        'provider_identity' => 'yi',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 20,
        'status' => ProviderAllocation::StatusActive,
    ]))->toThrow(InvalidArgumentException::class, 'Merchant already has an active allocation for this provider identity.');
});

test('provider allocation removal is requested without deleting the record', function () {
    $agency = createCentralTenant('remove-agency', 'Removal Agency');
    $merchant = createCentralTenant('remove-merchant', 'Removal Merchant');
    $membership = createActiveMembership($agency, $merchant);

    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'compulsory',
        'source_provider_model' => App\Models\Tenant\TenantInsuranceProvider::class,
        'source_provider_id' => 7,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $allocation->requestRemoval();

    expect($allocation->fresh())
        ->status->toBe(ProviderAllocation::StatusRemovalRequested)
        ->removal_requested_at->not->toBeNull();

    $this->assertDatabaseHas('provider_allocations', [
        'id' => $allocation->id,
        'status' => ProviderAllocation::StatusRemovalRequested,
    ]);
});

test('merchant allocation resolver returns only active allocations from active memberships', function () {
    $agency = createCentralTenant('resolver-agency', 'Resolver Agency');
    $merchant = createCentralTenant('resolver-merchant', 'Resolver Merchant');
    $activeMembership = createActiveMembership($agency, $merchant);
    $suspendedMembership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusSuspended,
    ]);

    $activeAllocation = ProviderAllocation::query()->create([
        'network_membership_id' => $activeMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 1,
        'status' => ProviderAllocation::StatusActive,
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $activeMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'travel',
        'source_provider_model' => App\Models\Tenant\TenantInsuranceProvider::class,
        'source_provider_id' => 2,
        'status' => ProviderAllocation::StatusRemovalRequested,
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $suspendedMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'compulsory',
        'source_provider_model' => App\Models\Tenant\TenantInsuranceProvider::class,
        'source_provider_id' => 3,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $allocations = app(MerchantProviderAllocationResolver::class)->forMerchant($merchant->id);

    expect($allocations)->toHaveCount(1)
        ->and($allocations->first()->is($activeAllocation))->toBeTrue();
});

test('merchant allocation resolver merges active allocations from multiple agencies when identities differ', function () {
    $firstAgency = createCentralTenant('merge-agency-a', 'Merge Agency A');
    $secondAgency = createCentralTenant('merge-agency-b', 'Merge Agency B');
    $merchant = createCentralTenant('merge-merchant', 'Merge Merchant');
    $firstMembership = createActiveMembership($firstAgency, $merchant);
    $secondMembership = createActiveMembership($secondAgency, $merchant);

    ProviderAllocation::query()->create([
        'network_membership_id' => $firstMembership->id,
        'agency_tenant_id' => $firstAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 1,
        'status' => ProviderAllocation::StatusActive,
    ]);

    ProviderAllocation::query()->create([
        'network_membership_id' => $secondMembership->id,
        'agency_tenant_id' => $secondAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => '8U',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 2,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $metadata = app(MerchantProviderAllocationResolver::class)->metadataForMerchant($merchant->id);

    expect($metadata)->toHaveCount(2)
        ->and($metadata->pluck('source_type')->unique()->values()->all())->toBe(['agency_network'])
        ->and($metadata->pluck('provider_selector')->every(fn (string $selector): bool => str_starts_with($selector, 'agency_network:')))->toBeTrue()
        ->and($metadata->pluck('provider_identity')->sort()->values()->all())->toBe(['8U', 'YI']);
});

test('provider source selector creates stable identifiers without credentials', function () {
    $agency = createCentralTenant('selector-agency', 'Selector Agency');
    $merchant = createCentralTenant('selector-merchant', 'Selector Merchant');
    $membership = createActiveMembership($agency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 99,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $metadata = app(ProviderSourceSelector::class)->agencyNetwork($allocation);

    expect($metadata['provider_selector'])->toBe("agency_network:{$allocation->id}")
        ->and($metadata['source_type'])->toBe('agency_network')
        ->and($metadata['source_provider_id'])->toBe(99)
        ->and($metadata)->not->toHaveKey('credentials');
});

test('provider source resolver resolves own provider selector from current tenant', function () {
    $agency = createCentralTenant('source-own-agency', 'Source Own Agency');

    tenancy()->initialize($agency);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Own Source',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);

    $resolved = app(ProviderSourceResolver::class)->resolve("own:{$provider->id}");
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($provider->id)
        ->and($resolved['source_type'])->toBe('own')
        ->and($resolved['provider_selector'])->toBe("own:{$provider->id}")
        ->and($resolved['resolved_tenant_id'])->toBe($agency->id)
        ->and($resolved['is_using_master_agency'])->toBeFalse()
        ->and($resolved['is_default_agency_deprecated'])->toBeFalse();
});

test('provider source resolver resolves agency network insurance allocation from source tenant', function () {
    $agency = createCentralTenant('ins-source-agency', 'Insurance Source Agency');
    $merchant = createCentralTenant('ins-source-merchant', 'Insurance Source Merchant');

    tenancy()->initialize($agency);
    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Agency Al Baraka',
        'credentials' => ['token' => 'agency-token', 'base_url' => 'https://agency-insurance.test'],
        'is_active' => true,
        'commission_compulsory' => 10,
        'commission_travel' => 8,
        'commission_orange' => 6,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'ALBARAKA',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($merchant);
    $resolved = app(ProviderSourceResolver::class)->resolve("agency_network:{$allocation->id}");
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantInsuranceProvider::class)
        ->and($resolved['provider']->id)->toBe($provider->id)
        ->and($resolved['provider']->credentials['token'])->toBe('agency-token')
        ->and($resolved['source_type'])->toBe('agency_network')
        ->and($resolved['provider_selector'])->toBe("agency_network:{$allocation->id}")
        ->and($resolved['resolved_tenant_id'])->toBe($agency->id)
        ->and($resolved['provider_allocation_id'])->toBe($allocation->id);
});

test('insurance provider manager falls back to enabled agency network insurance provider', function () {
    $agency = createCentralTenant('ins-manager-agency', 'Insurance Manager Agency');
    $merchant = createCentralTenant('ins-manager-merchant', 'Insurance Manager Merchant');

    tenancy()->initialize($agency);
    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Network Al Baraka',
        'credentials' => ['token' => 'network-token'],
        'is_active' => true,
        'commission_compulsory' => 12,
        'commission_travel' => 9,
        'commission_orange' => 7,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'ALBARAKA',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($merchant);
    $resolved = app(InsuranceProviderManager::class)->activeProviderWithSource();
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantInsuranceProvider::class)
        ->and($resolved['provider']->id)->toBe($provider->id)
        ->and($resolved['source']['source_type'])->toBe('agency_network')
        ->and($resolved['source']['provider_selector'])->toBe("agency_network:{$allocation->id}")
        ->and($resolved['source']['provider_allocation_id'])->toBe($allocation->id);
});

test('insurance provider manager excludes disabled and suspended network insurance allocations', function () {
    $agency = createCentralTenant('ins-disabled-agency', 'Insurance Disabled Agency');
    $merchant = createCentralTenant('ins-disabled-merchant', 'Insurance Disabled Merchant');

    tenancy()->initialize($agency);
    $provider = TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Disabled Network Al Baraka',
        'credentials' => ['token' => 'disabled-token'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'ALBARAKA',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
        'is_enabled_by_merchant' => false,
    ]);

    $suspendedMembership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusSuspended,
    ]);
    ProviderAllocation::query()->create([
        'network_membership_id' => $suspendedMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'TRAVEL',
        'source_provider_model' => TenantInsuranceProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($merchant);
    $resolved = app(InsuranceProviderManager::class)->activeProviderWithSource();
    tenancy()->end();

    expect($resolved['provider'])->toBeNull()
        ->and($resolved['source'])->toBeNull();
});

test('provider source resolver resolves deprecated default agency provider selector from source tenant', function () {
    $defaultAgency = createCentralTenant('source-default-agency', 'Source Default Agency');
    $defaultAgency->update(['is_default_agency' => true]);
    $merchant = createCentralTenant('source-default-merchant', 'Source Default Merchant');

    tenancy()->initialize($defaultAgency);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Default Yemenia',
        'account_name' => 'Default Source',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    tenancy()->initialize($merchant);
    $resolved = app(ProviderSourceResolver::class)->resolve("default_agency:{$defaultAgency->id}:{$provider->id}");
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($provider->id)
        ->and($resolved['source_type'])->toBe('default_agency')
        ->and($resolved['provider_selector'])->toBe("default_agency:{$defaultAgency->id}:{$provider->id}")
        ->and($resolved['source_agency_tenant_id'])->toBe($defaultAgency->id)
        ->and($resolved['resolved_tenant_id'])->toBe($defaultAgency->id)
        ->and($resolved['is_using_master_agency'])->toBeTrue()
        ->and($resolved['is_default_agency_deprecated'])->toBeTrue();
});

test('provider source resolver resolves active agency network allocation selector', function () {
    $agency = createCentralTenant('source-network-agency', 'Source Network Agency');
    $merchant = createCentralTenant('source-network-merchant', 'Source Network Merchant');

    tenancy()->initialize($agency);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Network Yemenia',
        'account_name' => 'Network Source',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($merchant);
    $resolved = app(ProviderSourceResolver::class)->resolve("agency_network:{$allocation->id}");
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($provider->id)
        ->and($resolved['source_type'])->toBe('agency_network')
        ->and($resolved['provider_selector'])->toBe("agency_network:{$allocation->id}")
        ->and($resolved['source_agency_tenant_id'])->toBe($agency->id)
        ->and($resolved['merchant_tenant_id'])->toBe($merchant->id)
        ->and($resolved['network_membership_id'])->toBe($membership->id)
        ->and($resolved['provider_allocation_id'])->toBe($allocation->id)
        ->and($resolved['resolved_tenant_id'])->toBe($agency->id)
        ->and($resolved['is_using_master_agency'])->toBeFalse()
        ->and($resolved['is_default_agency_deprecated'])->toBeFalse();
});

test('provider source resolver safely rejects invalid or inactive selectors', function () {
    $agency = createCentralTenant('source-invalid-agency', 'Source Invalid Agency');
    $merchant = createCentralTenant('source-invalid-merchant', 'Source Invalid Merchant');
    $activeMembership = createActiveMembership($agency, $merchant);
    $suspendedMembership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusSuspended,
    ]);

    tenancy()->initialize($agency);
    $inactiveProvider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Inactive Yemenia',
        'account_name' => 'Inactive Source',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => false,
    ]);
    tenancy()->end();

    $inactiveAllocation = ProviderAllocation::query()->create([
        'network_membership_id' => $activeMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $inactiveProvider->id,
        'status' => ProviderAllocation::StatusRemovalRequested,
    ]);
    $suspendedMembershipAllocation = ProviderAllocation::query()->create([
        'network_membership_id' => $suspendedMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => '8U',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $inactiveProvider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $resolver = app(ProviderSourceResolver::class);

    expect($resolver->resolve('unknown:1')['provider'])->toBeNull()
        ->and($resolver->resolve('own:not-a-number')['provider'])->toBeNull()
        ->and($resolver->resolve("default_agency:{$agency->id}:{$inactiveProvider->id}")['provider'])->toBeNull()
        ->and($resolver->resolve("agency_network:{$inactiveAllocation->id}")['provider'])->toBeNull()
        ->and($resolver->resolve("agency_network:{$suspendedMembershipAllocation->id}")['provider'])->toBeNull();
});

test('merchant allocation resolver can find a specific logical provider allocation', function () {
    $agency = createCentralTenant('specific-agency', 'Specific Agency');
    $merchant = createCentralTenant('specific-merchant', 'Specific Merchant');
    $membership = createActiveMembership($agency, $merchant);

    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 44,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $resolved = app(MerchantProviderAllocationResolver::class)
        ->forMerchantProvider($merchant->id, 'AIRLINE', 'VIDECOM', 'yi');

    expect($resolved?->is($allocation))->toBeTrue();
});

test('agency provider resolver resolves active airline network allocation from source agency tenant', function () {
    $agency = createCentralTenant('resolve-net-agency', 'Resolve Network Agency');
    $merchant = createCentralTenant('resolve-net-merchant', 'Resolve Network Merchant');

    tenancy()->initialize($agency);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia',
        'account_name' => 'Agency Source',
        'credentials' => ['username' => 'agency-secret', 'password' => 'hidden'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $resolved = app(AgencyProviderResolver::class)->resolveNetworkProviderAllocation($allocation);

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($provider->id)
        ->and($resolved['source_type'])->toBe('agency_network')
        ->and($resolved['is_using_master_agency'])->toBeFalse()
        ->and($resolved['resolved_tenant_id'])->toBe($agency->id)
        ->and($resolved['source_agency_tenant_id'])->toBe($agency->id)
        ->and($resolved['merchant_tenant_id'])->toBe($merchant->id)
        ->and($resolved['network_membership_id'])->toBe($membership->id)
        ->and($resolved['provider_allocation_id'])->toBe($allocation->id)
        ->and($allocation->metadata ?? [])->not->toHaveKey('credentials');
});

test('merchant active providers include enabled agency network airline providers', function () {
    $agency = createCentralTenant('search-net-agency', 'Search Network Agency');
    $merchant = createCentralTenant('search-net-merchant', 'Search Network Merchant');

    tenancy()->initialize($agency);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Network Yemenia',
        'account_name' => 'Agency Source',
        'credentials' => ['username' => 'agency-secret', 'password' => 'hidden'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => true,
    ]);

    tenancy()->initialize($merchant);
    AgencySetting::current()->update([
        'force_use_default_agency' => false,
        'can_use_own_airline_credentials' => true,
    ]);

    $providers = app(AgencyProviderResolver::class)->getAllActiveProviders();
    tenancy()->end();

    expect($providers)->toHaveCount(1)
        ->and($providers->first())->toBeInstanceOf(TenantProvider::class)
        ->and($providers->first()->airline_code)->toBe('YI')
        ->and(data_get($providers->first(), 'provider_source_metadata.source_type'))->toBe('agency_network')
        ->and(data_get($providers->first(), 'provider_source_metadata.provider_selector'))->toBe("agency_network:{$allocation->id}")
        ->and(data_get($providers->first(), 'provider_source_metadata.provider_allocation_id'))->toBe($allocation->id);
});

test('merchant active providers exclude disabled or suspended network allocations', function () {
    $agency = createCentralTenant('exclude-net-agency', 'Exclude Network Agency');
    $merchant = createCentralTenant('exclude-net-merchant', 'Exclude Network Merchant');

    tenancy()->initialize($agency);
    $provider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Network Yemenia',
        'account_name' => 'Agency Source',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($agency, $merchant);
    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $provider->id,
        'status' => ProviderAllocation::StatusActive,
        'is_offered_by_agency' => true,
        'is_enabled_by_merchant' => false,
    ]);

    tenancy()->initialize($merchant);
    $providers = app(AgencyProviderResolver::class)->getAllActiveProviders();
    tenancy()->end();

    expect($providers)->toBeEmpty();
});

test('agency provider resolver ignores inactive network allocation or membership', function () {
    $agency = createCentralTenant('inactive-net-agency', 'Inactive Network Agency');
    $merchant = createCentralTenant('inactive-net-merchant', 'Inactive Network Merchant');
    $activeMembership = createActiveMembership($agency, $merchant);
    $suspendedMembership = NetworkMembership::query()->create([
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'status' => NetworkMembership::StatusSuspended,
    ]);

    $inactiveAllocation = ProviderAllocation::query()->create([
        'network_membership_id' => $activeMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 1,
        'status' => ProviderAllocation::StatusRemovalRequested,
    ]);

    $inactiveMembershipAllocation = ProviderAllocation::query()->create([
        'network_membership_id' => $suspendedMembership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => '8U',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => 2,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $resolver = app(AgencyProviderResolver::class);

    expect($resolver->resolveNetworkProviderAllocation($inactiveAllocation)['provider'])->toBeNull()
        ->and($resolver->resolveNetworkProviderAllocation($inactiveMembershipAllocation)['provider'])->toBeNull();
});

test('agency provider resolver ignores non airline provider allocation models', function () {
    $agency = createCentralTenant('non-air-agency', 'Non Airline Agency');
    $merchant = createCentralTenant('non-air-merchant', 'Non Airline Merchant');
    $membership = createActiveMembership($agency, $merchant);

    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $agency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'insurance',
        'provider_driver' => 'albaraka',
        'provider_identity' => 'travel',
        'source_provider_model' => App\Models\Tenant\TenantInsuranceProvider::class,
        'source_provider_id' => 1,
        'status' => ProviderAllocation::StatusActive,
    ]);

    $resolved = app(AgencyProviderResolver::class)->resolveNetworkProviderAllocation($allocation);

    expect($resolved['provider'])->toBeNull()
        ->and($resolved['source_type'])->toBe('agency_network')
        ->and($resolved['provider_allocation_id'])->toBe($allocation->id);
});

test('forced tenant resolves agency network allocation before deprecated default agency provider', function () {
    $defaultAgency = createCentralTenant('default-net-first', 'Deprecated Default Agency');
    $defaultAgency->update(['is_default_agency' => true]);
    $networkAgency = createCentralTenant('network-first-agency', 'Network First Agency');
    $merchant = createCentralTenant('network-first-merchant', 'Network First Merchant');

    tenancy()->initialize($defaultAgency);
    TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Default Yemenia',
        'account_name' => 'Default',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    tenancy()->initialize($networkAgency);
    $networkProvider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Network Yemenia',
        'account_name' => 'Network',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($networkAgency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $networkAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $networkProvider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($merchant);
    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'can_use_own_airline_credentials' => false,
        'default_agency_tenant_id' => $defaultAgency->id,
    ]);

    $resolved = app(AgencyProviderResolver::class)->resolve('YI');
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($networkProvider->id)
        ->and($resolved['source_type'])->toBe('agency_network')
        ->and($resolved['provider_selector'])->toBe("agency_network:{$allocation->id}")
        ->and($resolved['resolved_tenant_id'])->toBe($networkAgency->id)
        ->and($resolved['provider_allocation_id'])->toBe($allocation->id)
        ->and($resolved['is_using_master_agency'])->toBeFalse()
        ->and($resolved['is_default_agency_deprecated'])->toBeFalse();
});

test('forced tenant falls back to deprecated default agency provider when no network allocation exists', function () {
    $defaultAgency = createCentralTenant('deprecated-default', 'Deprecated Default Agency Fallback');
    $defaultAgency->update(['is_default_agency' => true]);
    $merchant = createCentralTenant('deprecated-merchant', 'Deprecated Merchant');

    tenancy()->initialize($defaultAgency);
    $defaultProvider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Default Yemenia',
        'account_name' => 'Default',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    tenancy()->initialize($merchant);
    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'can_use_own_airline_credentials' => false,
        'default_agency_tenant_id' => $defaultAgency->id,
    ]);

    $resolved = app(AgencyProviderResolver::class)->resolve('YI');
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($defaultProvider->id)
        ->and($resolved['source_type'])->toBe('default_agency')
        ->and($resolved['provider_selector'])->toBe("default_agency:{$defaultAgency->id}:{$defaultProvider->id}")
        ->and($resolved['is_using_master_agency'])->toBeTrue()
        ->and($resolved['is_default_agency_deprecated'])->toBeTrue()
        ->and($resolved['resolved_tenant_id'])->toBe($defaultAgency->id);
});

test('tenant with own provider capability still resolves own provider before network allocation', function () {
    $networkAgency = createCentralTenant('own-before-network-agency', 'Own Before Network Agency');
    $agency = createCentralTenant('own-before-network-tenant', 'Own Before Network Tenant');

    tenancy()->initialize($networkAgency);
    $networkProvider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Network Yemenia',
        'account_name' => 'Network',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($networkAgency, $agency);
    ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $networkAgency->id,
        'merchant_tenant_id' => $agency->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $networkProvider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($agency);
    AgencySetting::current()->update([
        'force_use_default_agency' => false,
        'can_use_own_airline_credentials' => true,
    ]);
    $ownProvider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Own Yemenia',
        'account_name' => 'Own',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);

    $resolved = app(AgencyProviderResolver::class)->resolve('YI');
    tenancy()->end();

    expect($resolved['provider'])->toBeInstanceOf(TenantProvider::class)
        ->and($resolved['provider']->id)->toBe($ownProvider->id)
        ->and($resolved['source_type'])->toBe('own')
        ->and($resolved['provider_selector'])->toBe("own:{$ownProvider->id}")
        ->and($resolved['is_using_master_agency'])->toBeFalse()
        ->and($resolved['is_default_agency_deprecated'])->toBeFalse()
        ->and($resolved['provider_allocation_id'])->toBeNull();
});

test('financial source application stores network resolver metadata without changing financial source type', function () {
    $networkAgency = createCentralTenant('metadata-network-agency', 'Metadata Network Agency');
    $merchant = createCentralTenant('metadata-merchant', 'Metadata Merchant');

    tenancy()->initialize($networkAgency);
    $networkProvider = TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Network Yemenia',
        'account_name' => 'Network',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    $membership = createActiveMembership($networkAgency, $merchant);
    $allocation = ProviderAllocation::query()->create([
        'network_membership_id' => $membership->id,
        'agency_tenant_id' => $networkAgency->id,
        'merchant_tenant_id' => $merchant->id,
        'provider_type' => 'airline',
        'provider_driver' => 'videcom',
        'provider_identity' => 'YI',
        'source_provider_model' => TenantProvider::class,
        'source_provider_id' => $networkProvider->id,
        'status' => ProviderAllocation::StatusActive,
    ]);

    tenancy()->initialize($merchant);
    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'can_use_own_airline_credentials' => false,
    ]);

    $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-NET-META',
        'status' => 'confirmed',
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'provider' => 'videcom',
        'item_details' => ['airline_code' => 'YI'],
        'product_details' => [],
        'price' => 100,
        'net_fare' => 100,
        'taxes' => [],
        'total' => 100,
        'total_amount' => 100,
        'currency' => 'LYD',
        'status' => 'confirmed',
    ]);

    app(ApplyFinancialSourceAndCommission::class)->execute($order);

    $item->refresh();
    tenancy()->end();

    expect(data_get($item->item_details, 'financial_source'))->toBe('master_agency_supply')
        ->and(data_get($item->item_details, 'provider_source_type'))->toBe('agency_network')
        ->and(data_get($item->item_details, 'provider_selector'))->toBe("agency_network:{$allocation->id}")
        ->and(data_get($item->item_details, 'financial_source_tenant_id'))->toBe($networkAgency->id)
        ->and(data_get($item->item_details, 'source_agency_tenant_id'))->toBe($networkAgency->id)
        ->and(data_get($item->item_details, 'merchant_tenant_id'))->toBe($merchant->id)
        ->and(data_get($item->item_details, 'network_membership_id'))->toBe($membership->id)
        ->and(data_get($item->item_details, 'provider_allocation_id'))->toBe($allocation->id)
        ->and(data_get($item->item_details, 'is_default_agency_deprecated'))->toBeFalse();
});

test('esim provider appears in available providers with correct shape', function () {
    $agency = createCentralTenant('esim-avail-agency', 'eSIM Available Agency');

    tenancy()->initialize($agency);

    $provider = TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 eSIM',
        'credentials' => ['api_key' => 'test-key', 'client_secret' => 'test-secret'],
        'is_active' => true,
        'commission_esim' => 15.5,
        'currency' => 'USD',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $response = $this->actingAs($user)->get(route('network.index'));

    tenancy()->end();

    $response->assertOk();

    $providers = collect($response->original->getData()['page']['props']['availableProviders']);
    $esimEntry = $providers->firstWhere('key', "esim:{$provider->id}");

    expect($esimEntry)->not->toBeNull()
        ->and($esimEntry['provider_type'])->toBe('esim')
        ->and($esimEntry['financial_mode'])->toBe('commission')
        ->and($esimEntry['source_provider_model'])->toBe(TenantEsimProvider::class)
        ->and($esimEntry['source_provider_id'])->toBe($provider->id)
        ->and($esimEntry['agency_rates']['esim_commission_rate'])->toBe(15.5);
});

test('esim financial terms calculates merchant commission and agency profit correctly', function () {
    $agency = createCentralTenant('esim-terms-agency', 'eSIM Terms Agency');

    tenancy()->initialize($agency);

    $provider = TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 eSIM Terms',
        'credentials' => ['api_key' => 'key', 'client_secret' => 'secret'],
        'is_active' => true,
        'commission_esim' => 20.0,
        'currency' => 'USD',
    ]);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $response = $this->actingAs($user)->post(route('network.invite'), [
        'merchant_email' => 'merchant@example.com',
        'provider_keys' => ["esim:{$provider->id}"],
        'provider_terms' => [
            "esim:{$provider->id}" => ['esim_commission_rate' => 12.0],
        ],
    ]);

    tenancy()->end();

    $response->assertRedirect();

    $allocation = \App\Models\ProviderAllocation::query()
        ->where('provider_type', 'esim')
        ->where('source_provider_id', $provider->id)
        ->first();

    expect($allocation)->not->toBeNull()
        ->and((float) $allocation->commission_rate)->toBe(12.0)
        ->and($allocation->markup_rate)->toBeNull()
        ->and((float) data_get($allocation->metadata, 'financial_terms.merchant_rates.esim_commission_rate'))->toBe(12.0)
        ->and((float) data_get($allocation->metadata, 'financial_terms.agency_profit_rates.esim_commission_rate'))->toBe(8.0);
});

test('financial source application marks deprecated default agency fallback metadata', function () {
    $defaultAgency = createCentralTenant('metadata-default-agency', 'Metadata Default Agency');
    $defaultAgency->update(['is_default_agency' => true]);
    $merchant = createCentralTenant('metadata-default-merchant', 'Metadata Default Merchant');

    tenancy()->initialize($defaultAgency);
    TenantProvider::query()->create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Default Yemenia',
        'account_name' => 'Default',
        'credentials' => ['currency' => 'LYD'],
        'is_active' => true,
    ]);
    tenancy()->end();

    tenancy()->initialize($merchant);
    AgencySetting::current()->update([
        'force_use_default_agency' => true,
        'can_use_own_airline_credentials' => false,
        'default_agency_tenant_id' => $defaultAgency->id,
    ]);

    $user = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'ORD-DEFAULT-META',
        'status' => 'confirmed',
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
    ]);
    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight_ticket',
        'provider' => 'videcom',
        'item_details' => ['airline_code' => 'YI'],
        'product_details' => [],
        'price' => 100,
        'net_fare' => 100,
        'taxes' => [],
        'total' => 100,
        'total_amount' => 100,
        'currency' => 'LYD',
        'status' => 'confirmed',
    ]);

    app(ApplyFinancialSourceAndCommission::class)->execute($order);

    $item->refresh();
    tenancy()->end();

    expect(data_get($item->item_details, 'financial_source'))->toBe('master_agency_supply')
        ->and(data_get($item->item_details, 'provider_source_type'))->toBe('default_agency')
        ->and(data_get($item->item_details, 'provider_selector'))->toBeString()->toStartWith('default_agency:')
        ->and(data_get($item->item_details, 'financial_source_tenant_id'))->toBe($defaultAgency->id)
        ->and(data_get($item->item_details, 'source_agency_tenant_id'))->toBe($defaultAgency->id)
        ->and(data_get($item->item_details, 'is_default_agency_deprecated'))->toBeTrue();
});
