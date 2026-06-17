<?php

use App\Models\Country;
use App\Models\LandlordUser;
use Illuminate\Support\Facades\Hash;

test('landlord can view countries index with featured filter', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $this->actingAs($landlord, 'landlord');

    $response = $this->get(route('landlord.countries.index'));

    $response->assertSuccessful();

    // Should see the initially seeded featured countries
    $response->assertSee('Turkey')
        ->assertSee('Tunisia')
        ->assertSee('Egypt')
        ->assertSee('Italy');
});

test('landlord can filter countries by esim_featured = yes', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $this->actingAs($landlord, 'landlord');

    $response = $this->get(route('landlord.countries.index', ['esim_featured' => 'yes']));

    $response->assertSuccessful();
    $response->assertSee('Featured');

    // Should NOT see countries that aren't featured (e.g. Afghanistan)
    $response->assertDontSee('Afghanistan');
});

test('landlord can toggle esim_featured on a country', function () {
    $landlord = LandlordUser::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => Hash::make('secret-password'),
    ]);

    $this->actingAs($landlord, 'landlord');

    $country = Country::first();

    $initialFeatured = $country->esim_featured;

    // Toggle
    $this->patch(route('landlord.countries.toggle-esim-featured', $country))
        ->assertRedirect();

    $country->refresh();

    expect($country->esim_featured)->toBe(! $initialFeatured);

    // Toggle back
    $this->patch(route('landlord.countries.toggle-esim-featured', $country))
        ->assertRedirect();

    $country->refresh();

    expect($country->esim_featured)->toBe($initialFeatured);
});

test('eSIM search page shows featured countries', function () {
    $response = $this->get(route('esim.index', 'test-tenant'));

    $response->assertSuccessful();
    $response->assertSee('featuredCountries');
});
