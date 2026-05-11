<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgencyCreatedConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $agencyName,
        public string $agencyNumber,
        public string $ownerName,
        public string $ownerEmail,
        public string $domain,
        public string $loginUrl,
    ) {
        $this->afterCommit();
    }

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
        return (new MailMessage)
            ->subject('Your Booknow agency workspace is ready')
            ->greeting('Hello '.$this->ownerName.',')
            ->line('Your agency workspace has been created successfully.')
            ->line('Agency: '.$this->agencyName)
            ->line('Agency number: '.$this->agencyNumber)
            ->line('Domain: '.$this->domain)
            ->line('Owner email: '.$this->ownerEmail)
            ->action('Sign in to your agency', $this->loginUrl)
            ->line('Use the password you created during registration to sign in.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'agency_name' => $this->agencyName,
            'agency_number' => $this->agencyNumber,
            'owner_email' => $this->ownerEmail,
            'domain' => $this->domain,
            'login_url' => $this->loginUrl,
        ];
    }
}
