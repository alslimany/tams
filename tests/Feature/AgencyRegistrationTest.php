<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

test('agency registration creates central tenant metadata and tenant owner', function () {
    $subdomain = 'atlas-'.Str::lower(Str::random(8));

    $response = $this->post('/register-agency', [
        'company_name' => 'Atlas Travel',
        'owner_name' => 'Amina Saleh',
        'phone' => '+218900000000',
        'email' => 'owner@atlas.test',
        'subdomain' => $subdomain,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $tenant = Tenant::findOrFail($subdomain);

    expect($tenant->company_name)->toBe('Atlas Travel');
    expect($tenant->owner_name)->toBe('Amina Saleh');
    expect($tenant->owner_email)->toBe('owner@atlas.test');
    expect($tenant->status)->toBe('active');
    expect($tenant->subscription_status)->toBe('trial');
    $owner = $tenant->run(fn () => User::where('email', 'owner@atlas.test')->first());

    expect($owner)->not->toBeNull();
    expect($owner->role)->toBe('admin');
    expect($owner->is_active)->toBeTrue();

    $response->assertRedirect();
});
