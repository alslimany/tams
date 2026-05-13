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
        public string $agencyPath,
        public string $ownerName,
        public string $ownerEmail,
        public string $workspaceUrl,
        public string $status = 'frozen',
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Your Booknow agency registration is under review')
            ->greeting('Hello '.$this->ownerName.',');

        if ($this->status === 'frozen') {
            $message
                ->line('Thank you for registering your agency with Booknow.')
                ->line('Your registration has been received and is currently under review.')
                ->line('Agency: '.$this->agencyName)
                ->line('Agency Number: '.$this->agencyNumber)
                ->line('Agency ID: '.$this->agencyPath)
                ->line('Owner Email: '.$this->ownerEmail)
                ->line('')
                ->line('Your submitted documents (commercial register and passport) are being verified.')
                ->line('You will receive another email once your agency has been activated.')
                ->line('')
                ->line('Your workspace will be available at: '.$this->workspaceUrl)
                ->line('(You will be able to log in after activation)');
        } else {
            $message
                ->line('Your agency workspace has been created successfully.')
                ->line('Agency: '.$this->agencyName)
                ->line('Agency Number: '.$this->agencyNumber)
                ->line('Agency ID: '.$this->agencyPath)
                ->line('Owner Email: '.$this->ownerEmail)
                ->action('Sign in to your agency', $this->workspaceUrl)
                ->line('Use the password you created during registration to sign in.');
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'agency_name' => $this->agencyName,
            'agency_number' => $this->agencyNumber,
            'agency_path' => $this->agencyPath,
            'owner_email' => $this->ownerEmail,
            'workspace_url' => $this->workspaceUrl,
            'status' => $this->status,
        ];
    }
}
