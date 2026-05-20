<?php

namespace App\Notifications\Orders;

use App\Channels\WhatsAppMessage;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PolicyIssued extends Notification implements ShouldQueue
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
        $policyNumber = $this->item->ticket_number
            ?? $this->item->provider_reference
            ?? $this->order->number;
        $type = ucfirst((string) ($this->item->product_type ?? 'insurance'));

        return (new MailMessage)
            ->subject('Your insurance policy has been issued — '.$this->order->number)
            ->greeting('Hello'.($name ? ' '.$name : '').',')
            ->line("Your {$type} insurance policy has been issued successfully.")
            ->line('Order number: '.$this->order->number)
            ->line('Policy number: '.$policyNumber)
            ->line('Total: '.$this->order->grand_total.' '.$this->order->currency)
            ->line('Thank you for choosing us.');
    }

    public function toWhatsApp(object $notifiable): WhatsAppMessage
    {
        $name = $this->order->contact['first_name'] ?? '';
        $policyNumber = $this->item->ticket_number
            ?? $this->item->provider_reference
            ?? $this->order->number;
        $type = ucfirst((string) ($this->item->product_type ?? 'insurance'));

        $greeting = $name ? "Hello {$name}," : 'Hello,';

        $text = implode("\n", [
            $greeting,
            "🛡️ Your {$type} policy has been issued.",
            "Order: {$this->order->number}",
            "Policy: {$policyNumber}",
            "Total: {$this->order->grand_total} {$this->order->currency}",
        ]);

        return WhatsAppMessage::create($text)
            ->to($notifiable->routeNotificationForWhatsApp())
            ->withFile(route('orders.insurance-items.policy-pdf', ['order' => $this->order->id, 'item' => $this->item->id]));
    }
}
