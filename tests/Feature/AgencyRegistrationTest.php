<?php

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgencyCreatedConfirmation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

test('agency registration creates central tenant metadata and tenant owner', function () {
    config()->set('tenancy.tenant_base_domain', 'atom.ly');
    config()->set('tenancy.tenant_url_scheme', 'https');

    Notification::fake();

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
    expect($tenant->domains()->first()->domain)->toBe($subdomain.'.atom.ly');
    expect($tenant->agency_number)->toStartWith('AG-');

    $response->assertRedirectToRoute('agency.registration.success');
    $response->assertSessionHas('agency_registration', [
        'agencyName' => 'Atlas Travel',
        'agencyNumber' => $tenant->agency_number,
        'ownerName' => 'Amina Saleh',
        'ownerEmail' => 'owner@atlas.test',
        'domain' => $subdomain.'.atom.ly',
        'loginUrl' => 'https://'.$subdomain.'.atom.ly/login',
    ]);

    Notification::assertSentOnDemand(
        AgencyCreatedConfirmation::class,
        fn (AgencyCreatedConfirmation $notification, array $channels, object $notifiable): bool => $notification->agencyName === 'Atlas Travel'
            && $notification->agencyNumber === $tenant->agency_number
            && $notification->ownerName === 'Amina Saleh'
            && $notification->ownerEmail === 'owner@atlas.test'
            && $notification->domain === $subdomain.'.atom.ly'
            && $notification->loginUrl === 'https://'.$subdomain.'.atom.ly/login'
            && $notifiable->routes['mail'] === ['owner@atlas.test' => 'Amina Saleh']
    );
});

test('agency registration success page renders session details', function () {
    $this->withSession([
        'agency_registration' => [
            'agencyName' => 'Atlas Travel',
            'agencyNumber' => 'AG-100001',
            'ownerName' => 'Amina Saleh',
            'ownerEmail' => 'owner@atlas.test',
            'domain' => 'atlas.atom.ly',
            'loginUrl' => 'https://atlas.atom.ly/login',
        ],
    ]);

    $this->get(route('agency.registration.success'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Agency/Success')
            ->where('registration.agencyName', 'Atlas Travel')
            ->where('registration.agencyNumber', 'AG-100001')
            ->where('registration.ownerName', 'Amina Saleh')
            ->where('registration.ownerEmail', 'owner@atlas.test')
            ->where('registration.domain', 'atlas.atom.ly')
            ->where('registration.loginUrl', 'https://atlas.atom.ly/login')
        );
});

test('agency registration success page redirects without session details', function () {
    $this->get(route('agency.registration.success'))
        ->assertRedirectToRoute('agency.register');
});
