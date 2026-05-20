<?php

namespace App\Notifications\Orders;

use App\Channels\WhatsAppMessage;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderItem $item,
        public readonly float $refundAmount = 0.0,
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

        $mail = (new MailMessage)
            ->subject('Your ticket cancellation — '.$this->order->number)
            ->greeting('Hello'.($name ? ' '.$name : '').',')
            ->line('Your flight ticket cancellation has been processed.')
            ->line('Order number: '.$this->order->number)
            ->line('Ticket number: '.$ticketNumber);

        if ($this->refundAmount > 0) {
            $mail->line('Refund amount: '.$this->refundAmount.' '.$this->order->currency);
        }

        return $mail->line('Thank you for your understanding.');
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $name = $this->order->contact['first_name'] ?? '';
        $ticketNumber = $this->item->ticket_number ?? $this->item->provider_reference ?? $this->order->number;

        $greeting = $name ? "Hello {$name}," : 'Hello,';

        $lines = [
            $greeting,
            '❌ Your flight ticket has been cancelled.',
            "Order: {$this->order->number}",
            "Ticket: {$ticketNumber}",
        ];

        if ($this->refundAmount > 0) {
            $lines[] = "Refund: {$this->refundAmount} {$this->order->currency}";
        }

        return WhatsAppMessage::create(implode("\n", $lines))
            ->to($notifiable->routeNotificationForWhatsApp())
            ->withFile(route('orders.summary-pdf', ['order' => $this->order->id]));
    }
}
