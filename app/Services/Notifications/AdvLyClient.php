<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdvLyClient
{
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://adv.ly/api/v1',
    ) {}

    /**
     * Send a WhatsApp message via the AdvLy API.
     *
     * @throws AdvLyException
     */
    public function sendMessage(string $recipient, string $message): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->connectTimeout(5)
                ->retry(2, 500, function (\Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError());
                })
                ->post("{$this->baseUrl}/send-message", [
                    'recipient' => $recipient,
                    'message' => $message,
                ]);

            $body = $response->json();
            $status = $body['status'] ?? false;
            $apiMessage = $body['message'] ?? 'UNKNOWN_EXCEPTION';

            if (! $response->successful() || ! $status) {
                throw new AdvLyException($apiMessage, $response->status());
            }

            return true;
        } catch (AdvLyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('AdvLy: unexpected error sending WhatsApp message', [
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            throw new AdvLyException('UNKNOWN_EXCEPTION', 0, $e);
        }
    }

    /**
     * Send a WhatsApp media file via the AdvLy API.
     *
     * @throws AdvLyException
     */
    public function sendMedia(string $recipient, string $message, string $fileUrl, string $fileMime = 'application/pdf'): bool
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(15)
                ->connectTimeout(5)
                ->retry(2, 500, function (\Throwable $e): bool {
                    return $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError());
                })
                ->post("{$this->baseUrl}/send-whatsapp-media", [
                    'recipient' => $recipient,
                    'message' => $message,
                    'file_url' => $fileUrl,
                    'file_mime' => $fileMime,
                ]);

            $body = $response->json();
            $status = $body['status'] ?? false;
            $apiMessage = $body['message'] ?? 'UNKNOWN_EXCEPTION';

            if (! $response->successful() || ! $status) {
                throw new AdvLyException($apiMessage, $response->status());
            }

            return true;
        } catch (AdvLyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('AdvLy: unexpected error sending WhatsApp media', [
                'recipient' => $recipient,
                'file_url' => $fileUrl,
                'error' => $e->getMessage(),
            ]);

            throw new AdvLyException('UNKNOWN_EXCEPTION', 0, $e);
        }
    }
}
