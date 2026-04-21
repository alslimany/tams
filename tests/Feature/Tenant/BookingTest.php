<?php

use App\Models\Airport;
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

    $this->bookingResponse = '<PNR><Locator>ABC123</Locator></PNR>';
    $this->pricingResponse = '<FareQuote />';
    $this->ancillaryCatalog = [
        [
            'code' => 'extra_baggage_1kg',
            'label' => 'Extra baggage',
            'enabled' => true,
            'type' => 'baggage_increment',
            'pricing_mode' => 'per_kg',
            'unit_price' => 10,
        ],
        [
            'code' => 'insurance',
            'label' => 'Insurance',
            'enabled' => true,
            'type' => 'special_service',
            'pricing_mode' => 'per_passenger',
            'unit_price' => 20,
        ],
    ];

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('createBooking')->andReturnUsing(fn () => $this->bookingResponse);
    $providerMock->shouldReceive('getPricing')->andReturnUsing(fn () => $this->pricingResponse);
    $providerMock->shouldReceive('getAncillaryCatalog')->andReturnUsing(fn () => $this->ancillaryCatalog);
    $providerMock->shouldReceive('previewBookingCommand')->andReturn('i^-1TEST/TESTMR^9-1M*+123456789^9-1E*temp@example.com^0YI0510Y12AprMJISEBNN1^FG^FS1^MI-ABC TOURS01012^EZT*R^EZRE^*R~x');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
});

afterEach(function () {
    tenancy()->end();
    $this->tenant->delete();
    \Mockery::close();
});

test('agent can view the booking selection page', function () {
    $this->actingAs($this->user);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.select', [], false);

    $response = $this->post($url, [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'flight' => ['pricing' => ['total' => 100, 'currency' => 'USD']],
        'reservation_type' => 'NN',
    ]);

    $response->assertOk();
});

test('agent can submit a booking and it persists to database', function () {
    $this->actingAs($this->user);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);

    $response = $this->post($url, [
        'preview_issue_command' => false,
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
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
        'extras' => [
            'selected_services' => [
                [
                    'code' => 'extra_baggage_1kg',
                    'quantity' => 2,
                    'passengers' => [],
                ],
                [
                    'code' => 'insurance',
                    'quantity' => 1,
                    'passengers' => [0],
                ],
            ],
            'seats' => [
                0 => [
                    1 => '12A',
                ],
            ],
        ],
    ]);

    $booking = Booking::first();
    expect($booking)->not->toBeNull();

    $response->assertRedirect(route('flights.show', $booking));

    expect((string) $booking->pnr)->not->toBeEmpty();
    expect((float) $booking->total_price)->toEqual(540.0);
    expect((string) $booking->currency)->toEqual('LYD');
    expect($booking->raw_request['ancillary_summary']['total'])->toEqual(40.0);
    expect($booking->raw_request['provider_payload']['extras']['seats'][0][1])->toEqual('12A');

    expect($booking->customer->email)->toBe('john@test.com');
    expect($booking->passengers)->toHaveCount(1);
    expect($booking->flightSegments)->toHaveCount(1);
    expect($booking->flightSegments->first()->flight_number)->toBe('YI123');
});

test('agent booking persists when provider returns PNR RLOC attribute format', function () {
    $this->actingAs($this->user);

    $this->bookingResponse = '<PNR RLOC="AD9PMM"><Tickets><TKT TktNo="928 2972466787" /></Tickets></PNR>';

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);

    $response = $this->post($url, [
        'preview_issue_command' => false,
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 350, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'UZ0002',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'BEN',
                    'departure_time' => now()->addDays(2)->toDateTimeString(),
                    'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Mohammed',
            'email' => 'alslimany@gmail.com',
            'phone' => '911388788',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah',
                'last_name' => 'Mohammed',
                'gender' => 'M',
                'dob' => '1992-05-07',
            ],
        ],
        'extras' => [
            'selected_services' => [],
            'seats' => [],
        ],
    ]);

    $booking = Booking::first();

    expect($booking)->not->toBeNull();
    expect($booking->pnr)->toBe('AD9PMM');

    $response->assertRedirect(route('flights.show', $booking));
});

