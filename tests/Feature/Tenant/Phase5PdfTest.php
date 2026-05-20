<?php

use App\Channels\WhatsAppChannel;
use App\Channels\WhatsAppMessage;
use App\Models\Tenant;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\User;
use App\Notifications\Orders\HotelBooked;
use App\Notifications\Orders\OrderContact;
use App\Notifications\Orders\PolicyIssued;
use App\Notifications\Orders\TicketCancelled;
use App\Notifications\Orders\TicketIssued;
use App\Notifications\Orders\TicketVoided;
use App\Services\Notifications\AdvLyClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Shared tenant state
// ---------------------------------------------------------------------------

/** @var array<string, mixed> $state */
$state = [];

beforeEach(function () {
    global $state;

    $tenant = Tenant::create([
        'id' => 'phase5-pdf-'.Str::random(4),
        'company_name' => 'Phase 5 PDF Tenant',
        'status' => 'active',
        'subscription_status' => 'trial',
    ]);

    $tenant->domains()->create(['domain' => $tenant->id.'.localhost']);
    tenancy()->initialize($tenant);

    $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $state['tenant'] = $tenant;
    $state['user'] = $user;
});

afterEach(function () {
    global $state;

    tenancy()->end();
    $state['tenant']->delete();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function p5MakeOrder(array $contact = []): Order
{
    global $state;

    return Order::create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-P5-'.Str::upper(Str::random(6)),
        'status' => 'issued',
        'subtotal' => 500,
        'tax_total' => 0,
        'grand_total' => 500,
        'amount_paid' => 500,
        'amount_refunded' => 0,
        'currency' => 'LYD',
        'payment_method' => 'wallet',
        'contact' => array_merge([
            'first_name' => 'Ali',
            'last_name' => 'Hassan',
            'email' => 'ali@example.com',
            'phone' => '+218912345678',
        ], $contact),
    ]);
}

function p5MakeItem(Order $order, string $type = 'flight', array $extra = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'type' => $type,
        'product_type' => $type,
        'product_subtype' => $type,
        'provider' => 'test',
        'ticket_number' => 'TKT-P5-001',
        'provider_reference' => 'REF-P5-001',
        'status' => 'issued',
        'price' => 500,
        'net_fare' => 500,
        'taxes' => 0,
        'total' => 500,
        'total_amount' => 500,
        'currency' => 'LYD',
        'commission_amount' => 0,
        'paid' => 500,
        'remaining' => 0,
        'item_details' => [],
        'product_details' => [],
    ], $extra));
}

// ---------------------------------------------------------------------------
// WhatsAppMessage file support
// ---------------------------------------------------------------------------

test('WhatsAppMessage withFile sets fileUrl and fileMime', function () {
    $message = WhatsAppMessage::create('Hello')
        ->withFile('https://example.com/ticket.pdf', 'application/pdf');

    expect($message->hasFile())->toBeTrue()
        ->and($message->fileUrl)->toBe('https://example.com/ticket.pdf')
        ->and($message->fileMime)->toBe('application/pdf');
});

test('WhatsAppMessage hasFile returns false when no file set', function () {
    $message = WhatsAppMessage::create('Hello');

    expect($message->hasFile())->toBeFalse();
});

test('WhatsAppMessage withFile defaults mime to application/pdf', function () {
    $message = WhatsAppMessage::create('Hello')->withFile('https://example.com/doc.pdf');

    expect($message->fileMime)->toBe('application/pdf');
});

// ---------------------------------------------------------------------------
// AdvLyClient sendMedia
// ---------------------------------------------------------------------------

