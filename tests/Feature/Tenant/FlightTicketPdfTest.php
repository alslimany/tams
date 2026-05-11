<?php

use App\Models\Tenant;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'ticket-pdf-'.Str::random(4),
        'company_name' => 'Ticket PDF Agency',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);

    tenancy()->initialize($tenant);

    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
    ]);

    $order = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'number' => 'PDF0001',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
        'payment_reference' => 'PAY-123',
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'type' => 'flight',
        'product_type' => 'flight',
        'product_subtype' => 'oneway',
        'provider' => 'videcom',
        'provider_reference' => 'AAJ6DU',
        'ticket_number' => '854 3220420747',
        'item_details' => [
            'rloc' => 'AAJ6DU',
            'iata' => 'YI',
            'currency' => 'LYD',
            'total_price' => 100,
            'booked_by' => 'PDF TEST USER',
            'itineraries' => [[
                'itinerary_id' => '1',
                'airline_id' => 'YI',
                'flight_number' => '0500',
                'class' => 'Y',
                'cabin' => 'Y',
                'class_band_display_name' => 'Y',
                'date' => '2026-04-30',
                'from' => 'MJI',
                'to' => 'IST',
                'departure' => '20:00:00',
                'arrival' => '22:00:00',
                'status' => 'HK',
            ]],
            'passengers' => [[
                'id' => '1',
                'title' => 'MR',
                'first_name' => 'JOHN',
                'last_name' => 'DOE',
            ]],
            'tickets' => [[
                'passenger_id' => '1',
                'ticket_number' => '854 3220420747',
                'segment_number' => '01',
                'coupon' => '1',
                'flight_number' => 'YI0500',
                'from' => 'MJI',
                'to' => 'IST',
                'status' => 'OK',
                'hold_weight' => '20K',
            ]],
            'contacts' => [[
                'type' => 'E',
                'value' => 'john@example.com',
            ]],
            'payments' => [[
                'reference' => 'PAY-123',
            ]],
        ],
        'price' => 100,
        'taxes' => 0,
        'total' => 100,
        'currency' => 'LYD',
        'status' => 'issued',
    ]);

    $state = compact('tenant', 'user', 'order', 'item');
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

test('flight ticket pdf is returned inline for a flight order item', function () {
    global $state;

    Pdf::fake();

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('orders.flight-items.ticket-pdf', [
        'order' => $state['order'],
        'item' => $state['item'],
    ], false))->assertSuccessful();

    Pdf::assertRespondedWithPdf(function (PdfBuilder $pdf): bool {
        return $pdf->viewName === 'pdf.flight-ticket'
            && $pdf->downloadName === 'flight-ticket-AAJ6DU.pdf'
            && $pdf->isInline()
            && $pdf->contains(['Flight Ticket', 'AAJ6DU', 'JOHN DOE']);
    });
});

test('pdf browsershot binary paths are configured from environment', function () {
    config()->set('laravel-pdf.browsershot.node_binary', '/usr/bin/node');
    config()->set('laravel-pdf.browsershot.npm_binary', '/usr/bin/npm');
    config()->set('laravel-pdf.browsershot.include_path', '/usr/bin:/usr/local/bin');
    config()->set('laravel-pdf.browsershot.chrome_path', '/usr/bin/chromium');
    config()->set('laravel-pdf.browsershot.node_modules_path', '/var/www/app/node_modules');
    config()->set('laravel-pdf.browsershot.no_sandbox', true);

    expect(config('laravel-pdf.browsershot.node_binary'))->toBe('/usr/bin/node')
        ->and(config('laravel-pdf.browsershot.npm_binary'))->toBe('/usr/bin/npm')
        ->and(config('laravel-pdf.browsershot.include_path'))->toBe('/usr/bin:/usr/local/bin')
        ->and(config('laravel-pdf.browsershot.chrome_path'))->toBe('/usr/bin/chromium')
        ->and(config('laravel-pdf.browsershot.node_modules_path'))->toBe('/var/www/app/node_modules')
        ->and(config('laravel-pdf.browsershot.no_sandbox'))->toBeTrue();
});

test('flight ticket pdf returns not found when item does not belong to order', function () {
    global $state;

    $otherOrder = Order::query()->create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'PDF0002',
        'status' => 'issued',
        'issued_at' => now(),
        'subtotal' => 100,
        'tax_total' => 0,
        'grand_total' => 100,
        'amount_paid' => 100,
        'currency' => 'LYD',
        'payment_method' => 'airline_token',
    ]);

    $this->actingAs($state['user']);

    $baseUrl = 'http://'.$state['tenant']->domains->first()->domain;

    $this->get($baseUrl.route('orders.flight-items.ticket-pdf', [
        'order' => $otherOrder,
        'item' => $state['item'],
    ], false))->assertNotFound();
});
