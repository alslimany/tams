<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Insurance\TravelIssueRequest;
use App\Http\Requests\Tenant\Insurance\TravelPriceRequest;
use App\Models\User;
use App\Services\Insurance\InsuranceProviderManager;
use App\Services\Insurance\Providers\AlBarakaProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class TravelInsuranceController extends Controller
{
    public function __construct(
        protected AlBarakaProvider $provider,
        protected InsuranceProviderManager $providerManager,
        protected CreateOrderFromInsuranceBooking $createOrderFromInsuranceBooking,
    ) {}

    public function beneficiaryPage(string $quoteToken): Response|RedirectResponse
    {
        $quote = $this->pullQuote($quoteToken, keepInSession: true);

        if ($quote === null) {
            return redirect()
                ->route('insurance.search')
                ->with('error', 'The selected quote has expired. Please get a new price.');
        }

        return Inertia::render('Tenant/Insurance/TravelBeneficiary', [
            'quoteToken' => $quoteToken,
            'quote' => $quote,
            'genders' => $this->genderOptions(),
            'nationalities' => $this->cachedReference('albaraka_nationalities', fn (): array => $this->provider->travelNationalities()),
        ]);
    }

    public function references(): JsonResponse
    {
        try {
            return response()->json([
                'zones' => $this->cachedReference('albaraka_zones', fn (): array => $this->provider->travelZones()),
                'durations' => $this->cachedReference('albaraka_durations', fn (): array => $this->provider->travelDurations()),
                'nationalities' => $this->cachedReference('albaraka_nationalities', fn (): array => $this->provider->travelNationalities()),
                'genders' => $this->genderOptions(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to fetch travel insurance references at the moment.',
            ], 422);
        }
    }

    public function price(TravelPriceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $dateFrom = CarbonImmutable::parse((string) $validated['policy_date_from']);
            $dateTo = CarbonImmutable::parse((string) $validated['policy_date_to']);

            $durationDays = max(1, $dateFrom->diffInDays($dateTo));
            $resolvedDuration = $this->resolveTravelDuration($durationDays);

            if ($resolvedDuration === null) {
                return response()->json([
                    'message' => 'Unable to match your selected dates to an insurance duration.',
                ], 422);
            }

            $passengerPricing = [];
            $passengerBreakdown = [
                'adult' => ['count' => 0, 'unit_total_premium' => 0.0, 'total_premium' => 0.0],
                'child' => ['count' => 0, 'unit_total_premium' => 0.0, 'total_premium' => 0.0],
                'senior' => ['count' => 0, 'unit_total_premium' => 0.0, 'total_premium' => 0.0],
            ];
            $totalPremium = 0.0;
            $totalNetPremium = 0.0;
            $totalTax = 0.0;
            $currency = 'LYD';
            $zoneText = $this->resolveZoneText((int) $validated['zone_id']);

            $pricingPassengers = $this->resolvePricingPassengers($validated);

            if (count($pricingPassengers) === 0) {
                return response()->json([
                    'message' => 'At least one passenger is required for travel insurance pricing.',
                ], 422);
            }

            foreach ($pricingPassengers as $index => $passenger) {
                $ageValidation = $this->validatePassengerAge((string) $passenger['birth_date']);

                if (! $ageValidation['valid']) {
                    return response()->json([
                        'message' => 'Passenger age is outside supported range (3 months to 85 years).',
                        'passenger_index' => $index,
                    ], 422);
                }

                $pricing = $this->provider->calculateTravelPolicyAgePrice([
                    'BirthDate' => (string) $passenger['birth_date'],
                    'ZoneID' => (int) $validated['zone_id'],
                    'InsuranceDurationID' => (int) $resolvedDuration['id'],
                ]);

                $totalPremium += (float) $pricing['total_premium'];
                $totalNetPremium += (float) $pricing['net_premium'];
                $totalTax += (float) $pricing['tax_amount'];
                $currency = (string) $pricing['currency'];

                $passengerType = (string) ($passenger['passenger_type'] ?? $ageValidation['band']);

                if (array_key_exists($passengerType, $passengerBreakdown)) {
                    $passengerBreakdown[$passengerType]['count']++;
                    $passengerBreakdown[$passengerType]['unit_total_premium'] = (float) $pricing['total_premium'];
                    $passengerBreakdown[$passengerType]['total_premium'] = round(
                        (float) $passengerBreakdown[$passengerType]['total_premium'] + (float) $pricing['total_premium'],
                        2,
                    );
                }

                $passengerPricing[] = [
                    'index' => $index,
                    'first_name' => (string) $passenger['first_name'],
                    'last_name' => (string) $passenger['last_name'],
                    'birth_date' => (string) $passenger['birth_date'],
                    'gender_id' => (int) $passenger['gender_id'],
                    'birth_place' => (string) $passenger['birth_place'],
                    'passport_number' => (string) $passenger['passport_number'],
                    'nationality_id' => isset($passenger['nationality_id']) ? (int) $passenger['nationality_id'] : null,
                    'passenger_type' => $passengerType,
                    'age_years' => (float) $ageValidation['age_years'],
                    'age_band' => (string) $ageValidation['band'],
                    'net_premium' => (float) $pricing['net_premium'],
                    'total_premium' => (float) $pricing['total_premium'],
                    'tax_amount' => (float) $pricing['tax_amount'],
                    'currency' => (string) $pricing['currency'],
                    'raw' => $pricing['raw'],
                ];
            }

            $quoteToken = (string) Str::uuid();

            $quote = [
                'quote_token' => $quoteToken,
                'zone_id' => (int) $validated['zone_id'],
                'zone_text' => $zoneText,
                'duration_id' => (int) $resolvedDuration['id'],
                'duration_text' => $durationDays.' days',
                'provider_duration_text' => (string) $resolvedDuration['text'],
                'duration_days' => (int) $durationDays,
                'policy_date_from' => $dateFrom->toDateString(),
                'policy_date_to' => $dateTo->toDateString(),
                'adult_count' => (int) ($passengerBreakdown['adult']['count'] ?? 0),
                'child_count' => (int) ($passengerBreakdown['child']['count'] ?? 0),
                'senior_count' => (int) ($passengerBreakdown['senior']['count'] ?? 0),
                'total_premium' => round($totalPremium, 2),
                'total_net_premium' => round($totalNetPremium, 2),
                'total_tax' => round($totalTax, 2),
                'currency' => strtoupper((string) $currency),
                'passenger_breakdown' => $passengerBreakdown,
                'passengers' => $passengerPricing,
                'created_at' => now()->toISOString(),
            ];

            $this->storeQuote($quoteToken, $quote);

            return response()->json($quote);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to calculate travel insurance price. Please verify passenger details and try again.',
            ], 422);
        }
    }

    public function issue(TravelIssueRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $quote = $this->pullQuote((string) $validated['quote_token'], keepInSession: true);

        if ($quote === null) {
            return back()->with('error', 'The selected quote has expired. Please calculate the price again.');
        }

        $issuer = $request->user();

        if (! $issuer instanceof User) {
            return back()->with('error', 'Authentication is required to issue travel insurance.');
        }

        try {
            $order = DB::transaction(function () use ($validated, $quote, $issuer) {
                $normalizedPhone = $this->normalizePhone((string) $validated['client_phone']);
                $existingClientProfileId = $this->provider->findClientProfileByPhone($normalizedPhone);

                $clientProfileId = $existingClientProfileId ?? $this->provider->createClientProfile([
                    'Name' => (string) $validated['client_name'],
                    'Phone' => $normalizedPhone,
                    'Address' => (string) ($validated['client_address'] ?? 'Not provided'),
                    'Email' => $validated['client_email'] ?? null,
                ]);

                $quotedPassengers = collect($quote['passengers'] ?? [])->values();

                $policyItems = [];

                foreach ($validated['passengers'] as $index => $passenger) {
                    $quotedPassenger = $quotedPassengers->first(fn (array $item): bool => (int) ($item['index'] ?? -1) === $index);

                    if (! is_array($quotedPassenger)) {
                        throw new \RuntimeException('Passenger list does not match the selected quote. Please recalculate the price.');
                    }

                    $clientProfilePax = $this->provider->createClientProfilePax([
                        'FirstName' => (string) $passenger['first_name'],
                        'LastName' => (string) $passenger['last_name'],
                        'GenderId' => (int) $passenger['gender_id'],
                        'BirthDate' => (string) $passenger['birth_date'],
                        'BirthPlace' => (string) $passenger['birth_place'],
                        'PassportNo' => (string) $passenger['passport_number'],
                        'NationalityId' => (string) $passenger['nationality_id'],
                        'ClientProfileId' => $clientProfileId,
                    ]);

                    $policy = $this->provider->createTravelPolicy([
                        'ClientProfileId' => $clientProfileId,
                        'ClientProfilePaxeId' => (int) $clientProfilePax['id'],
                        'ZoneID' => (string) ($quote['zone_id'] ?? ''),
                        'InsuranceDurationID' => (string) ($quote['duration_id'] ?? ''),
                        'PolicyDateFrom' => CarbonImmutable::parse((string) ($quote['policy_date_from'] ?? now()->toDateString()))->format('Y-m-d'),
                        'IsPolicyPaid' => false,
                        'VoucherCode' => null,
                        'Check' => null,
                    ]);

                    $policyItems[] = [
                        'passenger' => [
                            'first_name' => (string) $passenger['first_name'],
                            'last_name' => (string) $passenger['last_name'],
                            'birth_date' => (string) $passenger['birth_date'],
                            'gender_id' => (int) $passenger['gender_id'],
                            'birth_place' => (string) $passenger['birth_place'],
                            'passport_number' => (string) $passenger['passport_number'],
                            'nationality_id' => (int) $passenger['nationality_id'],
                        ],
                        'policy_details' => [
                            'policy_id' => (int) $policy['policy_id'],
                            'policy_number' => (string) $policy['policy_number'],
                            'report_reference' => (string) $policy['report_reference'],
                            'zone_id' => (int) ($quote['zone_id'] ?? 0),
                            'duration_id' => (int) ($quote['duration_id'] ?? 0),
                            'policy_date_from' => (string) ($quote['policy_date_from'] ?? ''),
                            'policy_date_to' => (string) ($quote['policy_date_to'] ?? ''),
                            'raw' => $policy['raw'],
                        ],
                        'net_amount' => (float) ($quotedPassenger['net_premium'] ?? $policy['net_premium']),
                        'total_amount' => (float) ($quotedPassenger['total_premium'] ?? $policy['total_premium']),
                        'tax_amount' => (float) ($quotedPassenger['tax_amount'] ?? $policy['tax_amount']),
                        'currency' => (string) ($quotedPassenger['currency'] ?? $policy['currency'] ?? 'LYD'),
                    ];
                }

                return $this->createOrderFromInsuranceBooking->createFromTravelPolicies(
                    userId: $issuer->id,
                    clientProfileData: [
                        'name' => (string) $validated['client_name'],
                        'phone' => $normalizedPhone,
                        'address' => (string) ($validated['client_address'] ?? 'Not provided'),
                        'email' => (string) ($validated['client_email'] ?? ''),
                        'client_profile_id' => $clientProfileId,
                    ],
                    policyItems: $policyItems,
                    requestPayload: [
                        'quote' => $quote,
                        'issue_request' => $validated,
                    ],
                    insuranceProvider: $this->providerManager->activeProvider(),
                );
            });

            $this->forgetQuote((string) $validated['quote_token']);

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'Travel insurance policies were issued successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Unable to issue travel insurance policies at the moment. Please try again.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    protected function resolvePricingPassengers(array $validated): array
    {
        if (is_array($validated['passengers'] ?? null) && count($validated['passengers']) > 0) {
            return array_values(array_map(function (array $passenger): array {
                return [
                    'first_name' => (string) ($passenger['first_name'] ?? ''),
                    'last_name' => (string) ($passenger['last_name'] ?? ''),
                    'birth_date' => (string) ($passenger['birth_date'] ?? ''),
                    'gender_id' => (int) ($passenger['gender_id'] ?? 1),
                    'birth_place' => (string) ($passenger['birth_place'] ?? ''),
                    'passport_number' => (string) ($passenger['passport_number'] ?? ''),
                    'nationality_id' => isset($passenger['nationality_id']) ? (int) $passenger['nationality_id'] : null,
                    'passenger_type' => (string) ($passenger['passenger_type'] ?? ''),
                ];
            }, $validated['passengers']));
        }

        $adultCount = max(0, (int) ($validated['adult_count'] ?? 0));
        $childCount = max(0, (int) ($validated['child_count'] ?? 0));
        $seniorCount = max(0, (int) ($validated['senior_count'] ?? 0));

        $passengers = [];
        $passengers = array_merge($passengers, $this->buildPassengerTemplates('adult', $adultCount));
        $passengers = array_merge($passengers, $this->buildPassengerTemplates('child', $childCount));
        $passengers = array_merge($passengers, $this->buildPassengerTemplates('senior', $seniorCount));

        return $passengers;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildPassengerTemplates(string $type, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $templates = [];

        for ($index = 0; $index < $count; $index++) {
            $birthDate = match ($type) {
                'child' => now()->subYears(10)->toDateString(),
                'senior' => now()->subYears(80)->toDateString(),
                default => now()->subYears(30)->toDateString(),
            };

            $templates[] = [
                'first_name' => '',
                'last_name' => '',
                'birth_date' => $birthDate,
                'gender_id' => 1,
                'birth_place' => '',
                'passport_number' => '',
                'nationality_id' => null,
                'passenger_type' => $type,
            ];
        }

        return $templates;
    }

    protected function resolveZoneText(int $zoneId): string
    {
        $zones = $this->cachedReference('albaraka_zones', fn (): array => $this->provider->travelZones());

        foreach ($zones as $zone) {
            if ((int) ($zone['id'] ?? 0) === $zoneId) {
                return (string) ($zone['name'] ?? '');
            }
        }

        return '';
    }

    /**
     * @return array<int, array{id:int,name:string,value:int}>
     */
    protected function genderOptions(): array
    {
        $genders = config('albaraka.genders', [1 => 'ذكر', 2 => 'انثى']);

        if (! is_array($genders)) {
            return [];
        }

        return array_values(array_map(function (int|string $value, int|string $id): array {
            return [
                'id' => (int) $id,
                'name' => (string) $value,
                'value' => (int) $id,
            ];
        }, $genders, array_keys($genders)));
    }

    /**
     * @param  callable():array<int, array<string, mixed>>  $resolver
     * @return array<int, array<string, mixed>>
     */
    protected function cachedReference(string $cacheKey, callable $resolver): array
    {
        return Cache::remember(
            $cacheKey,
            now()->addSeconds((int) config('albaraka.reference_cache_ttl_seconds', 86400)),
            $resolver,
        );
    }

    /**
     * @return array{id:int,text:string}|null
     */
    protected function resolveTravelDuration(int $durationDays): ?array
    {
        $durations = $this->cachedReference('albaraka_durations', fn (): array => $this->provider->travelDurations());

        if (count($durations) === 0) {
            return null;
        }

        $exact = null;
        $closest = null;
        $closestDistance = null;

        foreach ($durations as $duration) {
            $id = (int) ($duration['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $text = (string) ($duration['name'] ?? '');
            $days = $this->extractDaysFromText($text);

            if ($days !== null && $days === $durationDays) {
                $exact = ['id' => $id, 'text' => $text];
                break;
            }

            if ($days === null) {
                continue;
            }

            $distance = abs($days - $durationDays);

            if ($closest === null || $closestDistance === null || $distance < $closestDistance) {
                $closest = ['id' => $id, 'text' => $text];
                $closestDistance = $distance;
            }
        }

        return $exact ?? $closest;
    }

    protected function extractDaysFromText(string $label): ?int
    {
        $normalized = strtr($label, [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
        ]);

        if (preg_match('/(\d+)/', $normalized, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        if ($value <= 0) {
            return null;
        }

        if (preg_match('/(month|months|شهر|شهور|أشهر)/iu', $normalized) === 1) {
            return $value * 30;
        }

        if (preg_match('/(year|years|سنة|سنوات|عام|أعوام)/iu', $normalized) === 1) {
            return $value * 365;
        }

        if (preg_match('/(week|weeks|أسبوع|اسبوع|أسابيع|اسابيع)/iu', $normalized) === 1) {
            return $value * 7;
        }

        return $value;
    }

    /**
     * @return array{valid:bool,age_years:float,band:string}
     */
    protected function validatePassengerAge(string $birthDate): array
    {
        $birth = CarbonImmutable::parse($birthDate)->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();
        $ageInDays = max(0, $birth->diffInDays($today));
        $ageYears = $ageInDays / 365.25;

        if ($ageYears >= 0.25 && $ageYears < 18) {
            return ['valid' => true, 'age_years' => $ageYears, 'band' => 'child'];
        }

        if ($ageYears >= 18 && $ageYears <= 75) {
            return ['valid' => true, 'age_years' => $ageYears, 'band' => 'adult'];
        }

        if ($ageYears >= 76 && $ageYears <= 85) {
            return ['valid' => true, 'age_years' => $ageYears, 'band' => 'senior'];
        }

        return ['valid' => false, 'age_years' => $ageYears, 'band' => 'unsupported'];
    }

    protected function normalizePhone(string $phone): string
    {
        $trimmed = trim($phone);

        if ($trimmed === '') {
            return $trimmed;
        }

        $hasLeadingPlus = str_starts_with($trimmed, '+');
        $digitsOnly = preg_replace('/\D+/', '', $trimmed) ?: '';

        if ($digitsOnly === '') {
            return $trimmed;
        }

        return $hasLeadingPlus ? '+'.$digitsOnly : $digitsOnly;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function pullQuote(string $quoteToken, bool $keepInSession): ?array
    {
        $quotes = session()->get('insurance.travel_quotes', []);

        if (! is_array($quotes) || ! isset($quotes[$quoteToken]) || ! is_array($quotes[$quoteToken])) {
            return null;
        }

        $quote = $quotes[$quoteToken];

        if (! $keepInSession) {
            unset($quotes[$quoteToken]);
            session()->put('insurance.travel_quotes', $quotes);
        }

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    protected function storeQuote(string $quoteToken, array $quote): void
    {
        $quotes = session()->get('insurance.travel_quotes', []);

        if (! is_array($quotes)) {
            $quotes = [];
        }

        $quotes[$quoteToken] = $quote;
        session()->put('insurance.travel_quotes', $quotes);
    }

    protected function forgetQuote(string $quoteToken): void
    {
        $quotes = session()->get('insurance.travel_quotes', []);

        if (! is_array($quotes)) {
            return;
        }

        unset($quotes[$quoteToken]);
        session()->put('insurance.travel_quotes', $quotes);
    }
}