test('AdvLyClient sendMedia sends to send-whatsapp-media endpoint', function () {
    Http::fake([
        'https://adv.ly/api/v1/send-whatsapp-media' => Http::response([
            'status' => true,
            'message' => 'Media sent',
        ], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $result = $client->sendMedia('+218912345678', 'Your ticket', 'https://example.com/ticket.pdf');

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'send-whatsapp-media')
        && $request['recipient'] === '+218912345678'
        && $request['message'] === 'Your ticket'
        && $request['file_url'] === 'https://example.com/ticket.pdf'
        && $request['file_mime'] === 'application/pdf'
    );
});

test('AdvLyClient sendMedia throws AdvLyException on API error', function () {
    Http::fake([
        'https://adv.ly/api/v1/send-whatsapp-media' => Http::response([
            'status' => false,
            'message' => 'UNKNOWN_EXCEPTION',
        ], 200),
    ]);

    $client = new AdvLyClient('test-token');

    expect(fn () => $client->sendMedia('+218912345678', 'msg', 'https://example.com/file.pdf'))
        ->toThrow(\App\Services\Notifications\AdvLyException::class);
});

// ---------------------------------------------------------------------------
// WhatsAppChannel sends media when message has file
// ---------------------------------------------------------------------------

test('WhatsAppChannel calls sendMedia when message has file', function () {
    global $state;

    Http::fake([
        'https://adv.ly/api/v1/send-whatsapp-media' => Http::response(['status' => true, 'message' => 'ok'], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    $order = p5MakeOrder();
    $item = p5MakeItem($order);
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);

    $channel->send($contact, $notification);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'send-whatsapp-media'));

    expect(NotificationLog::where('status', 'sent')->where('channel', 'whatsapp')->exists())->toBeTrue();
});

test('WhatsAppChannel calls sendMessage when message has no file', function () {
    global $state;

    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response(['status' => true, 'message' => 'ok'], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    // TicketVoided attaches order summary PDF — use a plain message to test text-only path
    $notifiable = new OrderContact('test@example.com', '+218912345678');
    $notification = new class extends \Illuminate\Notifications\Notification
    {
        public function via(object $notifiable): array
        {
            return ['whatsapp'];
        }

        public function toWhatsApp(object $notifiable): WhatsAppMessage
        {
            return WhatsAppMessage::create('Plain text only');
        }
    };

    $channel->send($notifiable, $notification);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'send-message'));
});

// ---------------------------------------------------------------------------
// Notifications attach correct PDF URLs
// ---------------------------------------------------------------------------

test('TicketIssued toWhatsApp attaches flight ticket PDF URL', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'flight');
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message->hasFile())->toBeTrue()
        ->and($message->fileUrl)->toContain('ticket-pdf');
});

test('HotelBooked toWhatsApp attaches hotel voucher PDF URL', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'hotel');
    $notification = new HotelBooked($order, $item);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message->hasFile())->toBeTrue()
        ->and($message->fileUrl)->toContain('voucher-pdf');
});

test('PolicyIssued toWhatsApp attaches insurance policy PDF URL', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'insurance');
    $notification = new PolicyIssued($order, $item);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message->hasFile())->toBeTrue()
        ->and($message->fileUrl)->toContain('policy-pdf');
});

test('TicketVoided toWhatsApp attaches order summary PDF URL', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order);
    $notification = new TicketVoided($order, $item);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message->hasFile())->toBeTrue()
        ->and($message->fileUrl)->toContain('summary-pdf');
});

test('TicketCancelled toWhatsApp attaches order summary PDF URL', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order);
    $notification = new TicketCancelled($order, $item, 100.0);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message->hasFile())->toBeTrue()
        ->and($message->fileUrl)->toContain('summary-pdf');
});

// ---------------------------------------------------------------------------
// PDF routes return 200 with correct content-type
// ---------------------------------------------------------------------------

test('flight ticket PDF route returns PDF response', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'flight');

    $this->actingAs($state['user'])
        ->get(route('orders.flight-items.ticket-pdf', ['order' => $order->id, 'item' => $item->id]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

test('hotel voucher PDF route returns PDF response', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'hotel');

    $this->actingAs($state['user'])
        ->get(route('orders.hotel-items.voucher-pdf', ['order' => $order->id, 'item' => $item->id]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

test('insurance policy PDF route returns PDF response', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'insurance');

    $this->actingAs($state['user'])
        ->get(route('orders.insurance-items.policy-pdf', ['order' => $order->id, 'item' => $item->id]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

test('order summary PDF route returns PDF response', function () {
    global $state;

    $order = p5MakeOrder();
    p5MakeItem($order, 'flight');
    p5MakeItem($order, 'hotel');

    $this->actingAs($state['user'])
        ->get(route('orders.summary-pdf', ['order' => $order->id]))
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
});

test('hotel voucher PDF route returns 404 for flight item', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'flight');

    $this->actingAs($state['user'])
        ->get(route('orders.hotel-items.voucher-pdf', ['order' => $order->id, 'item' => $item->id]))
        ->assertStatus(404);
});

test('insurance policy PDF route returns 404 for flight item', function () {
    global $state;

    $order = p5MakeOrder();
    $item = p5MakeItem($order, 'flight');

    $this->actingAs($state['user'])
        ->get(route('orders.insurance-items.policy-pdf', ['order' => $order->id, 'item' => $item->id]))
        ->assertStatus(404);
});