test('booking is not persisted when videcom returns an authorization error', function () {
    $this->actingAs($this->user);
    $this->bookingResponse = 'Agent not authorised to enter manual ticket time limits';

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);
    $referer = 'http://'.$this->tenant->domains->first()->domain.'/flights/select';

    $response = $this->from($referer)->post($url, [
        'preview_issue_command' => false,
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 280.02, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => '0510',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'SEB',
                    'departure_time' => now()->addDays(2)->toDateTimeString(),
                    'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
                    'class' => 'Y',
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Abdullah',
            'email' => 'alslimany@gmail.com',
            'phone' => '911388788',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah',
                'last_name' => 'Abdullah',
                'gender' => 'M',
                'dob' => '1992-05-07',
                'passport_number' => 'HPPLRF3K',
                'passport_expiry' => '2029-10-01',
                'passport_issue_country' => 'LBY',
                'nationality' => 'LBY',
            ],
        ],
        'extras' => [
            'selected_services' => [],
            'seats' => [
                0 => [
                    1 => '14B',
                ],
            ],
        ],
    ]);

    $response->assertRedirect($referer);
    $response->assertSessionHas('error');

    expect(Booking::count())->toBe(0);
});

test('passport fields are required for international flights', function () {
    $this->actingAs($this->user);

    Airport::query()->create([
        'name' => ['en' => 'Mitiga International Airport'],
        'city' => ['en' => 'Tripoli'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'MJI',
    ]);

    Airport::query()->create([
        'name' => ['en' => 'Tunis-Carthage International Airport'],
        'city' => ['en' => 'Tunis'],
        'country' => ['en' => 'Tunisia'],
        'iata_code' => 'TUN',
    ]);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);
    $referer = 'http://'.$this->tenant->domains->first()->domain.'/flights/select';

    $response = $this->from($referer)->post($url, [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'UZ0123',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'TUN',
                    'departure_time' => now()->addDays(3)->toDateTimeString(),
                    'arrival_time' => now()->addDays(3)->addHours(2)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Abdullah',
            'email' => 'abdullah@test.com',
            'phone' => '+218910000000',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah',
                'last_name' => 'Abdullah',
                'gender' => 'M',
                'dob' => '1992-05-07',
            ],
        ],
    ]);

    $response->assertRedirect($referer);
    $response->assertSessionHasErrors([
        'passengers.0.passport_number',
        'passengers.0.passport_expiry',
        'passengers.0.passport_issue_country',
        'passengers.0.nationality',
    ]);
});

test('passport fields are optional for domestic flights and command preview is returned', function () {
    $this->actingAs($this->user);

    Airport::query()->create([
        'name' => ['en' => 'Mitiga International Airport'],
        'city' => ['en' => 'Tripoli'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'MJI',
    ]);

    Airport::query()->create([
        'name' => ['en' => 'Benina International Airport'],
        'city' => ['en' => 'Benghazi'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'BEN',
    ]);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);
    $referer = 'http://'.$this->tenant->domains->first()->domain.'/flights/select';

    $response = $this->from($referer)->post($url, [
        'preview_issue_command' => true,
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'YI0510',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'BEN',
                    'departure_time' => now()->addDays(3)->toDateTimeString(),
                    'arrival_time' => now()->addDays(3)->addHours(1)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Abdullah',
            'email' => 'abdullah@test.com',
            'phone' => '+218910000000',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah',
                'last_name' => 'Abdullah',
                'gender' => 'M',
                'dob' => '1992-05-07',
            ],
        ],
    ]);

    $response->assertRedirect($referer);
    $response->assertSessionHas('issue_command_preview');
    $response->assertSessionHas('success');
    $response->assertSessionMissing('errors');

    expect(Booking::count())->toBe(0);
});

