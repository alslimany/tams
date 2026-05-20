<?php

namespace App\Notifications\Orders;

use App\Channels\WhatsAppMessage;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketVoided extends Notification implements ShouldQueue
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
        $ticketNumber = $this->item->ticket_number ?? $this->item->provider_reference ?? $this->order->number;

        return (new MailMessage)
            ->subject('Your ticket has been voided — '.$this->order->number)
            ->greeting('Hello'.($name ? ' '.$name : '').',')
            ->line('Your flight ticket has been voided successfully.')
            ->line('Order number: '.$this->order->number)
            ->line('Ticket number: '.$ticketNumber)
            ->line('The refund amount has been returned to your account.')
            ->line('Thank you for your understanding.');
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $name = $this->order->contact['first_name'] ?? '';
        $ticketNumber = $this->item->ticket_number ?? $this->item->provider_reference ?? $this->order->number;

        $greeting = $name ? "Hello {$name}," : 'Hello,';

        $text = implode("\n", [
            $greeting,
            '🔁 Your flight ticket has been voided.',
            "Order: {$this->order->number}",
            "Ticket: {$ticketNumber}",
            'The refund has been processed.',
        ]);

        return WhatsAppMessage::create($text)
            ->to($notifiable->routeNotificationForWhatsApp())
            ->withFile(route('orders.summary-pdf', ['order' => $this->order->id]));
    }
}
