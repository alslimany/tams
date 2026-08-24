<?php

use App\Models\Tenant;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\User;
use App\Services\ESim\ESimCatalogueService;
use App\Services\ESim\Pricing\L2EsimRrpPrices;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function (): void {
    global $state;

    $tenant = Tenant::create([
        'id' => 'esim-rrp-'.Str::random(4),
        'company_name' => 'ESim RRP Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    TenantEsimProvider::query()->create([
        'provider_type' => 'l2',
        'name' => 'L2 Travel eSIM',
        'credentials' => [
            'base_url' => 'https://l2travelesim.com',
            'api_key' => 'test-api-key',
            'client_secret' => 'test-client-secret',
        ],
        'is_active' => true,
        'commission_esim' => 10,
        'currency' => 'USD',
    ]);

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function (): void {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

/**
 * @param  list<array<string, mixed>>  $bundles
 */
function fakeL2CatalogueWithBundles(array $bundles): void
{
    Http::fake([
        'https://l2travelesim.com/api/whitelabel/v2/catalogue' => Http::response([
            'bundles' => $bundles,
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/bundle/details' => Http::response([
            'name' => $bundles[0]['name'] ?? 'unknown',
            'price' => $bundles[0]['price'] ?? 0,
            'description' => $bundles[0]['description'] ?? '',
            'dataAmount' => $bundles[0]['dataAmount'] ?? 1024,
            'duration' => $bundles[0]['duration'] ?? 7,
            'unlimited' => $bundles[0]['unlimited'] ?? false,
            'countries' => $bundles[0]['countries'] ?? [],
        ], 200),
        'https://l2travelesim.com/api/whitelabel/v2/organization' => Http::response([
            'balance' => 500,
        ], 200),
    ]);
}

test('zero priced l2 package uses temporary rrp table price', function () {
    global $state;

    $knownRef = 'esimd_1GB_7D_AX_V2';
    $expected = L2EsimRrpPrices::get($knownRef);

    expect($expected)->not->toBeNull();

    fakeL2CatalogueWithBundles([
        [
            'name' => $knownRef,
            'description' => 'Aaland Islands 1GB 7 Days',
            'countries' => [['name' => 'Aaland Islands', 'iso' => 'AX']],
            'dataAmount' => 1024,
            'duration' => 7,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
        [
            'name' => 'esimd_2GB_15D_AX_V2',
            'description' => 'Aaland Islands 2GB 15 Days',
            'countries' => [['name' => 'Aaland Islands', 'iso' => 'AX']],
            'dataAmount' => 2048,
            'duration' => 15,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'AX',
    ]);
    $searchResponse->assertRedirect();
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $response = $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful();

    $package = $response->json('packages.0');

    expect($package['id'])->toBe($knownRef)
        ->and((float) $package['price'])->toBe((float) $expected)
        ->and((float) $package['provider_price'])->toBe((float) $expected)
        ->and($package['currency'])->toBe('USD');
});

test('zero priced l2 package without rrp entry is hidden from results', function () {
    global $state;

    fakeL2CatalogueWithBundles([
        [
            'name' => 'esimd_50GB_30D_RAF_V2',
            'description' => 'Africa 50GB NA package',
            'countries' => [['name' => 'Africa', 'iso' => 'EG']],
            'dataAmount' => 51200,
            'duration' => 30,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
        [
            'name' => 'esimd_unknown_zero_pkg',
            'description' => 'Unknown zero package',
            'countries' => [['name' => 'Libya', 'iso' => 'LY']],
            'dataAmount' => 1024,
            'duration' => 30,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'LY',
    ]);
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('packages', []);
});

test('nonzero l2 package price is kept and rrp table is not applied', function () {
    global $state;

    fakeL2CatalogueWithBundles([
        [
            'name' => 'esimd_1GB_7D_AX_V2',
            'description' => 'Aaland Islands 1GB 7 Days',
            'countries' => [['name' => 'Aaland Islands', 'iso' => 'AX']],
            'dataAmount' => 1024,
            'duration' => 7,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 9.99,
        ],
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'AX',
    ]);
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $this->getJson($state['baseUrl'].route('esim.packages', ['uuid' => $searchUuid], false))
        ->assertSuccessful()
        ->assertJsonPath('packages.0.price', 9.99)
        ->assertJsonPath('packages.0.provider_price', 9.99);
});

test('resolve package data rejects zero priced packages missing from rrp table', function () {
    fakeL2CatalogueWithBundles([
        [
            'name' => 'esimd_unknown_zero_pkg',
            'description' => 'Unknown zero package',
            'countries' => [['name' => 'Libya', 'iso' => 'LY']],
            'dataAmount' => 1024,
            'duration' => 30,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
    ]);

    expect(fn () => app(ESimCatalogueService::class)->resolvePackageData('esimd_unknown_zero_pkg', ['country' => 'LY']))
        ->toThrow(RuntimeException::class, 'Selected eSIM package is unavailable.');
});

test('resolve package data applies rrp fallback for zero priced known package', function () {
    $knownRef = 'esimd_1GB_7D_AX_V2';
    $expected = L2EsimRrpPrices::get($knownRef);

    fakeL2CatalogueWithBundles([
        [
            'name' => $knownRef,
            'description' => 'Aaland Islands 1GB 7 Days',
            'countries' => [['name' => 'Aaland Islands', 'iso' => 'AX']],
            'dataAmount' => 1024,
            'duration' => 7,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
    ]);

    $resolved = app(ESimCatalogueService::class)->resolvePackageData($knownRef, ['country' => 'AX']);

    expect($resolved['id'])->toBe($knownRef)
        ->and((float) $resolved['price'])->toBe((float) $expected)
        ->and((float) $resolved['provider_price'])->toBe((float) $expected);
});

test('selecting a zero priced unknown package fails cleanly', function () {
    global $state;

    fakeL2CatalogueWithBundles([
        [
            'name' => 'esimd_unknown_zero_pkg',
            'description' => 'Unknown zero package',
            'countries' => [['name' => 'Libya', 'iso' => 'LY']],
            'dataAmount' => 1024,
            'duration' => 30,
            'speed' => ['4G'],
            'unlimited' => false,
            'price' => 0,
        ],
    ]);

    $this->actingAs($state['admin']);

    $searchResponse = $this->post($state['baseUrl'].route('esim.search', [], false), [
        'country' => 'LY',
    ]);
    $searchUuid = Str::afterLast((string) $searchResponse->headers->get('Location'), '/');

    $this->post($state['baseUrl'].route('esim.select', ['uuid' => $searchUuid], false), [
        'package_id' => 'esimd_unknown_zero_pkg',
    ])->assertRedirect()
        ->assertSessionHas('error');
});
