<?php

use App\Models\Tenant;
use App\Models\Tenant\ApiAuditLog;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'phase6-api-'.Str::random(4),
        'company_name' => 'Phase 6 API Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'email' => 'admin@phase6.test',
        'password' => bcrypt('Secret123!'),
        'role' => 'admin',
        'is_active' => true,
    ]);

    $state['tenant'] = $tenant;
    $state['user'] = $user;
    $state['apiUrl'] = 'http://localhost/agency/'.$tenant->id.'/api/v1';
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

// ---------------------------------------------------------------------------
// Token Login — Abilities
// ---------------------------------------------------------------------------

test('login without abilities returns full-access token', function () {
    global $state;

    $response = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'admin@phase6.test',
        'password' => 'Secret123!',
        'device_name' => 'Test Device',
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.abilities', ['*']);
});

test('login with specific abilities scopes the token', function () {
    global $state;

    $response = $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'admin@phase6.test',
        'password' => 'Secret123!',
        'device_name' => 'Read-Only Device',
        'abilities' => ['read'],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.abilities', ['read']);
});

test('login rejects invalid ability values', function () {
    global $state;

    $this->postJson($state['apiUrl'].'/auth/token', [
        'email' => 'admin@phase6.test',
        'password' => 'Secret123!',
        'device_name' => 'Bad Device',
        'abilities' => ['superpower'],
    ])->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Ability Enforcement
// ---------------------------------------------------------------------------

test('read-only token can access GET endpoints', function () {
    global $state;

    $token = $state['user']->createToken('read-only', ['read'])->plainTextToken;

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/auth/me')
        ->assertOk();

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/orders')
        ->assertOk();
});

test('read-only token is forbidden from write endpoints', function () {
    global $state;

    $token = $state['user']->createToken('read-only', ['read'])->plainTextToken;

    $this->withToken($token)
        ->postJson($state['apiUrl'].'/flights/search', [])
        ->assertForbidden();
});

test('read-only token is forbidden from issue endpoints', function () {
    global $state;

    $token = $state['user']->createToken('read-only', ['read'])->plainTextToken;

    // The ability check fires before route model binding, so a non-existent booking
    // returns 404 (model not found) rather than 403. Use insurance/compulsory/issue
    // which has no route model binding to get a clean 403.
    $this->withToken($token)
        ->postJson($state['apiUrl'].'/insurance/compulsory/issue', [])
        ->assertForbidden();
});

test('write token cannot access report endpoints', function () {
    global $state;

    $token = $state['user']->createToken('write-only', ['write'])->plainTextToken;

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/dashboard')
        ->assertForbidden();
});

test('full-access token can reach all ability groups', function () {
    global $state;

    $token = $state['user']->createToken('full', ['*'])->plainTextToken;

    $this->withToken($token)->getJson($state['apiUrl'].'/orders')->assertOk();
    $this->withToken($token)->getJson($state['apiUrl'].'/dashboard')->assertOk();
});

// ---------------------------------------------------------------------------
// Token Management (API endpoints)
// ---------------------------------------------------------------------------

test('authenticated user can list their tokens', function () {
    global $state;

    $token = $state['user']->createToken('my-token', ['read'])->plainTextToken;

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/auth/tokens')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'abilities', 'created_at']]]);
});

test('authenticated user can create a new token via API', function () {
    global $state;

    $token = $state['user']->createToken('admin-token', ['*'])->plainTextToken;

    $response = $this->withToken($token)
        ->postJson($state['apiUrl'].'/auth/tokens', [
            'name' => 'Mobile App',
            'abilities' => ['read', 'write'],
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'Mobile App')
        ->assertJsonPath('data.abilities', ['read', 'write'])
        ->assertJsonStructure(['data' => ['id', 'name', 'abilities', 'token', 'created_at']]);
});

test('authenticated user can revoke a token via API', function () {
    global $state;

    $adminToken = $state['user']->createToken('admin', ['*'])->plainTextToken;
    $toRevoke = $state['user']->createToken('to-revoke', ['read']);
    $revokeId = $toRevoke->accessToken->id;

    $this->withToken($adminToken)
        ->deleteJson($state['apiUrl'].'/auth/tokens/'.$revokeId)
        ->assertOk();

    expect($state['user']->tokens()->where('id', $revokeId)->exists())->toBeFalse();
});

test('user cannot revoke another users token', function () {
    global $state;

    $otherUser = User::factory()->create(['role' => 'agent', 'is_active' => true]);
    $otherToken = $otherUser->createToken('other', ['read']);

    $myToken = $state['user']->createToken('mine', ['*'])->plainTextToken;

    $this->withToken($myToken)
        ->deleteJson($state['apiUrl'].'/auth/tokens/'.$otherToken->accessToken->id)
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

test('idempotency key returns same response on repeat request', function () {
    global $state;

    $token = $state['user']->createToken('issue-token', ['issue'])->plainTextToken;
    $idempotencyKey = Str::uuid()->toString();

    // First request — will fail (no booking) but we test the caching of 2xx only
    // Use a read endpoint that returns 200 to test idempotency caching
    $readToken = $state['user']->createToken('read-token', ['read'])->plainTextToken;

    $first = $this->withToken($readToken)
        ->withHeaders(['Idempotency-Key' => $idempotencyKey])
        ->getJson($state['apiUrl'].'/orders');

    $first->assertOk();

    $second = $this->withToken($readToken)
        ->withHeaders(['Idempotency-Key' => $idempotencyKey])
        ->getJson($state['apiUrl'].'/orders');

    $second->assertOk()
        ->assertHeader('X-Idempotency-Replayed', 'true');
});

test('requests without idempotency key proceed normally', function () {
    global $state;

    $token = $state['user']->createToken('read-token', ['read'])->plainTextToken;

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/orders')
        ->assertOk()
        ->assertHeaderMissing('X-Idempotency-Replayed');
});

// ---------------------------------------------------------------------------
// Audit Logs
// ---------------------------------------------------------------------------

test('authenticated API requests are recorded in audit log', function () {
    global $state;

    $token = $state['user']->createToken('audit-test', ['read'])->plainTextToken;

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/orders')
        ->assertOk();

    expect(ApiAuditLog::count())->toBeGreaterThanOrEqual(1);

    $log = ApiAuditLog::latest()->first();
    expect($log->user_id)->toBe($state['user']->id)
        ->and($log->method)->toBe('GET')
        ->and($log->status_code)->toBe(200);
});

test('audit log records token abilities', function () {
    global $state;

    $token = $state['user']->createToken('ability-audit', ['read', 'report'])->plainTextToken;

    $this->withToken($token)
        ->getJson($state['apiUrl'].'/orders')
        ->assertOk();

    $log = ApiAuditLog::latest()->first();
    expect($log->abilities)->toContain('read')
        ->and($log->abilities)->toContain('report');
});

// ---------------------------------------------------------------------------
// Rate Limiting
// ---------------------------------------------------------------------------

test('rate limit headers are present on API responses', function () {
    global $state;

    $token = $state['user']->createToken('rate-test', ['read'])->plainTextToken;

    $response = $this->withToken($token)
        ->getJson($state['apiUrl'].'/orders');

    $response->assertOk()
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining');
});
