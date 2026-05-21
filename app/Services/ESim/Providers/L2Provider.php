<?php

namespace App\Services\ESim\Providers;

use App\Contracts\ESim\ESimProviderInterface;
use App\DTOs\ESim\ESimOrderRequest;
use App\DTOs\ESim\ESimOrderResult;
use App\DTOs\ESim\ESimPackage;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\ESim\ESimApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class L2Provider implements ESimProviderInterface
{
    private string $baseUrl;

    private string $apiKey;

    private string $clientSecret;

    public function __construct(protected TenantEsimProvider $config)
    {
        $this->baseUrl = rtrim((string) ($config->credentials['base_url'] ?? 'https://l2travelesim.com/api/v2'), '/');
        $this->apiKey = (string) ($config->credentials['api_key'] ?? '');
        $this->clientSecret = (string) ($config->credentials['client_secret'] ?? '');
    }

    /** @return ESimPackage[] */
    public function catalogue(array $filters = []): array
    {
        $response = $this->get('/packages', array_filter([
            'country' => $filters['country'] ?? null,
        ]));

        $packages = [];

        foreach ($response as $item) {
            $packages[] = new ESimPackage(
                id: (string) ($item['id'] ?? $item['package_id'] ?? ''),
                name: (string) ($item['name'] ?? ''),
                country: (string) ($item['country'] ?? $filters['country'] ?? ''),
                dataMb: (int) ($item['data_mb'] ?? ($item['data'] ?? 0)),
                validityDays: (int) ($item['validity'] ?? $item['validity_days'] ?? 0),
                price: (float) ($item['price'] ?? 0),
                currency: (string) ($item['currency'] ?? 'USD'),
                provider: 'l2',
            );
        }

        return $packages;
    }

    /** @return array<string, mixed> */
    public function bundles(string $packageId): array
    {
        return $this->get("/packages/{$packageId}/bundles");
    }

    public function processOrder(ESimOrderRequest $request): ESimOrderResult
    {
        $payload = array_filter([
            'package_id' => $request->packageId,
            'quantity' => $request->quantity,
            'customer_email' => $request->customerEmail,
            'customer_name' => $request->customerName,
        ]);

        $response = $this->post('/orders', $payload);

        return new ESimOrderResult(
            orderId: (string) ($response['order_id'] ?? $response['id'] ?? ''),
            iccid: (string) ($response['iccid'] ?? ''),
            activationCode: (string) ($response['activation_code'] ?? ''),
            qrCodeUrl: isset($response['qr_code_url']) ? (string) $response['qr_code_url'] : null,
            status: (string) ($response['status'] ?? 'active'),
        );
    }

    /** @return array<string, mixed> */
    public function orderDetails(string $orderId): array
    {
        return $this->get("/orders/{$orderId}");
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'clientsecret' => $this->clientSecret,
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 500)
            ->get($this->baseUrl.$path, $query);

        return $this->parseResponse($response);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withHeaders([
            'X-API-Key' => $this->apiKey,
            'clientsecret' => $this->clientSecret,
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 500)
            ->post($this->baseUrl.$path, $payload);

        return $this->parseResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponse(Response $response): array
    {
        if ($response->failed()) {
            throw new ESimApiException(
                'L2 eSIM API error ['.$response->status().']: '.$response->body()
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new ESimApiException('L2 eSIM API returned unexpected response format.');
        }

        return $data;
    }
}
