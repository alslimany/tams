<?php

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-flight-'.Str::random(4),
        'company_name' => 'API Flight Agency',
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

    TenantProvider::query()->create([
        'name' => 'Videcom Airways',
        'airline_code' => 'YI',
        'airline_name' => 'Videcom Airways',
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
    $state['user'] = $user;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('flight search creates session and returns providers', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
        ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.search_params.origin', 'MJI')
        ->assertJsonPath('data.search_params.destination', 'IST')
        ->assertJsonPath('data.providers.0.airline_code', 'YI')
        ->assertJsonStructure(['data' => ['uuid', 'providers', 'search_params']]);
});

test('flight search with round-trip validates return date', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => true,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['return_date']);
});

test('flight search validates required fields', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['origin', 'destination', 'date', 'adults']);
});

test('results with expired uuid returns 410', function () {
    global $state;

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/results/nonexistent-uuid?provider_id=1');

    $response->assertStatus(410);
});

test('results with unknown provider returns 404', function () {
    global $state;

    // Create a valid search session first
    $search = $this->withToken($state['token'])
        ->postJson($state['apiUrl'].'/flights/search', [
            'origin' => 'MJI',
            'destination' => 'IST',
            'date' => '2026-06-15',
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
        ]);
    $uuid = $search->json('data.uuid');

    $response = $this->withToken($state['token'])
        ->getJson($state['apiUrl'].'/flights/results/'.$uuid.'?provider_id=9999');

    $response->assertStatus(404);
});
