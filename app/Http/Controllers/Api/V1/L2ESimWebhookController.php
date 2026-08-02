<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ESim\RecordESimUtilisation;
use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\ESim\ESimProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class L2ESimWebhookController extends Controller
{
    public function __construct(
        protected RecordESimUtilisation $recordESimUtilisation,
        protected ESimProviderManager $providerManager,
    ) {}

    /**
     * Inbound L2 utilisation / lifecycle callback.
     *
     * Configure in L2 portal as:
     * POST {base}/agency/{tenant}/api/v1/webhooks/l2-esim
     */
    public function __invoke(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-HMAC-Signature', '');

        if (! $this->signatureIsValid($rawBody, $signature)) {
            Log::warning('L2 eSIM webhook rejected: invalid HMAC signature.');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            $decoded = json_decode($rawBody, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $alertType = (string) ($payload['alertType'] ?? '');

        if ($alertType !== '' && strcasecmp($alertType, 'Utilisation') !== 0) {
            // Acknowledge other lifecycle events without failing retries.
            return response()->json(['success' => true, 'message' => 'Ignored alert type.']);
        }

        $item = $this->recordESimUtilisation->execute($payload);

        if ($item === null) {
            Log::info('L2 eSIM utilisation webhook: no matching order item.', [
                'iccid' => $payload['iccid'] ?? null,
            ]);

            // Still 200 so L2 does not retry forever for unknown ICCIDs.
            return response()->json(['success' => true, 'message' => 'No matching eSIM item.']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Utilisation recorded.',
            'order_item_id' => $item->id,
        ]);
    }

    protected function signatureIsValid(string $rawBody, string $signature): bool
    {
        if ($signature === '' || $rawBody === '') {
            return false;
        }

        $provider = $this->providerManager->activeProvider();

        if (! $provider instanceof TenantEsimProvider) {
            return false;
        }

        $apiKey = (string) ($provider->credentials['api_key'] ?? '');

        if ($apiKey === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $apiKey, true));

        return hash_equals($expected, $signature);
    }
}
