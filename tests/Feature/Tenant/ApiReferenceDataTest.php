<?php

use App\Models\Airport;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-ref-'.Str::random(4),
        'company_name' => 'API Ref Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'role' => 'agent',
        'is_active' => true,
    ]);

    // Seed test airports
    Airport::query()->create([
        'iata_code' => 'MJI',
        'name' => json_encode(['en' => 'Mitiga Airport', 'ar' => 'مطار معيتيقة']),
        'city' => json_encode(['en' => 'Tripoli', 'ar' => 'طرابلس']),
        'country' => json_encode(['en' => 'Libya', 'ar' => 'ليبيا']),
    ]);
    Airport::query()->create([
        'iata_code' => 'IST',
        'name' => json_encode(['en' => 'Istanbul Airport', 'ar' => 'مطار اسطنبول']),
        'city' => json_encode(['en' => 'Istanbul', 'ar' => 'اسطنبول']),
        'country' => json_encode(['en' => 'Türkiye', 'ar' => 'تركيا']),
    ]);
    Airport::query()->create([
        'iata_code' => 'TIP',
        'name' => json_encode(['en' => 'Tripoli International', 'ar' => 'مطار طرابلس الدولي']),
        'city' => json_encode(['en' => 'Tripoli', 'ar' => 'طرابلس']),
        'country' => json_encode(['en' => 'Libya', 'ar' => 'ليبيا']),
    ]);

    // Seed an active airline provider
    TenantProvider::query()->create([
        'name' => 'Yemenia Airways',
        'airline_code' => 'YI',
        'airline_name' => 'Yemenia Airways',
        'provider_type' => 'videcom',
        'credentials' => ['base_url' => 'https://example.com', 'api_key' => 'test-key'],
        'is_active' => true,
        'commission_own' => 5,
        'currency' => 'LYD',
    ]);

    $token = $user->createToken('Test Device')->plainTextToken;

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
    $state['token'] = $token;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('airlines endpoint returns active airlines', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/airlines');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.airline_code', 'YI')
        ->assertJsonPath('data.0.airline_name', 'Yemenia Airways');
});

test('airports search returns matching airports', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/airports/search?q=IST');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.iata_code', 'IST');
});

test('airports search with empty query returns popular airports', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/airports/search?q=');

    // Returns common airports that exist in DB (MJI, TIP, IST)
    $response->assertOk()
        ->assertJsonCount(3);
});

test('calendar hints returns monthly fare data', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/calendar-hints?origin=MJI&destination=IST&month=2026-05');

    $response->assertOk()
        ->assertJsonPath('origin', 'MJI')
        ->assertJsonPath('destination', 'IST')
        ->assertJsonPath('month', '2026-05')
        ->assertJsonStructure(['hints']);
});

test('unauthenticated requests are rejected', function () {
    global $state;

    $this->getJson($state['apiUrl'].'/airlines')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/airports/search?q=IST')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/flights/calendar-hints?origin=MJI&destination=IST&month=2026-05')->assertUnauthorized();
    $this->postJson($state['apiUrl'].'/flights/return-options', [])->assertUnauthorized();
});
