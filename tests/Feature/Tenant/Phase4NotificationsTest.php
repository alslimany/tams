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
use App\Services\Notifications\AdvLyException;
use Illuminate\Notifications\Messages\MailMessage;
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
        'id' => 'phase4-notif-'.Str::random(4),
        'company_name' => 'Phase 4 Notification Tenant',
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

function makeNotifOrder(array $contact = []): Order
{
    global $state;

    return Order::create([
        'owner_type' => User::class,
        'owner_id' => $state['user']->id,
        'number' => 'ORD-TEST-'.Str::upper(Str::random(6)),
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

function makeNotifOrderItem(Order $order, string $type = 'flight', array $extra = []): OrderItem
{
    return OrderItem::create(array_merge([
        'order_id' => $order->id,
        'type' => $type,
        'product_type' => $type,
        'product_subtype' => $type,
        'provider' => 'test',
        'ticket_number' => 'TKT-001',
        'provider_reference' => 'REF-001',
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
// OrderContact
// ---------------------------------------------------------------------------

test('OrderContact::fromOrder builds from order contact JSON', function () {
    global $state;

    $order = makeNotifOrder();
    $contact = OrderContact::fromOrder($order);

    expect($contact->email)->toBe('ali@example.com')
        ->and($contact->phone)->toBe('+218912345678')
        ->and($contact->name)->toBe('Ali Hassan')
        ->and($contact->routeNotificationForMail())->toBe('ali@example.com')
        ->and($contact->routeNotificationForWhatsApp())->toBe('+218912345678');
});

test('OrderContact::fromOrder handles missing fields gracefully', function () {
    global $state;

    $order = makeNotifOrder(['email' => '', 'phone' => '', 'first_name' => '', 'last_name' => '']);
    $contact = OrderContact::fromOrder($order);

    expect($contact->email)->toBe('')
        ->and($contact->phone)->toBe('')
        ->and($contact->name)->toBe('');
});

// ---------------------------------------------------------------------------
// TicketIssued notification
// ---------------------------------------------------------------------------

test('TicketIssued sends via mail and whatsapp channels', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);

    expect($notification->via(new stdClass))->toContain('mail', 'whatsapp');
});

test('TicketIssued toMail contains order number and ticket number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);
    $mail = $notification->toMail(new stdClass);

    expect($mail)->toBeInstanceOf(MailMessage::class);

    $lines = collect($mail->introLines)->implode(' ');
    expect($lines)->toContain($order->number)
        ->and($lines)->toContain($item->ticket_number);
});

test('TicketIssued toWhatsApp contains order number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message)->toBeInstanceOf(WhatsAppMessage::class)
        ->and($message->content)->toContain($order->number);
});

// ---------------------------------------------------------------------------
// TicketVoided notification
// ---------------------------------------------------------------------------

test('TicketVoided sends via mail and whatsapp channels', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketVoided($order, $item);

    expect($notification->via(new stdClass))->toContain('mail', 'whatsapp');
});

test('TicketVoided toMail contains order number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketVoided($order, $item);
    $mail = $notification->toMail(new stdClass);

    $lines = collect($mail->introLines)->implode(' ');
    expect($lines)->toContain($order->number);
});

// ---------------------------------------------------------------------------
// TicketCancelled notification
// ---------------------------------------------------------------------------

test('TicketCancelled sends via mail and whatsapp channels', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketCancelled($order, $item);

    expect($notification->via(new stdClass))->toContain('mail', 'whatsapp');
});

test('TicketCancelled toMail contains order number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketCancelled($order, $item);
    $mail = $notification->toMail(new stdClass);

    $lines = collect($mail->introLines)->implode(' ');
    expect($lines)->toContain($order->number);
});

// ---------------------------------------------------------------------------
// HotelBooked notification
// ---------------------------------------------------------------------------

test('HotelBooked sends via mail and whatsapp channels', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order, 'hotel');
    $notification = new HotelBooked($order, $item);

    expect($notification->via(new stdClass))->toContain('mail', 'whatsapp');
});

test('HotelBooked toMail contains order number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order, 'hotel');
    $notification = new HotelBooked($order, $item);
    $mail = $notification->toMail(new stdClass);

    $lines = collect($mail->introLines)->implode(' ');
    expect($lines)->toContain($order->number);
});

// ---------------------------------------------------------------------------
// PolicyIssued notification
// ---------------------------------------------------------------------------

test('PolicyIssued sends via mail and whatsapp channels', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order, 'insurance');
    $notification = new PolicyIssued($order, $item);

    expect($notification->via(new stdClass))->toContain('mail', 'whatsapp');
});

test('PolicyIssued toMail contains order number and policy number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order, 'insurance');
    $notification = new PolicyIssued($order, $item);
    $mail = $notification->toMail(new stdClass);

    $lines = collect($mail->introLines)->implode(' ');
    expect($lines)->toContain($order->number)
        ->and($lines)->toContain($item->ticket_number);
});

