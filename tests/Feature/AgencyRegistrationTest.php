<?php

use App\Models\Airport;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgencyCreatedConfirmation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;

afterEach(function () {
    tenancy()->end();
    Tenant::where('owner_email', 'like', '%@atlas.test')
        ->orWhere('owner_email', 'like', '%@test.com')
        ->get()
        ->each(fn (Tenant $t) => $t->delete());
});

/**
 * Insert a minimal MJI airport row so city_iata validation passes in tests.
 * The airports table is populated from a CSV import (not a seeder), so it is
 * empty after RefreshDatabase runs.
 */
function seedMjiAirport(): void
{
    Airport::firstOrCreate(
        ['iata_code' => 'MJI'],
        [
            'name' => json_encode(['en' => 'Mitiga International Airport']),
            'city' => json_encode(['en' => 'Tripoli']),
            'country' => json_encode(['en' => 'Libya']),
            'type' => 'large_airport',
            'show_in_registration' => true,
            'latitude' => 32.894,
            'longitude' => 13.276,
        ],
    );
}

test('agency registration creates frozen tenant with documents and sends review email', function () {
    config()->set('app.url', 'https://atom.ly');

    Notification::fake();
    Storage::fake('public');

    seedMjiAirport();

    $commercialRegister = UploadedFile::fake()->create('commercial-register.pdf', 512, 'application/pdf');
    $passport = UploadedFile::fake()->create('passport.pdf', 256, 'application/pdf');

    $response = $this->post('/register-agency', [
        'company_name' => 'Atlas Travel',
        'owner_name' => 'Amina Saleh',
        'phone' => '+218900000000',
        'email' => 'owner@atlas.test',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $commercialRegister,
        'passport' => $passport,
    ]);

    $response->assertRedirectToRoute('agency.registration.success');

    $tenant = Tenant::where('city_iata', 'MJI')
        ->where('owner_email', 'owner@atlas.test')
        ->firstOrFail()
        ->fresh();

    expect($tenant->company_name)->toBe('Atlas Travel');
    expect($tenant->owner_name)->toBe('Amina Saleh');
    expect($tenant->owner_email)->toBe('owner@atlas.test');
    expect($tenant->office_id)->toMatch('/^MJI[A-Z]{2}\d{2}AA$/');
    expect($tenant->city_iata)->toBe('MJI');
    expect($tenant->path)->toBe($tenant->office_id);
    expect($tenant->status)->toBe('frozen');
    expect($tenant->subscription_status)->toBe('trial');
    expect($tenant->commercial_register_path)->toContain("registrations/{$tenant->office_id}/");
    expect($tenant->passport_path)->toContain("registrations/{$tenant->office_id}/");

    $owner = $tenant->run(fn () => User::where('email', 'owner@atlas.test')->first());
    expect($owner)->not->toBeNull();
    expect($owner->role)->toBe('admin');
    expect($owner->is_active)->toBeTrue();

    expect($tenant->agency_number)->toStartWith('AG-');

    $officeId = $tenant->office_id;
    $workspaceUrl = 'https://atom.ly/agency/'.strtolower($officeId).'/login';

    $response->assertSessionHas('agency_registration.agencyName', 'Atlas Travel');
    $response->assertSessionHas('agency_registration.officeId', $officeId);
    $response->assertSessionHas('agency_registration.ownerName', 'Amina Saleh');
    $response->assertSessionHas('agency_registration.ownerEmail', 'owner@atlas.test');
    $response->assertSessionHas('agency_registration.status', 'frozen');

    Notification::assertSentOnDemand(
        AgencyCreatedConfirmation::class,
        fn ($notification, $channels, $notifiable): bool => $notification->agencyName === 'Atlas Travel'
            && $notification->agencyNumber === $tenant->agency_number
            && $notification->agencyPath === strtolower($officeId)
            && $notification->ownerName === 'Amina Saleh'
            && $notification->ownerEmail === 'owner@atlas.test'
            && $notification->workspaceUrl === $workspaceUrl
            && $notification->status === 'frozen'
            && $notifiable->routes['mail'] === ['owner@atlas.test' => 'Amina Saleh']
    );

    Storage::disk('public')->assertExists($tenant->commercial_register_path);
    Storage::disk('public')->assertExists($tenant->passport_path);
});

test('agency registration validates required documents', function () {
    seedMjiAirport();

    $response = $this->post('/register-agency', [
        'company_name' => 'Atlas Travel',
        'owner_name' => 'Amina Saleh',
        'email' => 'owner@atlas.test',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['commercial_register', 'passport']);
});

test('agency registration requires a valid city_iata from airports table', function () {
    Storage::fake('public');
    $pdf = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    // Missing city_iata
    $response = $this->post('/register-agency', [
        'company_name' => 'Test Agency',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    $response->assertSessionHasErrors(['city_iata']);

    // Invalid IATA code not in airports table
    $response2 = $this->post('/register-agency', [
        'company_name' => 'Test Agency',
        'email' => 'test@example.com',
        'city_iata' => 'ZZZ',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    $response2->assertSessionHasErrors(['city_iata']);
});

test('agency registration success page renders frozen session details', function () {
    $workspaceUrl = 'https://atom.ly/agency/mjiat01aa/login';

    $this->withSession([
        'agency_registration' => [
            'agencyName' => 'Atlas Travel',
            'agencyNumber' => 'AG-100001',
            'officeId' => 'MJIAT01AA',
            'ownerName' => 'Amina Saleh',
            'ownerEmail' => 'owner@atlas.test',
            'workspaceUrl' => $workspaceUrl,
            'status' => 'frozen',
        ],
    ]);

    $this->get(route('agency.registration.success'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Agency/Success')
            ->where('registration.agencyName', 'Atlas Travel')
            ->where('registration.agencyNumber', 'AG-100001')
            ->where('registration.officeId', 'MJIAT01AA')
            ->where('registration.ownerName', 'Amina Saleh')
            ->where('registration.ownerEmail', 'owner@atlas.test')
            ->where('registration.workspaceUrl', $workspaceUrl)
            ->where('registration.status', 'frozen')
        );
});

test('agency registration success page redirects without session details', function () {
    $this->get(route('agency.registration.success'))
        ->assertRedirectToRoute('agency.register');
});

test('two registrations from same city get unique sequential office ids', function () {
    Storage::fake('public');
    seedMjiAirport();
    $pdf = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $this->post('/register-agency', [
        'company_name' => 'First Agency',
        'email' => 'first@test.com',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    $this->post('/register-agency', [
        'company_name' => 'First Agency',
        'email' => 'second@test.com',
        'city_iata' => 'MJI',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    $tenants = Tenant::where('city_iata', 'MJI')
        ->whereIn('owner_email', ['first@test.com', 'second@test.com'])
        ->get();

    expect($tenants)->toHaveCount(2);
    expect($tenants->pluck('office_id')->unique())->toHaveCount(2);
});
