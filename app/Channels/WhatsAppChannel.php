<?php

namespace App\Channels;

use App\Models\Tenant\NotificationLog;
use App\Services\Notifications\AdvLyClient;
use App\Services\Notifications\AdvLyException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppChannel
{
    public function __construct(
        private readonly AdvLyClient $client,
    ) {}

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        if (! $message instanceof WhatsAppMessage) {
            return;
        }

        $recipient = $message->recipient ?? $this->resolveRecipient($notifiable);

        if (blank($recipient)) {
            Log::debug('WhatsAppChannel: no recipient resolved, skipping notification', [
                'notification' => get_class($notification),
            ]);

            return;
        }

        $event = $this->resolveEventName($notification);

        try {
            if ($message->hasFile()) {
                $this->client->sendMedia($recipient, $message->content, $message->fileUrl, $message->fileMime ?? 'application/pdf');
            } else {
                $this->client->sendMessage($recipient, $message->content);
            }

            $this->log($notifiable, $notification, $event, $recipient, $message->content, 'sent');
        } catch (AdvLyException $e) {
            if ($e->isNoFeature()) {
                Log::warning('WhatsAppChannel: account has no WhatsApp feature', [
                    'notification' => get_class($notification),
                    'recipient' => $recipient,
                ]);

                $this->log($notifiable, $notification, $event, $recipient, $message->content, 'skipped', $e->apiMessage);

                return;
            }

            Log::error('WhatsAppChannel: failed to send message', [
                'notification' => get_class($notification),
                'recipient' => $recipient,
                'error' => $e->apiMessage,
            ]);

            $this->log($notifiable, $notification, $event, $recipient, $message->content, 'failed', $e->apiMessage);
        }
    }

    private function resolveRecipient(mixed $notifiable): ?string
    {
        if (method_exists($notifiable, 'routeNotificationForWhatsApp')) {
            return $notifiable->routeNotificationForWhatsApp();
        }

        return $notifiable->phone ?? $notifiable->mobile ?? null;
    }

    private function resolveEventName(Notification $notification): string
    {
        return Str::snake(class_basename($notification));
    }

    private function log(
        mixed $notifiable,
        Notification $notification,
        string $event,
        string $recipient,
        string $message,
        string $status,
        ?string $error = null,
    ): void {
        try {
            $log = [
                'channel' => 'whatsapp',
                'event' => $event,
                'recipient' => $recipient,
                'message' => $message,
                'status' => $status,
                'error' => $error,
            ];

            if (is_object($notifiable) && method_exists($notifiable, 'getKey')) {
                $log['notifiable_type'] = get_class($notifiable);
                $log['notifiable_id'] = $notifiable->getKey();
            }

            NotificationLog::create($log);
        } catch (\Throwable $e) {
            // Logging must never break the notification flow
            Log::warning('WhatsAppChannel: failed to write notification log', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
