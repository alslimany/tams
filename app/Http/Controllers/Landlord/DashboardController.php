<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $tenants = Tenant::query()->latest()->get();

        $stats = [
            'totalAgencies' => $tenants->count(),
            'activeAgencies' => $tenants->where('status', 'active')->count(),
            'frozenAgencies' => $tenants->where('status', 'frozen')->count(),
            'suspendedAgencies' => $tenants->where('status', 'suspended')->count(),
            'activeProviders' => $this->countAcrossTenants(static fn () => TenantProvider::where('is_active', true)->count()),
            'tenantUsers' => $this->countAcrossTenants(static fn () => User::count()),
        ];

        $recentRegistrations = $tenants->take(5)->map(function (Tenant $tenant): array {
            return [
                'id' => $tenant->id,
                'company_name' => $tenant->company_name,
                'owner_email' => $tenant->owner_email,
                'status' => $tenant->status,
                'created_at' => $tenant->created_at,
            ];
        })->values();

        return Inertia::render('Landlord/Dashboard', [
            'stats' => $stats,
            'recentRegistrations' => $recentRegistrations,
        ]);
    }

    protected function countAcrossTenants(callable $callback): int
    {
        return Tenant::query()->get()->sum(function (Tenant $tenant) use ($callback): int {
            try {
                return (int) $tenant->run($callback);
            } catch (\Throwable) {
                return 0;
            }
        });
    }
}
