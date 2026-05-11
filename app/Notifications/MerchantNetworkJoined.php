<?php

namespace App\Notifications;

use App\Models\NetworkMembership;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MerchantNetworkJoined extends Notification
{
    use Queueable;

    public function __construct(
        public NetworkMembership $membership,
        public Tenant $merchant,
        public string $networkUrl,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $enabledCount = $this->membership->providerAllocations()
            ->where('is_enabled_by_merchant', true)
            ->count();

        return (new MailMessage)
            ->subject($this->merchant->company_name.' joined your agency network')
            ->greeting('Hello,')
            ->line($this->merchant->company_name.' accepted your agency network invitation.')
            ->line('Enabled provider APIs: '.$enabledCount)
            ->action('View Agency Network', $this->networkUrl)
            ->line('You can suspend or revoke merchant access from Network Settings.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'network_membership_id' => $this->membership->id,
            'merchant_tenant_id' => $this->merchant->id,
            'merchant_agency_number' => $this->merchant->agency_number,
        ];
    }
}
