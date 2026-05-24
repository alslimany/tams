<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\NetworkMembership;
use App\Models\ProviderAllocation;
use App\Models\Tenant;
use App\Models\Tenant\TenantEsimProvider;
use App\Models\Tenant\TenantHotelProvider;
use App\Models\Tenant\TenantInsuranceProvider;
use App\Models\TenantProvider;
use App\Models\User;
use App\Notifications\MerchantNetworkInvitation;
use App\Notifications\MerchantNetworkJoined;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class NetworkController extends Controller
{
    public function index(): Response
    {
        $tenant = tenant();

        return Inertia::render('Tenant/Network/Index', [
            'agencyNumber' => $tenant?->agency_number,
            'availableProviders' => $this->availableProviders(),
            'agencyMemberships' => $this->agencyMemberships((string) $tenant?->id),
            'merchantMemberships' => $this->merchantMemberships((string) $tenant?->id),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'merchant_email' => ['required', 'email', 'max:255'],
            'merchant_contact_name' => ['nullable', 'string', 'max:255'],
            'provider_keys' => ['required', 'array', 'min:1'],
            'provider_keys.*' => ['required', 'string'],
            'provider_terms' => ['nullable', 'array'],
            'provider_terms.*' => ['nullable', 'array'],
        ]);

        $tenant = tenant();
        $providers = $this->availableProviders()
            ->whereIn('key', $validated['provider_keys'])
            ->values();

        if ($providers->isEmpty()) {
            return back()->withErrors([
                'provider_keys' => 'Select at least one configured provider API to offer.',
            ]);
        }

        $membership = DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($tenant, $validated, $providers): NetworkMembership {
            $membership = NetworkMembership::query()->create([
                'agency_tenant_id' => (string) $tenant->id,
                'merchant_email' => $validated['merchant_email'],
                'merchant_contact_name' => $validated['merchant_contact_name'] ?? null,
                'status' => NetworkMembership::StatusPending,
                'expires_at' => now()->addDays(14),
                'invited_at' => now(),
                'metadata' => [
                    'agency_number' => $tenant->agency_number,
                ],
            ]);

            $providers->each(function (array $provider) use ($membership, $tenant, $validated): void {
                $terms = $this->merchantFinancialTerms(
                    $provider,
                    (array) data_get($validated, 'provider_terms.'.$provider['key'], []),
                );

                ProviderAllocation::query()->create([
                    'network_membership_id' => $membership->id,
                    'agency_tenant_id' => (string) $tenant->id,
                    'provider_type' => $provider['provider_type'],
                    'provider_driver' => $provider['provider_driver'],
                    'provider_identity' => $provider['provider_identity'],
                    'source_provider_model' => $provider['source_provider_model'],
                    'source_provider_id' => $provider['source_provider_id'],
                    'status' => ProviderAllocation::StatusActive,
                    'is_offered_by_agency' => true,
                    'is_enabled_by_merchant' => false,
                    'commission_rate' => $terms['commission_rate'],
                    'markup_rate' => $terms['markup_rate'],
                    'metadata' => [
                        ...Arr::except($provider, ['key']),
                        'financial_terms' => $terms['metadata'],
                    ],
                ]);
            });

            return $membership;
        });

        Notification::route('mail', [
            $membership->merchant_email => $membership->merchant_contact_name ?: $membership->merchant_email,
        ])->notify(new MerchantNetworkInvitation(
            membership: $membership,
            agency: Tenant::query()->findOrFail((string) $tenant->id),
            joinUrl: route('network.index'),
        ));

        return back()->with('success', 'Merchant invitation created. Share the invitation code with the merchant.');
    }

    public function join(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'invitation_code' => ['required', 'string', 'max:32'],
        ]);

        $membership = NetworkMembership::query()
            ->where('invitation_code', strtoupper($validated['invitation_code']))
            ->where('status', NetworkMembership::StatusPending)
            ->first();

        if (! $membership instanceof NetworkMembership) {
            return back()->withErrors([
                'invitation_code' => 'Invitation code is invalid or no longer pending.',
            ]);
        }

        if ($membership->expires_at !== null && $membership->expires_at->isPast()) {
            return back()->withErrors([
                'invitation_code' => 'Invitation code has expired.',
            ]);
        }

        $membership->forceFill([
            'merchant_tenant_id' => (string) tenant()?->id,
        ])->save();

        return back()->with('success', 'Invitation loaded. Choose the provider APIs you want to enable.');
    }

    public function accept(Request $request, NetworkMembership $membership): RedirectResponse
    {
        abort_unless($membership->merchant_tenant_id === tenant()?->id, 403);

        $validated = $request->validate([
            'allocation_ids' => ['required', 'array', 'min:1'],
            'allocation_ids.*' => ['integer'],
        ]);

        $validAllocationIds = $membership->providerAllocations()
            ->whereIn('id', $validated['allocation_ids'])
            ->pluck('id')
            ->all();

        if (count($validAllocationIds) !== count(array_unique($validated['allocation_ids']))) {
            return back()->withErrors([
                'allocation_ids' => 'One or more selected provider APIs are not available for this invitation.',
            ]);
        }

        $selectedAllocations = $membership->providerAllocations()
            ->whereIn('id', $validAllocationIds)
            ->get();

        $duplicateIdentity = $this->duplicateEnabledProviderIdentity($selectedAllocations, (string) tenant()?->id);

        if ($duplicateIdentity !== null) {
            return back()->withErrors([
                'allocation_ids' => "Provider {$duplicateIdentity} is already enabled from another agency network.",
            ]);
        }

        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($membership, $validAllocationIds): void {
            $membership->providerAllocations()
                ->update([
                    'merchant_tenant_id' => (string) tenant()?->id,
                    'is_enabled_by_merchant' => false,
                    'enabled_at' => null,
                ]);

            $membership->providerAllocations()
                ->whereIn('id', $validAllocationIds)
                ->update([
                    'merchant_tenant_id' => (string) tenant()?->id,
                    'is_enabled_by_merchant' => true,
                    'enabled_at' => now(),
                ]);

            $membership->activate();
        });

        $this->notifyAgencyAdmins($membership->fresh(), Tenant::query()->findOrFail((string) tenant()?->id));

        return back()->with('success', 'Agency network joined successfully.');
    }

    public function suspend(NetworkMembership $membership): RedirectResponse
    {
        abort_unless($membership->agency_tenant_id === tenant()?->id, 403);

        $membership->suspend();

        return back()->with('success', 'Merchant network access suspended.');
    }

    public function revoke(NetworkMembership $membership): RedirectResponse
    {
        abort_unless($membership->agency_tenant_id === tenant()?->id, 403);

        $membership->revoke();

        return back()->with('success', 'Merchant network access revoked.');
    }

    protected function availableProviders(): \Illuminate\Support\Collection
    {
        $airlines = TenantProvider::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TenantProvider $provider): array => [
                'key' => 'airline:'.$provider->id,
                'provider_type' => 'airline',
                'provider_driver' => $provider->provider_type,
                'provider_identity' => $provider->airline_code,
                'source_provider_model' => TenantProvider::class,
                'source_provider_id' => $provider->id,
                'display_name' => $provider->airline_name.' — '.$provider->account_name,
                'description' => strtoupper((string) $provider->airline_code).' '.$provider->provider_type,
                'financial_mode' => 'discount',
                'agency_rates' => [
                    'domestic_discount_rate' => (float) ($provider->domestic_commission_rate ?? 0),
                    'international_discount_rate' => (float) ($provider->international_commission_rate ?? 0),
                ],
            ]);

        $insurance = TenantInsuranceProvider::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TenantInsuranceProvider $provider): array => [
                'key' => 'insurance:'.$provider->id,
                'provider_type' => 'insurance',
                'provider_driver' => $provider->provider_type,
                'provider_identity' => $provider->provider_type,
                'source_provider_model' => TenantInsuranceProvider::class,
                'source_provider_id' => $provider->id,
                'display_name' => $provider->name,
                'description' => 'Insurance API',
                'financial_mode' => 'discount',
                'agency_rates' => [
                    'compulsory_discount_rate' => (float) ($provider->commission_compulsory ?? 0),
                    'travel_discount_rate' => (float) ($provider->commission_travel ?? 0),
                    'orange_discount_rate' => (float) ($provider->commission_orange ?? 0),
                ],
            ]);

        $hotels = TenantHotelProvider::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TenantHotelProvider $provider): array => [
                'key' => 'hotel:'.$provider->id,
                'provider_type' => 'hotel',
                'provider_driver' => $provider->provider_type,
                'provider_identity' => $provider->provider_type,
                'source_provider_model' => TenantHotelProvider::class,
                'source_provider_id' => $provider->id,
                'display_name' => $provider->name,
                'description' => 'Hotel API',
                'financial_mode' => 'markup',
                'agency_rates' => [
                    'hotel_markup_rate' => (float) ($provider->commission_hotel ?? 0),
                ],
            ]);

        $esim = TenantEsimProvider::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (TenantEsimProvider $provider): array => [
                'key' => 'esim:'.$provider->id,
                'provider_type' => 'esim',
                'provider_driver' => $provider->provider_type,
                'provider_identity' => strtoupper((string) $provider->provider_type),
                'source_provider_model' => TenantEsimProvider::class,
                'source_provider_id' => $provider->id,
                'display_name' => $provider->name,
                'description' => 'eSIM API',
                'financial_mode' => 'commission',
                'agency_rates' => [
                    'esim_commission_rate' => (float) ($provider->commission_esim ?? 0),
                ],
            ]);

        return $airlines->concat($insurance)->concat($hotels)->concat($esim)->values();
    }

    /**
     * @param  array<string, mixed>  $provider
     * @param  array<string, mixed>  $input
     * @return array{commission_rate: float|null, markup_rate: float|null, metadata: array<string, mixed>}
     */
    protected function merchantFinancialTerms(array $provider, array $input): array
    {
        $agencyRates = (array) ($provider['agency_rates'] ?? []);

        return match ($provider['provider_type']) {
            'airline' => $this->airlineFinancialTerms($agencyRates, $input),
            'hotel' => $this->hotelFinancialTerms($agencyRates, $input),
            'insurance' => $this->insuranceFinancialTerms($agencyRates, $input),
            'esim' => $this->esimFinancialTerms($agencyRates, $input),
            default => [
                'commission_rate' => null,
                'markup_rate' => null,
                'metadata' => [
                    'mode' => $provider['financial_mode'] ?? 'commission',
                    'agency_rates' => $agencyRates,
                    'merchant_rates' => [],
                    'agency_profit_rates' => [],
                ],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $agencyRates
     * @param  array<string, mixed>  $input
     * @return array{commission_rate: float|null, markup_rate: float|null, metadata: array<string, mixed>}
     */
    protected function airlineFinancialTerms(array $agencyRates, array $input): array
    {
        $domestic = $this->validatedSharedRate($input, 'domestic_discount_rate', $agencyRates);
        $international = $this->validatedSharedRate($input, 'international_discount_rate', $agencyRates);

        return [
            'commission_rate' => max($domestic, $international),
            'markup_rate' => null,
            'metadata' => [
                'mode' => 'discount',
                'agency_rates' => $agencyRates,
                'merchant_rates' => [
                    'domestic_discount_rate' => $domestic,
                    'international_discount_rate' => $international,
                ],
                'agency_profit_rates' => [
                    'domestic_discount_rate' => round(((float) ($agencyRates['domestic_discount_rate'] ?? 0)) - $domestic, 2),
                    'international_discount_rate' => round(((float) ($agencyRates['international_discount_rate'] ?? 0)) - $international, 2),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $agencyRates
     * @param  array<string, mixed>  $input
     * @return array{commission_rate: float|null, markup_rate: float|null, metadata: array<string, mixed>}
     */
    protected function hotelFinancialTerms(array $agencyRates, array $input): array
    {
        $markup = $this->validatedSharedRate($input, 'hotel_markup_rate', $agencyRates);

        return [
            'commission_rate' => null,
            'markup_rate' => $markup,
            'metadata' => [
                'mode' => 'markup',
                'agency_rates' => $agencyRates,
                'merchant_rates' => [
                    'hotel_markup_rate' => $markup,
                ],
                'agency_profit_rates' => [
                    'hotel_markup_rate' => round(((float) ($agencyRates['hotel_markup_rate'] ?? 0)) - $markup, 2),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $agencyRates
     * @param  array<string, mixed>  $input
     * @return array{commission_rate: float|null, markup_rate: float|null, metadata: array<string, mixed>}
     */
    protected function insuranceFinancialTerms(array $agencyRates, array $input): array
    {
        $compulsory = $this->validatedSharedRate($input, 'compulsory_discount_rate', $agencyRates);
        $travel = $this->validatedSharedRate($input, 'travel_discount_rate', $agencyRates);
        $orange = $this->validatedSharedRate($input, 'orange_discount_rate', $agencyRates);

        return [
            'commission_rate' => max($compulsory, $travel, $orange),
            'markup_rate' => null,
            'metadata' => [
                'mode' => 'discount',
                'agency_rates' => $agencyRates,
                'merchant_rates' => [
                    'compulsory_discount_rate' => $compulsory,
                    'travel_discount_rate' => $travel,
                    'orange_discount_rate' => $orange,
                ],
                'agency_profit_rates' => [
                    'compulsory_discount_rate' => round(((float) ($agencyRates['compulsory_discount_rate'] ?? 0)) - $compulsory, 2),
                    'travel_discount_rate' => round(((float) ($agencyRates['travel_discount_rate'] ?? 0)) - $travel, 2),
                    'orange_discount_rate' => round(((float) ($agencyRates['orange_discount_rate'] ?? 0)) - $orange, 2),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $agencyRates
     * @param  array<string, mixed>  $input
     * @return array{commission_rate: float|null, markup_rate: float|null, metadata: array<string, mixed>}
     */
    protected function esimFinancialTerms(array $agencyRates, array $input): array
    {
        $commission = $this->validatedSharedRate($input, 'esim_commission_rate', $agencyRates);

        return [
            'commission_rate' => $commission,
            'markup_rate' => null,
            'metadata' => [
                'mode' => 'commission',
                'agency_rates' => $agencyRates,
                'merchant_rates' => [
                    'esim_commission_rate' => $commission,
                ],
                'agency_profit_rates' => [
                    'esim_commission_rate' => round(((float) ($agencyRates['esim_commission_rate'] ?? 0)) - $commission, 2),
                ],
            ],
        ];
    }

    protected function validatedSharedRate(array $input, string $key, array $agencyRates): float
    {
        $rate = round((float) ($input[$key] ?? 0), 2);
        $agencyRate = round((float) ($agencyRates[$key] ?? 0), 2);

        abort_if($rate < 0 || $rate > $agencyRate, 422, "Merchant {$key} must be between 0 and the agency rate of {$agencyRate}%.");

        return $rate;
    }

    protected function notifyAgencyAdmins(NetworkMembership $membership, Tenant $merchant): void
    {
        $agency = Tenant::query()->find($membership->agency_tenant_id);

        if (! $agency instanceof Tenant) {
            return;
        }

        $agency->run(function () use ($membership, $merchant): void {
            User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->get()
                ->each(fn (User $user): mixed => $user->notify(new MerchantNetworkJoined(
                    membership: $membership,
                    merchant: $merchant,
                    networkUrl: route('network.index'),
                )));
        });
    }

    protected function duplicateEnabledProviderIdentity(\Illuminate\Support\Collection $selectedAllocations, string $merchantTenantId): ?string
    {
        foreach ($selectedAllocations as $allocation) {
            if (! $allocation instanceof ProviderAllocation) {
                continue;
            }

            $alreadyEnabled = ProviderAllocation::query()
                ->whereKeyNot($allocation->id)
                ->where('merchant_tenant_id', $merchantTenantId)
                ->where('provider_type', $allocation->provider_type)
                ->where('provider_driver', $allocation->provider_driver)
                ->where('provider_identity', $allocation->provider_identity)
                ->active()
                ->offered()
                ->whereHas('networkMembership', fn ($query) => $query->active())
                ->exists();

            if ($alreadyEnabled) {
                return $allocation->provider_type.'/'.$allocation->provider_driver.'/'.$allocation->provider_identity;
            }
        }

        return null;
    }

    protected function agencyMemberships(string $tenantId): \Illuminate\Support\Collection
    {
        return NetworkMembership::query()
            ->with(['merchant', 'providerAllocations'])
            ->where('agency_tenant_id', $tenantId)
            ->latest()
            ->get()
            ->map(fn (NetworkMembership $membership): array => $this->membershipPayload($membership));
    }

    protected function merchantMemberships(string $tenantId): \Illuminate\Support\Collection
    {
        return NetworkMembership::query()
            ->with(['agency', 'providerAllocations'])
            ->where(function ($query) use ($tenantId): void {
                $query->where('merchant_tenant_id', $tenantId)
                    ->orWhereNull('merchant_tenant_id');
            })
            ->whereIn('status', [NetworkMembership::StatusPending, NetworkMembership::StatusActive, NetworkMembership::StatusSuspended])
            ->latest()
            ->get()
            ->filter(fn (NetworkMembership $membership): bool => $membership->merchant_tenant_id === $tenantId)
            ->map(fn (NetworkMembership $membership): array => $this->membershipPayload($membership));
    }

    protected function membershipPayload(NetworkMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'agency_tenant_id' => $membership->agency_tenant_id,
            'agency_name' => $membership->agency?->company_name,
            'agency_number' => $membership->agency?->agency_number,
            'merchant_tenant_id' => $membership->merchant_tenant_id,
            'merchant_name' => $membership->merchant?->company_name,
            'merchant_number' => $membership->merchant?->agency_number,
            'merchant_email' => $membership->merchant_email,
            'merchant_contact_name' => $membership->merchant_contact_name,
            'invitation_code' => $membership->invitation_code,
            'status' => $membership->status,
            'expires_at' => $membership->expires_at?->toDateTimeString(),
            'accepted_at' => $membership->accepted_at?->toDateTimeString(),
            'allocations' => $membership->providerAllocations->map(fn (ProviderAllocation $allocation): array => [
                'id' => $allocation->id,
                'provider_type' => $allocation->provider_type,
                'provider_driver' => $allocation->provider_driver,
                'provider_identity' => $allocation->provider_identity,
                'display_name' => data_get($allocation->metadata, 'display_name', $allocation->provider_identity),
                'description' => data_get($allocation->metadata, 'description'),
                'status' => $allocation->status,
                'commission_rate' => $allocation->commission_rate,
                'markup_rate' => $allocation->markup_rate,
                'financial_terms' => data_get($allocation->metadata, 'financial_terms'),
                'is_offered_by_agency' => $allocation->is_offered_by_agency,
                'is_enabled_by_merchant' => $allocation->is_enabled_by_merchant,
                'enabled_at' => $allocation->enabled_at?->toDateTimeString(),
            ])->values(),
        ];
    }
}
