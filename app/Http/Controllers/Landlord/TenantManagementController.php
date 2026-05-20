<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\UpdateTenantStatusRequest;
use App\Models\AgencyWalletTransaction;
use App\Models\DefaultAgencySetting;
use App\Models\Tenant;
use App\Models\Tenant\AgencySetting;
use App\Models\Tenant\Order;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Stancl\Tenancy\Exceptions\TenantDatabaseDoesNotExistException;

class TenantManagementController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()
            ->latest()
            ->get()
            ->map(function (Tenant $tenant): array {
                $snapshot = $this->tenantSnapshot($tenant);
                $agencySettings = $this->resolveAgencySettings($tenant);
                $primaryDomain = (string) ($tenant->domains->first()?->domain ?? '');

                return [
                    'id' => $tenant->id,
                    'company_name' => $tenant->company_name,
                    'subdomain' => $primaryDomain,
                    'owner_name' => $tenant->owner_name,
                    'owner_email' => $tenant->owner_email,
                    'status' => $tenant->status,
                    'subscription_status' => $tenant->subscription_status,
                    'is_default_agency' => $tenant->isDefaultAgency(),
                    'domains' => $tenant->domains->pluck('domain')->values(),
                    'can_use_own_airline_credentials' => $agencySettings['can_use_own_airline_credentials'],
                    'force_use_default_agency' => $agencySettings['force_use_default_agency'],
                    'master_commission_percent' => $agencySettings['master_commission_percent'],
                    'created_at' => $tenant->created_at,
                    'stats' => $snapshot['stats'],
                    'database_missing' => $snapshot['database_missing'],
                ];
            });

        return Inertia::render('Landlord/Tenants/Index', [
            'tenants' => $tenants,
        ]);
    }

    public function show(Tenant $tenant): Response
    {
        $snapshot = $this->tenantSnapshot($tenant);
        $walletBalances = $this->resolveWalletBalances($tenant);
        $recentWalletTransactions = $this->resolveRecentWalletTransactions($tenant);
        $agencySettings = $this->resolveAgencySettings($tenant);
        $defaultAgencySettings = $this->resolveDefaultAgencySettings($tenant);

        return Inertia::render('Landlord/Tenants/Show', [
            'tenantRecord' => [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'owner_name' => $tenant->owner_name,
                'owner_email' => $tenant->owner_email,
                'owner_phone' => $tenant->owner_phone,
                'status' => $tenant->status,
                'subscription_status' => $tenant->subscription_status,
                'subscription_plan' => $tenant->subscription_plan,
                'settings' => $tenant->settings,
                'is_default_agency' => $tenant->isDefaultAgency(),
                'master_commission_rate' => $tenant->getMasterCommissionRate(),
                'uses_own_airline_credentials' => $tenant->usesOwnAirlineCredentials(),
                'domains' => $tenant->domains->pluck('domain')->values(),
                'created_at' => $tenant->created_at,
                'last_activity_at' => $tenant->last_activity_at,
                'wallet_balances' => $walletBalances,
                'recent_wallet_transactions' => $recentWalletTransactions,
                'agency_settings' => $agencySettings,
                'default_agency_settings' => $defaultAgencySettings,
                'snapshot' => $snapshot,
                'database_missing' => $snapshot['database_missing'],
            ],
        ]);
    }

    public function updateStatus(UpdateTenantStatusRequest $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update([
            'status' => $request->validated('status'),
        ]);

        return back()->with('success', 'Tenant status updated successfully.');
    }

    protected function tenantSnapshot(Tenant $tenant): array
    {
        try {
            $data = $tenant->run(function (): array {
                $providerModels = TenantProvider::query()->get([
                    'id',
                    'airline_code',
                    'airline_name',
                    'account_name',
                    'is_active',
                    'last_tested_at',
                    'last_test_status',
                ]);

                $providers = $providerModels
                    ->map(fn (TenantProvider $provider): array => [
                        'id' => $provider->id,
                        'airline_code' => $provider->airline_code,
                        'airline_name' => $provider->airline_name,
                        'account_name' => $provider->account_name,
                        'is_active' => (bool) $provider->is_active,
                        'last_tested_at' => $provider->last_tested_at,
                        'last_test_status' => $provider->last_test_status,
                    ])
                    ->values();

                $adminModel = User::query()
                    ->where('role', 'admin')
                    ->orderBy('id')
                    ->first(['id', 'name', 'email', 'last_login_at', 'last_activity_at']);

                $admin = $adminModel
                    ? [
                        'id' => $adminModel->id,
                        'name' => $adminModel->name,
                        'email' => $adminModel->email,
                        'last_login_at' => $adminModel->last_login_at,
                        'last_activity_at' => $adminModel->last_activity_at,
                    ]
                    : null;

                $providersByAirlineCode = $providers->keyBy('airline_code');

                $recentBookings = Order::query()
                    ->with('items:id,order_id,provider_reference,item_details')
                    ->latest()
                    ->limit(5)
                    ->get(['id', 'status', 'grand_total', 'currency', 'payment_reference', 'created_at'])
                    ->map(function (Order $order) use ($providersByAirlineCode): array {
                        $firstItem = $order->items->first();
                        $airlineCode = (string) data_get($firstItem?->item_details, 'airline_code', '');

                        return [
                            'id' => $order->id,
                            'pnr' => (string) ($firstItem?->provider_reference ?: $order->payment_reference),
                            'status' => $order->status,
                            'provider' => $airlineCode !== ''
                                ? ['airline_name' => data_get($providersByAirlineCode->get($airlineCode), 'airline_name')]
                                : null,
                            'total_price' => (float) $order->grand_total,
                            'currency' => $order->currency,
                            'created_at' => $order->created_at,
                        ];
                    })
                    ->values();

                $users = User::query()
                    ->latest()
                    ->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at'])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'is_active' => (bool) $user->is_active,
                        'last_login_at' => $user->last_login_at,
                    ])
                    ->values();

                return [
                    'stats' => [
                        'users' => User::count(),
                        'active_users' => User::where('is_active', true)->count(),
                        'providers' => $providers->count(),
                        'active_providers' => $providers->where('is_active', true)->count(),
                        'bookings' => Order::count(),
                    ],
                    'admin_user' => $admin,
                    'providers' => $providers,
                    'recent_bookings' => $recentBookings,
                    'users' => $users,
                ];
            });

            return [
                'database_missing' => false,
                'stats' => $data['stats'],
                'admin_user' => $data['admin_user'],
                'providers' => $data['providers'],
                'recent_bookings' => $data['recent_bookings'],
                'users' => $data['users'],
            ];
        } catch (TenantDatabaseDoesNotExistException $e) {
            Log::warning("Tenant [{$tenant->id}] database does not exist: {$e->getMessage()}");

            return [
                'database_missing' => true,
                'stats' => ['users' => 0, 'active_users' => 0, 'providers' => 0, 'active_providers' => 0, 'bookings' => 0],
                'admin_user' => null,
                'providers' => [],
                'recent_bookings' => [],
                'users' => [],
            ];
        }
    }

    protected function resolveWalletBalances(Tenant $tenant): array
    {
        $balances = [];
        $currencies = ['LYD', 'USD', 'EUR'];

        foreach ($currencies as $currency) {
            $lastTransaction = AgencyWalletTransaction::query()
                ->where('tenant_id', $tenant->id)
                ->where('currency', $currency)
                ->latest('id')
                ->first();

            $balances[$currency] = (float) ($lastTransaction?->balance_after ?? 0);
        }

        return $balances;
    }

    protected function resolveRecentWalletTransactions(Tenant $tenant): array
    {
        return AgencyWalletTransaction::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AgencyWalletTransaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'currency' => $transaction->currency,
                'amount' => (float) $transaction->amount,
                'balance_after' => (float) $transaction->balance_after,
                'description' => $transaction->description,
                'admin_id' => $transaction->admin_id,
                'created_at' => $transaction->created_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Resolve the agency_settings from the tenant database.
     */
    protected function resolveAgencySettings(Tenant $tenant): array
    {
        return $tenant->run(function (): array {
            $settings = AgencySetting::current();

            return [
                'can_use_own_airline_credentials' => $settings->canUseOwnAirlineCredentials(),
                'force_use_default_agency' => $settings->isForcedToUseDefaultAgency(),
                'default_agency_tenant_id' => $settings->default_agency_tenant_id,
                'master_commission_percent' => $settings->getMasterCommissionPercent(),
            ];
        });
    }

    /**
     * Resolve the default_agency_settings from the central database.
     * Only returns data if this tenant is the default agency.
     */
    protected function resolveDefaultAgencySettings(Tenant $tenant): ?array
    {
        if (! $tenant->isDefaultAgency()) {
            return null;
        }

        $settings = DefaultAgencySetting::forDefaultAgency($tenant->id);

        return [
            'master_commission_percent' => (float) ($settings->master_commission_percent ?? 0),
            'allowed_airline_codes' => $settings->allowed_airline_codes ?? [],
        ];
    }
}
