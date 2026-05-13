<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'api-auth-'.Str::random(4),
        'company_name' => 'API Auth Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $state['tenant'] = $tenant;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';

    $state['user'] = User::factory()->create([
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'role' => 'agent',
        'is_active' => true,
    ]);

    $state['inactiveUser'] = User::factory()->create([
        'email' => 'blocked@example.com',
        'password' => 'Secret123!',
        'role' => 'agent',
        'is_active' => false,
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('login with valid credentials returns a Bearer token', function () {
    global $state;

    $response = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'device_name' => 'iPhone 15 Pro',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.token', fn ($token) => is_string($token) && strlen($token) > 10)
        ->assertJsonPath('data.user.email', 'agent@example.com')
        ->assertJsonPath('data.user.role', 'agent')
        ->assertJsonPath('data.user.is_active', true);
});

test('login with invalid password returns 422', function () {
    global $state;

    $response = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'agent@example.com',
        'password' => 'wrong-password',
        'device_name' => 'iPhone',
    ]);

    $response->assertStatus(422);
});

test('login with deactivated account returns 403', function () {
    global $state;

    $response = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'blocked@example.com',
        'password' => 'Secret123!',
        'device_name' => 'iPhone',
    ]);

    $response->assertForbidden();
});

test('authenticated user can retrieve their profile', function () {
    global $state;

    $login = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Test Device',
    ]);
    $token = $login->json('data.token');

    $response = $this->withToken($token)
        ->getJson($state['apiUrl'].'/auth/me');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.email', 'agent@example.com')
        ->assertJsonPath('data.role', 'agent');
});

test('authenticated user can list their tokens', function () {
    global $state;

    $login = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'agent@example.com',
        'password' => 'Secret123!',
        'device_name' => 'Device One',
    ]);
    $token = $login->json('data.token');

    $response = $this->withToken($token)
        ->getJson($state['apiUrl'].'/auth/tokens');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.0.name', 'Device One');
});

test('user can revoke a token via API', function () {
    global $state;

    // Create token directly on model
    $token = $state['user']->createToken('Revocable Device')->plainTextToken;

    // Verify token exists in DB
    expect($state['user']->tokens()->count())->toBe(1);

    // Revoke via the API with the same token
    $this->withToken($token)
        ->deleteJson($state['apiUrl'].'/auth/token')
        ->assertOk()
        ->assertJsonPath('success', true);

    // Token must be deleted from DB
    expect($state['user']->tokens()->count())->toBe(0);
});

test('unauthenticated requests are rejected', function () {
    global $state;

    $this->getJson($state['apiUrl'].'/auth/me')->assertUnauthorized();
    $this->getJson($state['apiUrl'].'/auth/tokens')->assertUnauthorized();
    $this->deleteJson($state['apiUrl'].'/auth/token')->assertUnauthorized();
});
