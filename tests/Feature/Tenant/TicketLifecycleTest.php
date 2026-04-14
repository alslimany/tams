<?php

use App\Models\Tenant;
use App\Models\Tenant\Booking;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Ticket;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $tenant = Tenant::create([
        'id' => 'ticket-'.Str::random(4),
        'company_name' => 'Ticket Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    $this->tenant = $tenant;

    tenancy()->initialize($tenant);

    $this->manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $this->provider = TenantProvider::create([
        'provider_type' => 'videcom',
        'airline_code' => 'YI',
        'airline_name' => 'Oya',
        'account_name' => 'Default',
        'is_active' => true,
        'credentials' => ['base_url' => 'http://test', 'currency' => 'LYD'],
    ]);

    $customer = Customer::create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '123',
    ]);

    $this->booking = Booking::create([
        'customer_id' => $customer->id,
        'pnr' => 'ABC123',
        'tenant_provider_id' => $this->provider->id,
        'status' => 'confirmed',
        'total_price' => 120,
        'currency' => 'LYD',
        'created_by' => $this->manager->id,
    ]);

    $providerMock = \Mockery::mock();
    $providerMock->shouldReceive('issueTicket')->andReturn('<Ticket>1234567890123</Ticket>');
    $providerMock->shouldReceive('retrieveBooking')->andReturn(<<<'XML'
<PNR RLOC="ABC123">
    <Tickets>
        <TKT Pax="1" TKTID="ELFT" TktNo="854 3220420747" Coupon="01" TktFltDate="07JUL2025" TktFltNo="YI0500" TktDepart="MJI" TktArrive="IST" TktBClass="L" IssueDate="06JUL2025" Status="F" SegNo="01" />
    </Tickets>
</PNR>
XML);
    $providerMock->shouldReceive('void')->andReturn('VOID OK');
    $providerMock->shouldReceive('refund')->andReturn('REFUND OK');

    \Mockery::mock('alias:App\Services\Airline\ProviderFactory')
        ->shouldReceive('make')
        ->andReturn($providerMock);
});

afterEach(function () {
    tenancy()->end();
    $this->tenant->delete();
    \Mockery::close();
});

test('manager can issue void and refund tickets', function () {
    $this->actingAs($this->manager);

    $baseUrl = 'http://'.$this->tenant->domains->first()->domain;

    $this->post($baseUrl.route('tickets.issue', ['booking' => $this->booking], false))
        ->assertRedirect();

    $ticket = Ticket::first();

    expect($ticket)->not->toBeNull();
    expect($ticket->status)->toBe('issued');
    expect($ticket->ticket_number)->toBe('854 3220420747');
    expect($this->booking->fresh()->status)->toBe('ticketed');
    expect($this->booking->fresh()->ticket_number)->toBe('854 3220420747');

    $this->post($baseUrl.route('tickets.void', ['booking' => $this->booking, 'ticket' => $ticket], false))
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe('voided');
    expect($this->booking->fresh()->status)->toBe('cancelled');

    $this->booking->update(['status' => 'ticketed']);
    $ticket->update(['status' => 'issued']);

    $this->post($baseUrl.route('tickets.refund', ['booking' => $this->booking, 'ticket' => $ticket], false))
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe('refunded');
    expect($this->booking->fresh()->status)->toBe('refunded');
});
