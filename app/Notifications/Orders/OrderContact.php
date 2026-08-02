<?php

namespace App\Notifications\Orders;

use App\Models\Tenant\Order;
use Illuminate\Notifications\Notifiable;

/**
 * A lightweight notifiable built from an Order's contact JSON.
 * Used to send notifications to the customer without requiring a User model.
 */
class OrderContact
{
    use Notifiable;

    public function __construct(
        public readonly string $email,
        public readonly string $phone,
        public readonly string $name = '',
    ) {}

    public static function fromOrder(Order $order): static
    {
        return new static(
            email: (string) ($order->contact['email'] ?? ''),
            phone: (string) ($order->contact['phone'] ?? ''),
            name: trim(($order->contact['first_name'] ?? '').' '.($order->contact['last_name'] ?? '')),
        );
    }

    /**
     * Route mail notifications to the contact's email address.
     */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    /**
     * Route WhatsApp notifications to the contact's phone number.
     */
    public function routeNotificationForWhatsApp(): string
    {
        return $this->phone;
    }

    /**
     * Identifier for notification fakes / anonymous notifiables.
     */
    public function getKey(): string
    {
        return $this->email !== '' ? $this->email : ($this->phone !== '' ? $this->phone : 'order-contact');
    }
}
