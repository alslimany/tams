<?php

namespace App\Notifications\Orders;

use App\Channels\WhatsAppMessage;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HotelBooked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderItem $item,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'whatsapp'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->order->contact['first_name'] ?? '';
        $hotelName = data_get($this->item->product_details, 'hotel_name')
            ?? data_get($this->item->item_details, 'hotel_name')
            ?? 'Hotel';
        $reference = $this->item->provider_reference ?? $this->order->number;

        return (new MailMessage)
            ->subject('Your hotel booking is confirmed — '.$this->order->number)
            ->greeting('Hello'.($name ? ' '.$name : '').',')
            ->line('Your hotel booking has been confirmed.')
            ->line('Hotel: '.$hotelName)
            ->line('Order number: '.$this->order->number)
            ->line('Booking reference: '.$reference)
            ->line('Total: '.$this->order->grand_total.' '.$this->order->currency)
            ->line('Thank you for booking with us.');
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $name = $this->order->contact['first_name'] ?? '';
        $hotelName = data_get($this->item->product_details, 'hotel_name')
            ?? data_get($this->item->item_details, 'hotel_name')
            ?? 'Hotel';
        $reference = $this->item->provider_reference ?? $this->order->number;

        $greeting = $name ? "Hello {$name}," : 'Hello,';

        $text = implode("\n", [
            $greeting,
            '🏨 Your hotel booking is confirmed.',
            "Hotel: {$hotelName}",
            "Order: {$this->order->number}",
            "Reference: {$reference}",
            "Total: {$this->order->grand_total} {$this->order->currency}",
        ]);

        return WhatsAppMessage::create($text)
            ->to($notifiable->routeNotificationForWhatsApp())
            ->withFile(route('orders.hotel-items.voucher-pdf', ['order' => $this->order->id, 'item' => $this->item->id]));
    }
}
