<?php

use App\Models\Tenant;
use App\Models\Tenant\Booking;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    /** @var Tenant $tenant */
    $tenant = Tenant::create(['id' => 'test-agency-'.Str::random(4)]);
    $this->tenant = $tenant;
    $this->tenant->domains()->create(['domain' => $this->tenant->id.'.localhost']);

    tenancy()->initialize($this->tenant);

    $this->user = clone User::factory()->create(['role' => 'agent', 'is_active' => true]);

    $this->provider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);
});

afterEach(function () {
    tenancy()->end();
    $this->tenant->delete();
});

test('agent can view the booking selection page', function () {
    $this->actingAs($this->user);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('bookings.select', [], false);

    $response = $this->post($url, [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'flight' => ['pricing' => ['total' => 100, 'currency' => 'USD']],
    ]);

    $response->assertOk();
});

test('agent can submit a booking and it persists to database', function () {
    $this->actingAs($this->user);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('bookings.store', [], false);

    $response = $this->post($url, [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'YI123',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'IST',
                    'departure_time' => now()->addDays(2)->toDateTimeString(),
                    'arrival_time' => now()->addDays(2)->addHours(3)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@test.com',
            'phone' => '123456789',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'gender' => 'M',
            ],
        ],
    ]);

    $booking = Booking::first();
    expect($booking)->not->toBeNull();

    $response->assertRedirect(route('bookings.show', $booking));

    expect((string) $booking->pnr)->not->toBeEmpty();
    expect((int) $booking->total_price)->toEqual(500);
    expect((string) $booking->currency)->toEqual('LYD');

    expect($booking->customer->email)->toBe('john@test.com');
    expect($booking->passengers)->toHaveCount(1);
    expect($booking->flightSegments)->toHaveCount(1);
    expect($booking->flightSegments->first()->flight_number)->toBe('YI123');
});