test('booking timeout redirects back with retry alert instead of invalid route redirect', function () {
    $this->actingAs($this->user);
    $this->bookingResponse = 'cURL error 28: Connection timed out after 10001 milliseconds';

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);
    $referer = 'http://'.$this->tenant->domains->first()->domain.'/flights/select';

    $response = $this->from($referer)->post($url, [
        'preview_issue_command' => false,
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 280.02, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => '0510',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'SEB',
                    'departure_time' => now()->addDays(2)->toDateTimeString(),
                    'arrival_time' => now()->addDays(2)->addHours(2)->toDateTimeString(),
                    'class' => 'Y',
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Abdullah',
            'email' => 'alslimany@gmail.com',
            'phone' => '911388788',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah',
                'last_name' => 'Abdullah',
                'gender' => 'M',
                'dob' => '1992-05-07',
                'passport_number' => 'HPPLRF3K',
                'passport_expiry' => '2029-10-01',
                'passport_issue_country' => 'LBY',
                'nationality' => 'LBY',
            ],
        ],
        'extras' => [
            'selected_services' => [],
        ],
    ]);

    $response->assertRedirect($referer);
    $response->assertSessionHas('error', 'Airline request timed out. Please review and confirm the booking again.');

    expect(Booking::count())->toBe(0);
});

test('domestic flights require full passport details when any passport field is entered', function () {
    $this->actingAs($this->user);

    Airport::query()->create([
        'name' => ['en' => 'Mitiga International Airport'],
        'city' => ['en' => 'Tripoli'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'MJI',
    ]);

    Airport::query()->create([
        'name' => ['en' => 'Benina International Airport'],
        'city' => ['en' => 'Benghazi'],
        'country' => ['en' => 'Libya'],
        'iata_code' => 'BEN',
    ]);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);
    $referer = 'http://'.$this->tenant->domains->first()->domain.'/flights/select';

    $response = $this->from($referer)->post($url, [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'YI0510',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'BEN',
                    'departure_time' => now()->addDays(3)->toDateTimeString(),
                    'arrival_time' => now()->addDays(3)->addHours(1)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Ishtiwy',
            'email' => 'abdullah@test.com',
            'phone' => '+218910000000',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah',
                'last_name' => 'Ishtiwy',
                'gender' => 'M',
                'dob' => '1992-05-07',
                'passport_number' => 'LB1234567',
            ],
        ],
    ]);

    $response->assertRedirect($referer);
    $response->assertSessionHasErrors([
        'passengers.0.passport_expiry',
        'passengers.0.passport_issue_country',
        'passengers.0.nationality',
    ]);
});

test('passenger names only allow letters', function () {
    $this->actingAs($this->user);

    $url = 'http://'.$this->tenant->domains->first()->domain.route('flights.store', [], false);
    $referer = 'http://'.$this->tenant->domains->first()->domain.'/flights/select';

    $response = $this->from($referer)->post($url, [
        'uuid' => Str::uuid()->toString(),
        'provider_id' => $this->provider->id,
        'reservation_type' => 'NN',
        'flight' => [
            'pricing' => ['total' => 500, 'currency' => 'LYD'],
            'segments' => [
                [
                    'flight_number' => 'YI0510',
                    'departure_airport' => 'MJI',
                    'arrival_airport' => 'BEN',
                    'departure_time' => now()->addDays(3)->toDateTimeString(),
                    'arrival_time' => now()->addDays(3)->addHours(1)->toDateTimeString(),
                ],
            ],
        ],
        'customer' => [
            'first_name' => 'Abdullah',
            'last_name' => 'Ishtiwy',
            'email' => 'abdullah@test.com',
            'phone' => '+218910000000',
        ],
        'passengers' => [
            [
                'type' => 'adult',
                'first_name' => 'Abdullah1',
                'last_name' => 'Ishtiwy-',
                'gender' => 'M',
                'dob' => '1992-05-07',
            ],
        ],
    ]);

    $response->assertRedirect($referer);
    $response->assertSessionHasErrors([
        'passengers.0.first_name',
        'passengers.0.last_name',
    ]);
});
