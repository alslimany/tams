<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'lang-'.Str::random(4),
        'company_name' => 'Language Test Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $state['tenant'] = $tenant;
    $state['tenantPath'] = '/agency/'.$tenant->id;
    $state['domain'] = $tenant->id.'.localhost';

    $tenant->domains()->create(['domain' => $state['domain']]);

    tenancy()->initialize($tenant);

    $state['user'] = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
    ]);
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('tenant path can switch language for authenticated user', function () {
    global $state;

    $dashboardUrl = $state['tenantPath'].'/dashboard';
    $switchUrl = $state['tenantPath'].'/language/switch?locale=ar';

    $this->actingAs($state['user'])
        ->from($dashboardUrl)
        ->get($switchUrl)
        ->assertRedirect($dashboardUrl)
        ->assertSessionHas('success');

    expect(session('locale'))->toBe('ar');
});

test('tenant domain path language switch persists locale', function () {
    global $state;

    $dashboardPath = route('dashboard', ['tenant' => $state['tenant']->id], false);
    $switchPath = route('language.switch', ['tenant' => $state['tenant']->id], false).'?locale=fr';
    $baseUrl = 'http://'.$state['domain'];

    $this->actingAs($state['user'])
        ->from($baseUrl.$dashboardPath)
        ->get($baseUrl.$switchPath)
        ->assertRedirect($baseUrl.$dashboardPath);

    expect(session('locale'))->toBe('fr');
});

test('tenant dashboard shares switched locale with inertia', function () {
    global $state;

    $this->actingAs($state['user'])
        ->withSession(['locale' => 'fr'])
        ->get($state['tenantPath'].'/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('locale', 'fr'));
});
