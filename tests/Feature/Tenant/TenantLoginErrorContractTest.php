<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The tenant login form posts with axios (not Inertia), so a failed login must
 * come back as a 422 JSON payload whose `errors` / `message` the React login
 * page renders inside its destructive alert.
 *
 * @var array<string, mixed> $state
 */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'loginerr-'.Str::random(4),
        'company_name' => 'Login Error Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $state['tenant'] = $tenant;
    $state['baseUrl'] = 'http://'.$tenant->domains->first()->domain;
    $state['loginUrl'] = $state['baseUrl'].route('login', ['tenant' => $tenant->id], false);

    tenancy()->initialize($tenant);

    User::factory()->create(['email' => 'agent@loginerr.test']);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

/**
 * @return array<string, string>
 */
function loginJsonHeaders(): array
{
    return [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ];
}

test('the tenant login page renders the form that hosts the alert', function () {
    global $state;

    $this->get($state['loginUrl'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login'));
});

test('wrong credentials return a 422 json error the login alert can render', function () {
    global $state;

    $response = $this->withHeaders(loginJsonHeaders())
        ->post($state['loginUrl'], [
            'email' => 'agent@loginerr.test',
            'password' => 'wrong-password',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect($response->json('errors.email.0'))->toBe(trans('auth.failed'))
        ->and($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('an unknown email fails with the same credential error', function () {
    global $state;

    $response = $this->withHeaders(loginJsonHeaders())
        ->post($state['loginUrl'], [
            'email' => 'nobody@loginerr.test',
            'password' => 'wrong-password',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect($response->json('errors.email.0'))->toBe(trans('auth.failed'));
});

test('repeated wrong credentials are locked out with a throttling message', function () {
    global $state;

    foreach (range(1, 5) as $attempt) {
        $this->withHeaders(loginJsonHeaders())
            ->post($state['loginUrl'], [
                'email' => 'agent@loginerr.test',
                'password' => 'wrong-password',
            ])
            ->assertStatus(422);
    }

    $response = $this->withHeaders(loginJsonHeaders())
        ->post($state['loginUrl'], [
            'email' => 'agent@loginerr.test',
            'password' => 'wrong-password',
        ]);

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);

    expect($response->json('errors.email.0'))
        ->toContain('Too many login attempts')
        ->not->toBe(trans('auth.failed'));
});
