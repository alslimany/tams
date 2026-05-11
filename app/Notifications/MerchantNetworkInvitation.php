<?php

namespace App\Notifications;

use App\Models\NetworkMembership;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MerchantNetworkInvitation extends Notification
{
    use Queueable;

    public function __construct(
        public NetworkMembership $membership,
        public Tenant $agency,
        public string $joinUrl,
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
        $providerCount = $this->membership->providerAllocations()->count();

        return (new MailMessage)
            ->subject('Agency network invitation from '.$this->agency->company_name)
            ->greeting('Hello'.($this->membership->merchant_contact_name ? ' '.$this->membership->merchant_contact_name : '').',')
            ->line($this->agency->company_name.' invited you to join its agency network.')
            ->line('Agency number: '.$this->agency->agency_number)
            ->line('Invitation code: '.$this->membership->invitation_code)
            ->line('Offered provider APIs: '.$providerCount)
            ->action('Open Network Settings', $this->joinUrl)
            ->line('After opening the page, enter the invitation code and enable only the provider APIs you want to use.');
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
            'agency_tenant_id' => $this->agency->id,
            'agency_number' => $this->agency->agency_number,
            'invitation_code' => $this->membership->invitation_code,
        ];
    }
}
