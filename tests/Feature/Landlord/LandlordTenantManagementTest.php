<?php

use App\Models\LandlordUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('landlord can sign in and view the dashboard', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $response = $this->post(route('landlord.login.store'), [
        'email' => $landlord->email,
        'password' => 'secret-password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('landlord.dashboard'));

    $this->assertAuthenticated('landlord');
});

test('landlord can view tenants and update tenant status', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $tenant = Tenant::create([
        'id' => 'agency-'.Str::random(4),
        'company_name' => 'North Star Travel',
        'owner_name' => 'Mona Ali',
        'owner_email' => 'mona@example.com',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    $tenant->run(function (): void {
        User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    });

    $this->actingAs($landlord, 'landlord');

    $this->get(route('landlord.tenants.index'))
        ->assertSuccessful()
        ->assertSee('North Star Travel');

    $this->get(route('landlord.tenants.show', $tenant))
        ->assertSuccessful()
        ->assertSee('North Star Travel');

    $this->patch(route('landlord.tenants.status', $tenant), [
        'status' => 'frozen',
    ])->assertRedirect();

    expect($tenant->fresh()->status)->toBe('frozen');
});
