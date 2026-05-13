<?php

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgencyCreatedConfirmation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

test('agency registration creates frozen tenant with documents and sends review email', function () {
    config()->set('app.url', 'https://atom.ly');

    Notification::fake();

    Storage::fake('public');

    $agencyPath = 'atlas-'.Str::lower(Str::random(8));

    $commercialRegister = UploadedFile::fake()->create('commercial-register.pdf', 512, 'application/pdf');
    $passport = UploadedFile::fake()->create('passport.pdf', 256, 'application/pdf');

    $response = $this->post('/register-agency', [
        'company_name' => 'Atlas Travel',
        'owner_name' => 'Amina Saleh',
        'phone' => '+218900000000',
        'email' => 'owner@atlas.test',
        'agency_path' => $agencyPath,
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $commercialRegister,
        'passport' => $passport,
    ]);

    $tenant = Tenant::findOrFail($agencyPath);

    expect($tenant->company_name)->toBe('Atlas Travel');
    expect($tenant->owner_name)->toBe('Amina Saleh');
    expect($tenant->owner_email)->toBe('owner@atlas.test');
    expect($tenant->path)->toBe($agencyPath);
    expect($tenant->status)->toBe('frozen');
    expect($tenant->subscription_status)->toBe('trial');
    expect($tenant->commercial_register_path)->toContain("registrations/{$agencyPath}/");
    expect($tenant->passport_path)->toContain("registrations/{$agencyPath}/");

    $owner = $tenant->run(fn () => User::where('email', 'owner@atlas.test')->first());
    expect($owner)->not->toBeNull();
    expect($owner->role)->toBe('admin');
    expect($owner->is_active)->toBeTrue();

    expect($tenant->agency_number)->toStartWith('AG-');

    $workspaceUrl = 'https://atom.ly/agency/'.$agencyPath.'/login';

    $response->assertRedirectToRoute('agency.registration.success');
    $response->assertSessionHas('agency_registration', [
        'agencyName' => 'Atlas Travel',
        'agencyNumber' => $tenant->agency_number,
        'agencyPath' => $agencyPath,
        'ownerName' => 'Amina Saleh',
        'ownerEmail' => 'owner@atlas.test',
        'workspaceUrl' => $workspaceUrl,
        'status' => 'frozen',
    ]);

    Notification::assertSentOnDemand(
        AgencyCreatedConfirmation::class,
        fn ($notification, $channels, $notifiable): bool => $notification->agencyName === 'Atlas Travel'
            && $notification->agencyNumber === $tenant->agency_number
            && $notification->agencyPath === $agencyPath
            && $notification->ownerName === 'Amina Saleh'
            && $notification->ownerEmail === 'owner@atlas.test'
            && $notification->workspaceUrl === $workspaceUrl
            && $notification->status === 'frozen'
            && $notifiable->routes['mail'] === ['owner@atlas.test' => 'Amina Saleh']
    );

    // Verify files were stored
    Storage::disk('public')->assertExists($tenant->commercial_register_path);
    Storage::disk('public')->assertExists($tenant->passport_path);
});

test('agency registration validates required documents', function () {
    $agencyPath = 'atlas-'.Str::lower(Str::random(8));

    $response = $this->post('/register-agency', [
        'company_name' => 'Atlas Travel',
        'owner_name' => 'Amina Saleh',
        'email' => 'owner@atlas.test',
        'agency_path' => $agencyPath,
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['commercial_register', 'passport']);
});

test('agency registration validates agency_path format', function () {
    Storage::fake('public');
    $pdf = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $response = $this->post('/register-agency', [
        'company_name' => 'Test',
        'email' => 'test@example.com',
        'agency_path' => 'INVALID PATH!',
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    $response->assertSessionHasErrors(['agency_path']);
});

test('agency registration success page renders frozen session details', function () {
    $workspaceUrl = 'https://atom.ly/agency/atlas-travel/login';

    $this->withSession([
        'agency_registration' => [
            'agencyName' => 'Atlas Travel',
            'agencyNumber' => 'AG-100001',
            'agencyPath' => 'atlas-travel',
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
            ->where('registration.agencyPath', 'atlas-travel')
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

test('agency_path is unique across tenants', function () {
    Storage::fake('public');
    $pdf = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    $path = 'unique-'.Str::lower(Str::random(4));

    // First registration
    $this->post('/register-agency', [
        'company_name' => 'First Agency',
        'email' => 'first@test.com',
        'agency_path' => $path,
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    // Second registration with same path
    $response = $this->post('/register-agency', [
        'company_name' => 'Second Agency',
        'email' => 'second@test.com',
        'agency_path' => $path,
        'password' => 'password',
        'password_confirmation' => 'password',
        'commercial_register' => $pdf,
        'passport' => $pdf,
    ]);

    $response->assertSessionHasErrors(['agency_path']);
});
