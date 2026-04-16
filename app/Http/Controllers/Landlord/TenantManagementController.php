<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\UpdateTenantStatusRequest;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TenantManagementController extends Controller
{
    public function index(): Response
    {
        $tenants = Tenant::query()
            ->latest()
            ->get()
            ->map(function (Tenant $tenant): array {
                $snapshot = $this->tenantSnapshot($tenant);

                return [
                    'id' => $tenant->id,
                    'company_name' => $tenant->company_name,
                    'owner_name' => $tenant->owner_name,
                    'owner_email' => $tenant->owner_email,
                    'status' => $tenant->status,
                    'subscription_status' => $tenant->subscription_status,
                    'domains' => $tenant->domains->pluck('domain')->values(),
                    'created_at' => $tenant->created_at,
                    'stats' => $snapshot['stats'],
                ];
            });

        return Inertia::render('Landlord/Tenants/Index', [
            'tenants' => $tenants,
        ]);
    }

    public function show(Tenant $tenant): Response
    {
        $snapshot = $this->tenantSnapshot($tenant);

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
                'domains' => $tenant->domains->pluck('domain')->values(),
                'created_at' => $tenant->created_at,
                'last_activity_at' => $tenant->last_activity_at,
                'snapshot' => $snapshot,
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
        $data = $tenant->run(function (): array {
            $providers = TenantProvider::query()->get([
                'id',
                'airline_code',
                'airline_name',
                'account_name',
                'is_active',
                'last_tested_at',
                'last_test_status',
            ]);

            $admin = User::query()
                ->where('role', 'admin')
                ->orderBy('id')
                ->first(['id', 'name', 'email', 'last_login_at', 'last_activity_at']);

            $recentBookings = \App\Models\Tenant\Booking::query()
                ->with('provider:id,airline_name')
                ->latest()
                ->limit(5)
                ->get(['id', 'pnr', 'status', 'tenant_provider_id', 'total_price', 'currency', 'created_at']);

            $users = User::query()
                ->latest()
                ->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at']);

            return [
                'stats' => [
                    'users' => User::count(),
                    'active_users' => User::where('is_active', true)->count(),
                    'providers' => $providers->count(),
                    'active_providers' => $providers->where('is_active', true)->count(),
                    'bookings' => \App\Models\Tenant\Booking::count(),
                ],
                'admin_user' => $admin,
                'providers' => $providers,
                'recent_bookings' => $recentBookings,
                'users' => $users,
            ];
        });

        return [
            'stats' => $data['stats'],
            'admin_user' => $data['admin_user'],
            'providers' => Collection::make($data['providers'])->values(),
            'recent_bookings' => Collection::make($data['recent_bookings'])->values(),
            'users' => Collection::make($data['users'])->values(),
        ];
    }
}
