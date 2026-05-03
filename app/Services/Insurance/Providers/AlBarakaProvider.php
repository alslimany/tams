<?php

namespace App\Services\Insurance\Providers;

use App\Contracts\Insurance\InsuranceProviderInterface;
use App\DTOs\Insurance\InsuranceBookingRequest;
use App\DTOs\Insurance\InsuranceBookingResult;
use App\DTOs\Insurance\InsuranceQuoteRequest;
use App\DTOs\Insurance\InsuranceQuoteResult;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Services\Insurance\InsuranceApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class AlBarakaProvider implements InsuranceProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function travelZones(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/Travelers/ZonesLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function travelDurations(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/Travelers/DurationsLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function travelNationalities(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/ClientProfilePaxes/NationalityLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function compulsoryDurations(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/Compulsories/DurationsLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function compulsoryDocumentTypes(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/ClientProfileVehicles/DocumentTypesLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function compulsoryVehicleTypes(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/ClientProfileVehicles/CarsLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function compulsoryColors(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/ClientProfileVehicles/ColorsLookup'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function compulsoryLicensingAuthorities(): array
    {
        return $this->normalizeLookupItems($this->requestLookup('/api/ClientProfileVehicles/LicensingAuthoritiesLookup'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{total_premium:float,net_premium:float,tax_amount:float,currency:string,raw:array<string,mixed>}
     */
    public function calculateCompulsoryPrice(array $payload): array
    {
        $clientProfileVehicleId = (int) ($payload['ClientProfileVehicleId'] ?? $payload['DocumentTypeId'] ?? 0);

        $response = $this->request('POST', '/api/Compulsories/CheckPolicyPrices', [
            'ClientProfileVehicleId' => $clientProfileVehicleId,
            'InsuranceDurationId' => (int) ($payload['InsuranceDurationId'] ?? 0),
            'NoPassengers' => (int) ($payload['NoPassengers'] ?? 0),
            'Payload' => isset($payload['Payload']) ? (int) $payload['Payload'] : null,
        ]);

        $normalized = $this->normalizeMainResponse($response);

        $totalPremium = $this->extractNumericValue($normalized['data'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? $this->extractNumericValue($normalized['raw'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? 0.0;

        $taxAmount = $this->extractNumericValue($normalized['data'], ['Tax', 'Taxes', 'TaxAmount'])
            ?? $this->extractNumericValue($normalized['raw'], ['Tax', 'Taxes', 'TaxAmount'])
            ?? 0.0;

        $netPremium = max(0.0, $totalPremium - $taxAmount);

        return [
            'total_premium' => round($totalPremium, 2),
            'net_premium' => round($netPremium, 2),
            'tax_amount' => round($taxAmount, 2),
            'currency' => strtoupper((string) ($this->extractStringValue($normalized['data'], ['Currency', 'Curr'])
                ?? $this->extractStringValue($normalized['raw'], ['Currency', 'Curr'])
                ?? 'LYD')),
            'raw' => $normalized['raw'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createClientProfile(array $payload): int
    {
        $response = $this->request('POST', '/api/ClientProfiles/Post', [
            'Name' => (string) ($payload['Name'] ?? ''),
            'Phone' => (string) ($payload['Phone'] ?? ''),
            'Address' => (string) ($payload['Address'] ?? ''),
            'Email' => (string) ($payload['Email'] ?? ''),
        ]);

        return $this->extractPrimaryId($this->normalizeMainResponse($response));
    }

    public function findClientProfileByPhone(string $phone): ?int
    {
        $response = $this->request('GET', '/api/ClientProfiles/GetByPhone?Phone='.urlencode($phone));
        $normalized = $this->normalizeMainResponse($response, strictStatus: false);

        if (! $normalized['status']) {
            return null;
        }

        $id = $this->extractNumericValue($normalized['data'], ['Id', 'ID'])
            ?? $this->extractNumericValue($normalized['raw'], ['Id', 'ID']);

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createClientProfileVehicle(array $payload): int
    {
        $response = $this->request('POST', '/api/ClientProfileVehicles/Post', [
            'Name' => (string) ($payload['Name'] ?? ''),
            'Address' => (string) ($payload['Address'] ?? ''),
            'ChassisNumber' => (string) ($payload['ChassisNumber'] ?? ''),
            'TypeEnginePower' => (string) ($payload['TypeEnginePower'] ?? 0),
            'NoPassengers' => (int) ($payload['NoPassengers'] ?? $payload['NumberOfSeats'] ?? 0),
            'MetalPlateNo' => (string) ($payload['MetalPlateNo'] ?? ''),
            'Payload' => (int) ($payload['Payload'] ?? 0),
            'ManufactureYear' => (string) ($payload['ManufactureYear'] ?? ''),
            'ColorID' => (int) ($payload['ColorID'] ?? $payload['ColorId'] ?? $payload['ColorID'] ?? 0),
            'CarID' => (int) ($payload['CarID'] ?? $payload['CarId'] ?? $payload['CarID'] ?? 0),
            'LicensingAuthorityID' => (int) ($payload['LicensingAuthorityID'] ?? $payload['LicensingAuthorityId'] ?? 0),
            'PriceDetailID' => (int) ($payload['PriceDetailID'] ?? 14),
            'ClientProfileId' => (int) ($payload['ClientProfileId'] ?? 0),
        ]);

        return $this->extractPrimaryId($this->normalizeMainResponse($response));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{policy_id:int,policy_number:?string,raw:array<string,mixed>}
     */
    public function createCompulsoryPolicy(array $payload): array
    {
        $response = $this->request('POST', '/api/Compulsories/Post', [
            'Check' => $payload['Check'] ?? null,
            'ClientProfileId' => (int) ($payload['ClientProfileId'] ?? 0),
            'ClientProfileVehicleId' => (int) ($payload['ClientProfileVehicleId'] ?? 0),
            'PolicyDateFrom' => (string) ($payload['PolicyDateFrom'] ?? ''),
            'InsuranceDurationId' => (string) ($payload['InsuranceDurationId'] ?? ''),
            'IsPolicyPaid' => (bool) ($payload['IsPolicyPaid'] ?? false),
            'VoucherCode' => $payload['VoucherCode'] ?? null,
        ]);

        $normalized = $this->normalizeMainResponse($response);

        return [
            'policy_id' => $this->extractPrimaryId($normalized),
            'policy_number' => $this->extractStringValue($normalized['data'], ['PolicyNumber', 'policyNumber'])
                ?? $this->extractStringValue($normalized['raw'], ['policyNumber']),
            'raw' => $normalized['raw'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompulsoryPolicy(int $id): array
    {
        $response = $this->request('GET', '/api/Compulsories/Get/'.(int) $id);
        $normalized = $this->normalizeMainResponse($response);

        return [
            'data' => is_array($normalized['data']) ? $normalized['data'] : [],
            'raw' => $normalized['raw'],
        ];
    }

    public function quote(InsuranceQuoteRequest $request): InsuranceQuoteResult
    {
        $payload = $request->productType === 'compulsory'
            ? $this->normalizeCompulsoryPricingPayload($request->payload)
            : $request->payload;

        $response = match ($request->productType) {
            'compulsory' => $this->request('POST', $this->resolveCompulsoryQuotePath($payload), $payload),
            'travel' => $this->request('POST', '/api/Travelers/CheckPolicyPrices', $payload),
            'orange' => $this->request('POST', '/api/Oranges/CheckPolicyPrices', $payload),
            default => throw new InsuranceApiException('Unsupported insurance product type.'),
        };

        $normalized = $this->normalizeMainResponse($response);

        $totalPremium = $this->extractNumericValue($normalized['data'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? $this->extractNumericValue($normalized['raw'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? 0.0;

        $taxAmount = $this->extractNumericValue($normalized['data'], ['Tax', 'Taxes', 'TaxAmount']) ?? 0.0;
        $netPremium = max(0.0, $totalPremium - $taxAmount);

        return new InsuranceQuoteResult(
            success: $normalized['status'],
            message: $normalized['message'],
            totalPremium: round($totalPremium, 2),
            netPremium: round($netPremium, 2),
            taxAmount: round($taxAmount, 2),
            currency: $this->extractStringValue($normalized['data'], ['Currency', 'Curr']),
            raw: $normalized['raw'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{total_premium:float,net_premium:float,tax_amount:float,currency:string,raw:array<string,mixed>}
     */
    public function calculateTravelPolicyAgePrice(array $payload): array
    {
        $response = $this->request('POST', '/api/Travelers/CheckPolicyAgePrices', [
            'BirthDate' => (string) ($payload['BirthDate'] ?? ''),
            'ZoneID' => (int) ($payload['ZoneID'] ?? 0),
            'InsuranceDurationID' => (int) ($payload['InsuranceDurationID'] ?? 0),
        ]);

        $normalized = $this->normalizeMainResponse($response);

        $totalPremium = $this->extractNumericValue($normalized['data'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? $this->extractNumericValue($normalized['raw'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? 0.0;

        $netPremium = $this->extractNumericValue($normalized['data'], ['NetPremium', 'NetPrice', 'Net'])
            ?? $this->extractNumericValue($normalized['raw'], ['NetPremium', 'NetPrice', 'Net'])
            ?? 0.0;

        $taxAmount = $this->extractNumericValue($normalized['data'], ['Tax', 'Taxes', 'TaxAmount'])
            ?? $this->extractNumericValue($normalized['raw'], ['Tax', 'Taxes', 'TaxAmount'])
            ?? max(0.0, $totalPremium - $netPremium);

        if ($netPremium <= 0 && $totalPremium > 0) {
            $netPremium = max(0.0, $totalPremium - $taxAmount);
        }

        return [
            'total_premium' => round($totalPremium, 2),
            'net_premium' => round($netPremium, 2),
            'tax_amount' => round($taxAmount, 2),
            'currency' => strtoupper((string) ($this->extractStringValue($normalized['data'], ['Currency', 'Curr'])
                ?? $this->extractStringValue($normalized['raw'], ['Currency', 'Curr'])
                ?? 'LYD')),
            'raw' => $normalized['raw'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id:int,raw:array<string,mixed>}
     */
    public function createClientProfilePax(array $payload): array
    {
        $response = $this->request('POST', '/api/ClientProfilePaxes/Post', [
            'FirstName' => (string) ($payload['FirstName'] ?? ''),
            'LastName' => (string) ($payload['LastName'] ?? ''),
            'GenderId' => (int) ($payload['GenderId'] ?? 0),
            'BirthDate' => (string) ($payload['BirthDate'] ?? ''),
            'BirthPlace' => (string) ($payload['BirthPlace'] ?? ''),
            'PassportNo' => (string) ($payload['PassportNo'] ?? ''),
            'NationalityId' => (string) ($payload['NationalityId'] ?? ''),
            'ClientProfileId' => (int) ($payload['ClientProfileId'] ?? 0),
        ]);

        $normalized = $this->normalizeMainResponse($response);

        return [
            'id' => $this->extractPrimaryId($normalized),
            'raw' => $normalized['raw'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{policy_id:int,policy_number:string,report_reference:string,total_premium:float,net_premium:float,tax_amount:float,currency:string,raw:array<string,mixed>}
     */
    public function createTravelPolicy(array $payload): array
    {
        $response = $this->request('POST', '/api/Travelers/Post', [
            'ClientProfileId' => (int) ($payload['ClientProfileId'] ?? 0),
            'ClientProfilePaxeId' => (int) ($payload['ClientProfilePaxeId'] ?? 0),
            'ZoneID' => (string) ($payload['ZoneID'] ?? ''),
            'InsuranceDurationID' => (string) ($payload['InsuranceDurationID'] ?? ''),
            'PolicyDateFrom' => (string) ($payload['PolicyDateFrom'] ?? ''),
            'IsPolicyPaid' => (bool) ($payload['IsPolicyPaid'] ?? false),
            'VoucherCode' => $payload['VoucherCode'] ?? null,
            'Check' => $payload['Check'] ?? null,
        ]);

        $normalized = $this->normalizeMainResponse($response);
        $data = is_array($normalized['data']) ? $normalized['data'] : [];

        $totalPremium = $this->extractNumericValue($data, ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? $this->extractNumericValue($normalized['raw'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? 0.0;

        $netPremium = $this->extractNumericValue($data, ['NetPremium', 'NetPrice', 'Net'])
            ?? $this->extractNumericValue($normalized['raw'], ['NetPremium', 'NetPrice', 'Net'])
            ?? 0.0;

        $taxAmount = $this->extractNumericValue($data, ['Tax', 'Taxes', 'TaxAmount'])
            ?? $this->extractNumericValue($normalized['raw'], ['Tax', 'Taxes', 'TaxAmount'])
            ?? max(0.0, $totalPremium - $netPremium);

        if ($netPremium <= 0 && $totalPremium > 0) {
            $netPremium = max(0.0, $totalPremium - $taxAmount);
        }

        return [
            'policy_id' => $this->extractPrimaryId($normalized),
            'policy_number' => (string) ($this->extractStringValue($data, ['PolicyNo', 'PolicyNumber', 'policyNumber'])
                ?? $this->extractStringValue($normalized['raw'], ['policyNumber'])
                ?? ''),
            'report_reference' => (string) ($this->extractStringValue($data, ['EncryptedId', 'CardNumber'])
                ?? $this->extractStringValue($normalized['raw'], ['EncryptedId', 'CardNumber'])
                ?? ''),
            'total_premium' => round($totalPremium, 2),
            'net_premium' => round($netPremium, 2),
            'tax_amount' => round($taxAmount, 2),
            'currency' => strtoupper((string) ($this->extractStringValue($data, ['Currency', 'Curr'])
                ?? $this->extractStringValue($normalized['raw'], ['Currency', 'Curr'])
                ?? 'LYD')),
            'raw' => $normalized['raw'],
        ];
    }

    public function book(InsuranceBookingRequest $request): InsuranceBookingResult
    {
        $response = match ($request->productType) {
            'compulsory' => $this->request('POST', '/api/Compulsories/Post', $request->payload),
            'travel' => $this->request('POST', '/api/Travelers/Post', $request->payload),
            'orange' => $this->request('POST', '/api/Oranges/Post', $request->payload),
            default => throw new InsuranceApiException('Unsupported insurance product type.'),
        };

        $normalized = $this->normalizeMainResponse($response);
        $data = $normalized['data'];

        $totalPremium = $this->extractNumericValue($data, ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? $this->extractNumericValue($normalized['raw'], ['TotalPrice', 'TotalPremium', 'PolicyPrice', 'Price', 'Premium'])
            ?? 0.0;

        $taxAmount = $this->extractNumericValue($data, ['Tax', 'Taxes', 'TaxAmount']) ?? 0.0;

        return new InsuranceBookingResult(
            success: $normalized['status'],
            message: $normalized['message'],
            policyNumber: (string) ($normalized['raw']['policyNumber'] ?? $this->extractStringValue($data, ['PolicyNumber', 'policyNumber']) ?? ''),
            reportReference: $this->extractStringValue($data, ['EncryptedId', 'CardNumber', 'PolicyNumber']),
            totalPremium: round($totalPremium, 2),
            netPremium: round(max(0.0, $totalPremium - $taxAmount), 2),
            taxAmount: round($taxAmount, 2),
            currency: $this->extractStringValue($data, ['Currency', 'Curr']),
            raw: $normalized['raw'],
        );
    }

    public function lookup(string $productType, string $lookupKey): array
    {
        $path = $this->lookupPath($productType, $lookupKey);
        $response = $this->request('GET', $path);
        $normalized = $this->normalizeMainResponse($response, strictStatus: false);

        $data = $normalized['data'];

        if (is_array($data) && array_is_list($data)) {
            return $data;
        }

        if (is_array($data)) {
            return [$data];
        }

        return [];
    }

    public function policyReportUrl(string $productType, string $reportReference): string
    {
        $baseUrl = rtrim($this->baseUrl(), '/');

        return match ($productType) {
            'compulsory' => $baseUrl.'/api/Compulsories/GetReportById?EncryptedId='.urlencode($reportReference),
            'travel' => $baseUrl.'/api/Travelers/GetReportById?EncryptedId='.urlencode($reportReference),
            'orange' => $baseUrl.'/api/Oranges/GetReportById?CardNumber='.urlencode($reportReference),
            default => throw new InsuranceApiException('Unsupported insurance product type.'),
        };
    }

    public function fetchPolicyReport(string $productType, string $reportReference): array
    {
        $path = match ($productType) {
            'compulsory' => '/api/Compulsories/GetReportById?EncryptedId='.urlencode($reportReference),
            'travel' => '/api/Travelers/GetReportById?EncryptedId='.urlencode($reportReference),
            'orange' => '/api/Oranges/GetReportById?CardNumber='.urlencode($reportReference),
            default => throw new InsuranceApiException('Unsupported insurance product type.'),
        };

        $response = $this->client()
            ->accept('application/pdf')
            ->get($path);

        if ($response->failed()) {
            throw new InsuranceApiException('Insurance report request failed: '.$response->status().' '.$response->body());
        }

        return [
            'content' => $response->body(),
            'content_type' => (string) ($response->header('Content-Type') ?: 'application/pdf'),
        ];
    }

    public function cancel(string $productType, int $insurancePolicyId, string $remarks): array
    {
        $response = $this->request('POST', '/api/CancelRequests/Post', [
            'InsurancePolicyId' => $insurancePolicyId,
            'Remarks' => $remarks,
        ]);

        $normalized = $this->normalizeMainResponse($response, strictStatus: false);

        return [
            'insurance_policy_id' => $this->extractOptionalPrimaryId($normalized),
            'remarks' => $this->extractStringValue($normalized['data'], ['Remarks', 'Remark'])
                ?? $this->extractStringValue($normalized['raw'], ['Remarks', 'Remark'])
                ?? $remarks,
            'raw' => $normalized['raw'],
        ];
    }

    public function listCancellationRequests(string $dateFrom, string $dateTo): array
    {
        $response = $this->request(
            'GET',
            '/api/CancelRequests/Get?DateFrom='.urlencode($dateFrom).'&DateTo='.urlencode($dateTo),
        );

        $normalized = $this->normalizeMainResponse($response, strictStatus: false);
        $data = $this->normalizeCollectionData($normalized['data']);

        if (array_is_list($data)) {
            return array_values(array_filter($data, fn (mixed $item): bool => is_array($item)));
        }

        return is_array($data) ? [$data] : [];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $payload = null): array
    {
        $client = $this->client();

        $response = match (strtoupper($method)) {
            'GET' => $client->get($path),
            'POST' => $client->post($path, $payload ?? []),
            default => throw new InsuranceApiException('Unsupported HTTP method for insurance provider.'),
        };

        if ($response->failed()) {
            throw new InsuranceApiException('Insurance API request failed: '.$response->body());
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];

        return $json;
    }

    protected function client(): PendingRequest
    {
        $token = $this->resolveToken();

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(10)
            ->retry(2, 200)
            ->withToken($token);
    }

    protected function resolveToken(): string
    {
        $configuredToken = (string) data_get($this->activeProvider()?->credentials ?? [], 'token', '');

        if ($configuredToken !== '') {
            return $configuredToken;
        }

        $providedToken = (string) config('services.albaraka.token', '');

        if ($providedToken !== '') {
            return $providedToken;
        }

        throw new InsuranceApiException('Al Baraka bearer token is missing. Configure it from Insurance Configuration.');
    }

    protected function baseUrl(): string
    {
        $configuredBaseUrl = (string) data_get($this->activeProvider()?->credentials ?? [], 'base_url', '');

        if ($configuredBaseUrl !== '') {
            return rtrim($configuredBaseUrl, '/');
        }

        return rtrim((string) config('services.albaraka.base_url', 'https://tameen.webapi.ly'), '/');
    }

    protected function activeProvider(): ?TenantInsuranceProvider
    {
        return TenantInsuranceProvider::query()
            ->where('provider_type', 'albaraka')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{status:bool,message:string,data:mixed,raw:array<string,mixed>}
     */
    protected function normalizeMainResponse(array $response, bool $strictStatus = true): array
    {
        $status = (bool) ($response['Statues'] ?? $response['status'] ?? true);
        $message = (string) ($response['Messages'] ?? $response['message'] ?? '');
        $data = $response['data'] ?? null;

        if ($strictStatus && ! $status) {
            throw new InsuranceApiException($message !== '' ? $message : 'Insurance provider rejected request.');
        }

        return [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'raw' => $response,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveCompulsoryQuotePath(array $payload): string
    {
        return '/api/Compulsories/CheckPolicyPrices';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeCompulsoryPricingPayload(array $payload): array
    {
        $clientProfileVehicleId = (int) ($payload['ClientProfileVehicleId'] ?? $payload['DocumentTypeId'] ?? 0);

        if ($clientProfileVehicleId > 0) {
            $payload['ClientProfileVehicleId'] = $clientProfileVehicleId;
        }

        unset($payload['DocumentTypeId']);

        return $payload;
    }

    protected function lookupPath(string $productType, string $lookupKey): string
    {
        return match ($productType) {
            'compulsory' => match ($lookupKey) {
                'durations' => '/api/Compulsories/DurationsLookup',
                default => throw new InsuranceApiException('Unsupported compulsory lookup key.'),
            },
            'travel' => match ($lookupKey) {
                'zones' => '/api/Travelers/ZonesLookup',
                'durations' => '/api/Travelers/DurationsLookup',
                'nationalities' => '/api/ClientProfilePaxes/NationalityLookup',
                default => throw new InsuranceApiException('Unsupported travel lookup key.'),
            },
            'orange' => match ($lookupKey) {
                'cars' => '/api/Oranges/GetCarsLookup',
                'countries' => '/api/Oranges/GetCountriesLookup',
                'document_types' => '/api/Oranges/GetInsuranceClauseLookup',
                'vehicle_nationalities' => '/api/Oranges/GetVehicleNationalityLookup',
                default => throw new InsuranceApiException('Unsupported orange lookup key.'),
            },
            default => throw new InsuranceApiException('Unsupported insurance product type.'),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function requestLookup(string $path): array
    {
        $response = $this->request('GET', $path);
        $normalized = $this->normalizeMainResponse($response);

        if (is_array($normalized['data']) && array_is_list($normalized['data'])) {
            return $normalized['data'];
        }

        if (is_array($normalized['data'])) {
            return [$normalized['data']];
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeLookupItems(array $items): array
    {
        return array_values(array_map(function (array $item): array {
            $lookupValue = $item['Value'] ?? $item['value'] ?? $item['Id'] ?? $item['id'] ?? null;
            $id = is_numeric($lookupValue)
                ? (int) $lookupValue
                : (int) ($item['Id'] ?? $item['id'] ?? 0);

            return [
                'id' => $id,
                'name' => (string) ($item['Text'] ?? $item['text'] ?? $item['Name'] ?? $item['name'] ?? ''),
                'group' => (string) ($item['Group'] ?? $item['group'] ?? ''),
                'value' => $lookupValue,
                'raw' => $item,
            ];
        }, $items));
    }

    /**
     * @param  array{status:bool,message:string,data:mixed,raw:array<string,mixed>}  $normalized
     */
    protected function extractPrimaryId(array $normalized): int
    {
        $id = $this->extractNumericValue($normalized['data'], ['Id', 'ID', 'PolicyId', 'InsurancePolicyId'])
            ?? $this->extractNumericValue($normalized['raw'], ['Id', 'ID', 'PolicyId', 'InsurancePolicyId']);

        if ($id === null || (int) $id <= 0) {
            throw new InsuranceApiException('Insurance API response did not include a valid identifier.');
        }

        return (int) $id;
    }

    /**
     * @param  array{status:bool,message:string,data:mixed,raw:array<string,mixed>}  $normalized
     */
    protected function extractOptionalPrimaryId(array $normalized): ?int
    {
        $id = $this->extractNumericValue($normalized['data'], ['Id', 'ID', 'PolicyId', 'InsurancePolicyId'])
            ?? $this->extractNumericValue($normalized['raw'], ['Id', 'ID', 'PolicyId', 'InsurancePolicyId']);

        if ($id === null || (int) $id <= 0) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function normalizeCollectionData(mixed $data): array
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function extractNumericValue(mixed $source, array $keys): ?float
    {
        if (! is_array($source)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = Arr::get($source, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        foreach ($source as $value) {
            if (is_array($value)) {
                $nested = $this->extractNumericValue($value, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $keys
     */
    protected function extractStringValue(mixed $source, array $keys): ?string
    {
        if (! is_array($source)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = Arr::get($source, $key);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        foreach ($source as $value) {
            if (is_array($value)) {
                $nested = $this->extractStringValue($value, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }
}
