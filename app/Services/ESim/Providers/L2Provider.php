<?php

namespace App\Services\ESim\Providers;

use App\Contracts\ESim\ESimProviderInterface;
use App\DTOs\ESim\ESimOrderRequest;
use App\DTOs\ESim\ESimOrderResult;
use App\DTOs\ESim\ESimPackage;
use App\Models\Tenant\TenantEsimProvider;
use App\Services\ESim\ESimApiException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class L2Provider implements ESimProviderInterface
{
    private string $baseUrl;

    private string $apiKey;

    private string $clientSecret;

    public function __construct(protected TenantEsimProvider $config)
    {
        $this->baseUrl = rtrim((string) ($config->credentials['base_url'] ?? 'https://l2travelesim.com'), '/');
        $this->apiKey = (string) ($config->credentials['api_key'] ?? '');
        $this->clientSecret = (string) ($config->credentials['client_secret'] ?? '');
    }

    /** @return ESimPackage[] */
    public function catalogue(array $filters = []): array
    {
        $country = (string) ($filters['country'] ?? '');

        $payload = [];

        if ($country !== '') {
            $payload['countries'] = strtoupper($country);
        }

        $response = $this->post('/api/whitelabel/v2/catalogue', $payload);

        $bundles = $response['bundles'] ?? [];

        if (! is_array($bundles)) {
            return [];
        }

        $packages = [];

        foreach ($bundles as $item) {
            $packages[] = $this->mapBundle($item);
        }

        return $packages;
    }

    /** @return array<string, mixed> */
    public function bundles(string $packageId): array
    {
        return $this->post('/api/whitelabel/v2/bundle/details', [
            'bundleName' => $packageId,
        ]);
    }

    public function processOrder(ESimOrderRequest $request): ESimOrderResult
    {
        $payload = array_filter([
            'item' => $request->packageId,
            'iccid' => $request->iccid ?? null,
        ], fn (mixed $v): bool => $v !== null && $v !== '');

        $response = $this->post('/api/whitelabel/v2/processOrders', $payload);

        $firstOrder = is_array($response['order'] ?? null) ? ($response['order'][0] ?? []) : [];
        $firstEsim = is_array($firstOrder['esims'] ?? null) ? ($firstOrder['esims'][0] ?? []) : [];

        $iccid = (string) ($firstEsim['iccid'] ?? '');
        $matchingId = (string) ($firstEsim['matchingId'] ?? '');
        $smdpAddress = $firstEsim['smdpAddress'] !== '' ? (string) ($firstEsim['smdpAddress'] ?? '') : null;
        $assigned = (bool) ($response['assigned'] ?? false);
        $valid = (bool) ($response['valid'] ?? false);

        return new ESimOrderResult(
            orderId: (string) ($response['orderReference'] ?? ''),
            iccid: $iccid,
            activationCode: $matchingId,
            smdpAddress: $smdpAddress,
            qrCodeUrl: null,
            status: $assigned ? 'assigned' : ($valid ? 'pending' : 'processing'),
            assigned: $assigned,
        );
    }

    /** @return array<string, mixed> */
    public function orderDetails(string $orderId): array
    {
        return $this->post('/api/whitelabel/v2/orders/details', [
            'orderId' => $orderId,
        ]);
    }

    /** @return array{status: string} */
    public function deleteEsim(string $iccid): array
    {
        $response = $this->post('/api/whitelabel/v2/esims/delete', [
            'iccid' => $iccid,
        ]);

        return [
            'status' => (string) ($response['status'] ?? 'unknown'),
        ];
    }

    /**
     * @return array<int, array{name: string, brandName: string, speed: string[]}>
     */
    public function networks(string $iso): array
    {
        $iso = strtoupper($iso);

        return Cache::remember('esim_networks_'.$iso, now()->addHours(24), function () use ($iso): array {
            $response = $this->post('/api/whitelabel/v2/networks', ['isos' => $iso]);

            $countryNetworks = $response['countryNetworks'] ?? [];

            if (! is_array($countryNetworks) || empty($countryNetworks)) {
                return [];
            }

            $networks = $countryNetworks[0]['networks'] ?? [];

            if (! is_array($networks)) {
                return [];
            }

            return array_map(fn (array $n): array => [
                'name' => (string) ($n['name'] ?? ''),
                'brandName' => (string) ($n['brandName'] ?? ''),
                'speed' => is_array($n['speed'] ?? null) ? $n['speed'] : [],
            ], $networks);
        });
    }

    /**
     * @return array{firstName: string, lastName: string, email: string, title: string, mobileNo: string|null, balance: string}
     */
    public function organization(): array
    {
        return Cache::remember('esim_org_'.$this->config->id, now()->addMinutes(5), function (): array {
            $response = $this->get('/api/whitelabel/v2/organization');

            return [
                'firstName' => (string) ($response['firstName'] ?? ''),
                'lastName' => (string) ($response['lastName'] ?? ''),
                'email' => (string) ($response['email'] ?? ''),
                'title' => (string) ($response['title'] ?? ''),
                'mobileNo' => isset($response['mobileNo']) ? (string) $response['mobileNo'] : null,
                'balance' => (string) ($response['balance'] ?? $response['amount'] ?? '0'),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapBundle(array $item): ESimPackage
    {
        $bundleName = (string) ($item['name'] ?? '');
        $dataAmount = (int) ($item['dataAmount'] ?? 0);
        $duration = (int) ($item['duration'] ?? 0);
        $unlimited = (bool) ($item['unlimited'] ?? false);
        $price = (float) ($item['price'] ?? 0);
        $speeds = is_array($item['speed'] ?? null) ? $item['speed'] : [];
        $description = (string) ($item['description'] ?? '');

        // Primary country from the countries array
        $countries = is_array($item['countries'] ?? null) ? $item['countries'] : [];
        $primaryCountry = (string) ($countries[0]['iso'] ?? '');

        // dataAmount is in MB; -1 means unlimited
        $dataMb = $unlimited ? 0 : max(0, $dataAmount);

        return new ESimPackage(
            id: $bundleName,
            name: $description !== '' ? $description : $bundleName,
            country: $primaryCountry,
            dataMb: $dataMb,
            validityDays: $duration,
            price: $price,
            currency: 'USD',
            provider: 'l2',
            unlimited: $unlimited,
            speeds: $speeds,
            description: $description,
            rawCountries: $countries,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'clientSecret' => $this->clientSecret,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
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
    private function get(string $path): array
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'clientSecret' => $this->clientSecret,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 500)
            ->get($this->baseUrl.$path);

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