test('PolicyIssued toWhatsApp contains order number', function () {
    global $state;

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order, 'insurance');
    $notification = new PolicyIssued($order, $item);
    $contact = OrderContact::fromOrder($order);
    $message = $notification->toWhatsApp($contact);

    expect($message)->toBeInstanceOf(WhatsAppMessage::class)
        ->and($message->content)->toContain($order->number);
});

// ---------------------------------------------------------------------------
// AdvLyClient
// ---------------------------------------------------------------------------

test('AdvLyClient sends message successfully', function () {
    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response([
            'status' => true,
            'message' => 'Message sent',
        ], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $result = $client->sendMessage('+218912345678', 'Hello test');

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'send-message')
        && $request['recipient'] === '+218912345678'
        && $request['message'] === 'Hello test'
    );
});

test('AdvLyClient throws AdvLyException on API error status', function () {
    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response([
            'status' => false,
            'message' => 'ERROR_NO_FEATURE',
        ], 200),
    ]);

    $client = new AdvLyClient('test-token');

    expect(fn () => $client->sendMessage('+218912345678', 'Hello'))
        ->toThrow(AdvLyException::class);
});

test('AdvLyClient throws AdvLyException on HTTP 500', function () {
    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response([], 500),
    ]);

    $client = new AdvLyClient('test-token');

    expect(fn () => $client->sendMessage('+218912345678', 'Hello'))
        ->toThrow(AdvLyException::class);
});

test('AdvLyException isNoFeature returns true for ERROR_NO_FEATURE', function () {
    $exception = new AdvLyException('ERROR_NO_FEATURE', 200);

    expect($exception->isNoFeature())->toBeTrue();
});

test('AdvLyException isNoFeature returns false for other errors', function () {
    $exception = new AdvLyException('UNKNOWN_EXCEPTION', 500);

    expect($exception->isNoFeature())->toBeFalse();
});

// ---------------------------------------------------------------------------
// WhatsAppChannel
// ---------------------------------------------------------------------------

test('WhatsAppChannel sends message and logs sent status', function () {
    global $state;

    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response(['status' => true, 'message' => 'Message sent'], 200),
        'https://adv.ly/api/v1/send-whatsapp-media' => Http::response(['status' => true, 'message' => 'Media sent'], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);

    $channel->send($contact, $notification);

    expect(NotificationLog::where('status', 'sent')->where('channel', 'whatsapp')->exists())->toBeTrue();
});

test('WhatsAppChannel logs skipped when ERROR_NO_FEATURE', function () {
    global $state;

    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response(['status' => false, 'message' => 'ERROR_NO_FEATURE'], 200),
        'https://adv.ly/api/v1/send-whatsapp-media' => Http::response(['status' => false, 'message' => 'ERROR_NO_FEATURE'], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);

    $channel->send($contact, $notification);

    expect(NotificationLog::where('status', 'skipped')->where('channel', 'whatsapp')->exists())->toBeTrue();
});

test('WhatsAppChannel logs failed on AdvLy API error', function () {
    global $state;

    Http::fake([
        'https://adv.ly/api/v1/send-message' => Http::response([
            'status' => false,
            'message' => 'UNKNOWN_EXCEPTION',
        ], 200),
    ]);

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    $order = makeNotifOrder();
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);

    $channel->send($contact, $notification);

    expect(NotificationLog::where('status', 'failed')->where('channel', 'whatsapp')->exists())->toBeTrue();
});

test('WhatsAppChannel skips when no recipient resolved', function () {
    global $state;

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    $order = makeNotifOrder(['phone' => '']);
    $item = makeNotifOrderItem($order);
    $notification = new TicketIssued($order, $item);
    $contact = OrderContact::fromOrder($order);

    // Should not throw and should not log anything
    $channel->send($contact, $notification);

    expect(NotificationLog::where('channel', 'whatsapp')->count())->toBe(0);
});

test('WhatsAppChannel skips notification without toWhatsApp method', function () {
    global $state;

    Http::fake();

    $client = new AdvLyClient('test-token');
    $channel = new WhatsAppChannel($client);

    $notifiable = new OrderContact('test@example.com', '+218912345678');

    // A notification without toWhatsApp
    $notification = new class extends \Illuminate\Notifications\Notification
    {
        public function via(object $notifiable): array
        {
            return ['whatsapp'];
        }
    };

    $channel->send($notifiable, $notification);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Notification dispatch is queued (afterCommit)
// ---------------------------------------------------------------------------

test('notifications implement ShouldQueue', function () {
    expect(TicketIssued::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(TicketVoided::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(TicketCancelled::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(HotelBooked::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class)
        ->and(PolicyIssued::class)->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);
});
