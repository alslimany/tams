<?php

namespace App\Services\Hotels\Providers;

use App\Contracts\Hotels\HotelProviderInterface;
use App\Models\Tenant\TenantHotelProvider;
use App\Services\Hotels\HotelApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class ThreeTProvider implements HotelProviderInterface
{
    public function __construct(protected ?TenantHotelProvider $configuredProvider = null) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function autocomplete(array $payload = []): array
    {
        return $this->bookingRequest('autocomplete', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function availability(array $payload): array
    {
        return $this->bookingRequest('availability', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function hotelDetails(array $payload): array
    {
        return $this->bookingRequest('hotelDetails', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function checkRate(array $payload): array
    {
        return $this->bookingRequest('checkRate', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function book(array $payload): array
    {
        return $this->bookingRequest('book', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cancel(array $payload): array
    {
        return $this->bookingRequest('cancel', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bookings(array $payload): array
    {
        return $this->bookingRequest('getBookings', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function creditCheck(): array
    {
        return $this->bookingRequest('creditCheck');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array
    {
        return $this->contentRequest('getCountries');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cities(string $countryCode): array
    {
        return $this->contentRequest('getCities', ['countryId' => strtoupper($countryCode)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hotels(int|string $cityId): array
    {
        return $this->contentRequest('getHotels', ['cityId' => $cityId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function boards(): array
    {
        return $this->contentRequest('getBoardList');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function bookingRequest(string $method, array $payload = []): array
    {
        $response = $this->client()
            ->asJson()
            ->post($this->bookingUrl($method), $payload)
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new HotelApiException('3T hotel API returned an invalid response.');
        }

        return $this->normalizeEnvelope($response, $method);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function contentRequest(string $method, array $query = []): array
    {
        $url = $this->contentUrl($method, $query);

        $response = $this->client()
            ->get($url)
            ->throw()
            ->json();

        if (! is_array($response)) {
            return [];
        }

        return array_values(array_filter($response, fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    protected function normalizeEnvelope(array $response, string $method): array
    {
        if ((bool) ($response['error'] ?? false)) {
            $message = (string) ($response['errorMessage'] ?? $response['msg'] ?? '3T hotel API rejected the request.');

            throw new HotelApiException($message, (int) ($response['errorCode'] ?? 0), [
                'method' => $method,
                'response' => $response,
            ]);
        }

        return [
            'method' => (string) ($response['method'] ?? $method),
            'response' => $response['response'] ?? [],
            'token_for_book' => (string) ($response['tokenForBook'] ?? ''),
            'search_code' => (string) ($response['searchCode'] ?? data_get($response, 'response.0.searchCode', '')),
            'error' => false,
            'error_code' => $response['errorCode'] ?? 200,
            'message' => (string) ($response['msg'] ?? 'Ok'),
            'raw' => $response,
        ];
    }

    protected function client(): PendingRequest
    {
        $credentials = $this->credentials();

        return Http::acceptJson()
            ->timeout(45)
            ->connectTimeout(10)
            ->withHeaders([
                'Api-key' => (string) Arr::get($credentials, 'api_key', ''),
                'Login' => (string) Arr::get($credentials, 'login', ''),
                'Password' => (string) Arr::get($credentials, 'password', ''),
            ]);
    }

    protected function bookingUrl(string $method): string
    {
        return $this->baseUrl().'/hotels-api?method='.urlencode($method);
    }

    protected function contentUrl(string $method, array $query = []): string
    {
        return $this->baseUrl().'/hotels-content?'.http_build_query([
            'method' => $method,
            ...$query,
        ]);
    }

    protected function baseUrl(): string
    {
        return str($this->credentials()['base_url'] ?? 'https://btob.3t.tn')
            ->rtrim('/')
            ->replaceEnd('/hotels-api', '')
            ->replaceEnd('/hotels-content', '')
            ->toString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentials(): array
    {
        $provider = $this->activeProvider();

        if (! $provider instanceof TenantHotelProvider) {
            throw new HotelApiException('3T hotel provider is not configured.');
        }

        return is_array($provider->credentials) ? $provider->credentials : [];
    }

    protected function activeProvider(): ?TenantHotelProvider
    {
        if ($this->configuredProvider instanceof TenantHotelProvider) {
            return $this->configuredProvider;
        }

        return TenantHotelProvider::query()
            ->where('provider_type', '3t')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }
}
