<?php

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Contracts\Insurance\InsuranceProviderInterface;
use App\DTOs\Insurance\InsuranceBookingRequest;
use App\DTOs\Insurance\InsuranceBookingResult;
use App\DTOs\Insurance\InsuranceQuoteRequest;
use App\DTOs\Insurance\InsuranceQuoteResult;
use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\User;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ins-flow-'.Str::random(4),
        'company_name' => 'Insurance Flow Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $admin = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);

    User::factory()->create([
        'role' => 'agent',
        'is_active' => true,
    ]);

    TenantInsuranceProvider::query()->create([
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'is_active' => true,
        'commission_compulsory' => 5,
        'commission_travel' => 7,
        'commission_orange' => 8,
    ]);

    $state['tenant'] = $tenant;
    $state['admin'] = $admin;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
    \Mockery::close();
});

function bindInsuranceManagerFake(): void
{
    $provider = new class implements InsuranceProviderInterface
    {
        public function quote(InsuranceQuoteRequest $request): InsuranceQuoteResult
        {
            return new InsuranceQuoteResult(
                success: true,
                message: 'quoted',
                totalPremium: 150,
                netPremium: 140,
                taxAmount: 10,
                currency: 'LYD',
                raw: ['Code' => 200, 'Statues' => true],
            );
        }

        public function book(InsuranceBookingRequest $request): InsuranceBookingResult
        {
            return new InsuranceBookingResult(
                success: true,
                message: 'booked',
                policyNumber: 'POL123',
                reportReference: 'ENC123',
                totalPremium: 150,
                netPremium: 140,
                taxAmount: 10,
                currency: 'LYD',
                raw: ['Code' => 200, 'Statues' => true, 'policyNumber' => 'POL123'],
            );
        }

        public function lookup(string $productType, string $lookupKey): array
        {
            return [
                ['id' => 1, 'name' => 'Lookup A'],
            ];
        }

        public function policyReportUrl(string $productType, string $reportReference): string
        {
            return 'https://example.com/report?ref='.$reportReference;
        }

        public function cancel(string $productType, int $insurancePolicyId, string $remarks): array
        {
            return ['cancelled' => true];
        }

        public function fetchPolicyReport(string $productType, string $reportReference): array
        {
            return ['content' => '<pdf-content>', 'content_type' => 'application/pdf'];
        }

        public function listCancellationRequests(string $dateFrom, string $dateTo): array
        {
            return [];
        }
    };

    $manager = \Mockery::mock(InsuranceProviderManager::class);
    $manager->shouldReceive('provider')->andReturn($provider);
    $manager->shouldReceive('activeProvider')->andReturn(TenantInsuranceProvider::query()->first());

    app()->instance(InsuranceProviderManager::class, $manager);
}

test('insurance search page is accessible for admin', function () {
    global $state;

    bindInsuranceManagerFake();

    $this->actingAs($state['admin']);

    $response = $this->get($state['baseUrl'].route('insurance.search', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Insurance/Search')
            ->has('productTypes')
        );
});

test('insurance config page is accessible for admin', function () {
    global $state;

    $this->actingAs($state['admin']);

    $response = $this->get($state['baseUrl'].route('settings.insurance.index', [], false));

    $response->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Settings/Insurance')
            ->has('providers')
        );
});

test('insurance config store saves albaraka settings', function () {
    global $state;

    $this->actingAs($state['admin']);

    $response = $this->post($state['baseUrl'].route('settings.insurance.store', [], false), [
        'provider_type' => 'albaraka',
        'name' => 'Al Baraka Insurance',
        'base_url' => 'https://tameen.webapi.ly',
        'token' => 'BearerToken123',
        'is_active' => true,
        'commission_compulsory' => 6,
        'commission_travel' => 7,
        'commission_orange' => 8,
    ]);

    $response->assertRedirect();

    $saved = TenantInsuranceProvider::query()
        ->where('provider_type', 'albaraka')
        ->first();

    expect($saved)->not->toBeNull()
        ->and(data_get($saved?->credentials, 'token'))->toBe('BearerToken123')
        ->and(data_get($saved?->credentials, 'base_url'))->toBe('https://tameen.webapi.ly')
        ->and((float) $saved?->commission_compulsory)->toBe(6.0)
        ->and((float) $saved?->commission_travel)->toBe(7.0)
        ->and((float) $saved?->commission_orange)->toBe(8.0);
});

test('insurance quote flashes quote result', function () {
    global $state;

    bindInsuranceManagerFake();

    $this->actingAs($state['admin']);

    $response = $this->post($state['baseUrl'].route('insurance.quote', [], false), [
        'product_type' => 'travel',
        'payload' => [
            'ClientProfileId' => 1,
            'ClientProfilePaxeId' => 1,
            'ZoneID' => 1,
            'InsuranceDurationID' => 1,
            'PolicyDateFrom' => now()->toISOString(),
            'IsPolicyPaid' => true,
        ],
    ]);

    $response->assertRedirect()
        ->assertSessionHas('insurance_quote');
});

test('insurance booking redirects to order show', function () {
    global $state;

    bindInsuranceManagerFake();

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['admin']->id,
        'number' => 'INS12345AA',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 140,
        'tax_total' => 10,
        'grand_total' => 150,
        'amount_paid' => 150,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'payment_reference' => 'POL123',
    ]);

    $action = \Mockery::mock(CreateOrderFromInsuranceBooking::class);
    $action->shouldReceive('execute')->once()->andReturn($order);
    app()->instance(CreateOrderFromInsuranceBooking::class, $action);

    $this->actingAs($state['admin']);

    $response = $this->post($state['baseUrl'].route('insurance.book', [], false), [
        'product_type' => 'compulsory',
        'payload' => [
            'ClientProfileVehicleId' => 1,
            'InsuranceDurationId' => 1,
            'PolicyDateFrom' => now()->toISOString(),
            'IsPolicyPaid' => true,
        ],
    ]);

    $response->assertRedirect(route('orders.show', $order, false));
});
