<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Jobs\MigrateAgentJob;
use App\Models\MigrationRecord;
use App\Models\Tenant;
use App\Services\Migration\LegacyDbService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MigrationController extends Controller
{
    public function __construct(private readonly LegacyDbService $legacy) {}

    /**
     * Migration Hub — shows connection status and past migration records.
     */
    public function index(): Response
    {
        $connectionOk = false;

        try {
            $connectionOk = $this->legacy->testConnection();
        } catch (\Throwable) {
            $connectionOk = false;
        }

        $records = MigrationRecord::orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'legacy_agent_id' => $r->legacy_agent_id,
                'legacy_agent_name' => $r->legacy_agent_name,
                'legacy_agent_number' => $r->legacy_agent_number,
                'tenant_id' => $r->tenant_id,
                'status' => $r->status,
                'orders_migrated' => $r->orders_migrated,
                'items_migrated' => $r->items_migrated,
                'customers_migrated' => $r->customers_migrated,
                'duration_seconds' => $r->durationSeconds(),
                'started_at' => $r->started_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
                'created_at' => $r->created_at->toIso8601String(),
            ]);

        return Inertia::render('Landlord/Migration/Index', [
            'connectionOk' => $connectionOk,
            'records' => $records,
            'legacyConfig' => [
                'host' => config('database.connections.legacy.host'),
                'database' => config('database.connections.legacy.database'),
            ],
        ]);
    }

    /**
     * Agent list — browse all agents from the legacy DB.
     */
    public function agents(): Response
    {
        $connectionOk = false;
        $agents = [];

        try {
            $connectionOk = $this->legacy->testConnection();

            if ($connectionOk) {
                $migratedAgentIds = MigrationRecord::where('status', 'completed')
                    ->pluck('legacy_agent_id')
                    ->toArray();

                $agents = $this->legacy->getAgents()->map(function ($agent) use ($migratedAgentIds) {
                    return [
                        'id' => $agent->id,
                        'name' => $agent->name,
                        'number' => $agent->number ?? null,
                        'email' => $agent->email ?? null,
                        'phone' => $agent->phone ?? null,
                        'agent_type' => $agent->agent_type_id
                            ? $this->legacy->getAgentTypeName($agent->agent_type_id)
                            : 'direct',
                        'order_count' => $this->legacy->countAgentOrders($agent->id),
                        'joined_at' => $agent->joined_at ?? $agent->created_at ?? null,
                        'already_migrated' => in_array($agent->id, $migratedAgentIds),
                    ];
                })->values()->all();
            }
        } catch (\Throwable) {
            $connectionOk = false;
        }

        return Inertia::render('Landlord/Migration/Agents', [
            'connectionOk' => $connectionOk,
            'agents' => $agents,
            'agencyTenants' => $this->getAgencyTenantsWithProviders(),
            'allTenants' => Tenant::orderBy('company_name')->get(['id', 'company_name'])->toArray(),
        ]);
    }

    /**
     * Dispatch the migration job.
     */
    public function run(Request $request): RedirectResponse
    {
        $request->validate([
            'legacy_agent_id' => ['required', 'integer'],
            'include_voided' => ['boolean'],
            'date_from' => ['nullable', 'date'],
            'agency_network_tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
            'existing_tenant_id' => ['nullable', 'string', 'exists:tenants,id'],
        ]);

        $legacyAgentId = (int) $request->integer('legacy_agent_id');

        // Prevent duplicate running migrations for the same agent
        $alreadyRunning = MigrationRecord::where('legacy_agent_id', $legacyAgentId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($alreadyRunning) {
            return back()->withErrors(['legacy_agent_id' => 'A migration for this agent is already in progress.']);
        }

        $options = [
            'include_voided' => (bool) $request->boolean('include_voided'),
            'date_from' => $request->input('date_from'),
            'agency_network_tenant_id' => $request->input('agency_network_tenant_id') ?: null,
            'existing_tenant_id' => $request->input('existing_tenant_id') ?: null,
        ];

        // Create the record first so the job can find it by ID
        $agent = $this->legacy->getAgent($legacyAgentId);
        $record = MigrationRecord::create([
            'legacy_agent_id' => $legacyAgentId,
            'legacy_agent_name' => $agent?->name ?? "Agent #{$legacyAgentId}",
            'legacy_agent_number' => $agent?->agent_number ?? null,
            'status' => 'pending',
            'initiated_by' => (string) auth('landlord')->id(),
            'options' => $options,
        ]);

        MigrateAgentJob::dispatch(
            $legacyAgentId,
            (string) auth('landlord')->id(),
            $options,
            $record->id,
        );

        return redirect()->route('landlord.migration.status', $record->id);
    }

    /**
     * Real-time status page — polls every 3 seconds via Inertia.
     */
    public function status(MigrationRecord $record): Response
    {
        return Inertia::render('Landlord/Migration/Status', [
            'record' => $this->formatRecord($record),
        ]);
    }

    /**
     * Migration report page.
     */
    public function report(MigrationRecord $record): Response
    {
        return Inertia::render('Landlord/Migration/Report', [
            'record' => $this->formatRecord($record),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Return tenants that have at least one active airline provider configured.
     * Used to populate the agency network selector in the migration modal.
     *
     * @return array<int, array{id: string, company_name: string, provider_count: int}>
     */
    private function getAgencyTenantsWithProviders(): array
    {
        $result = [];

        $tenants = Tenant::where('status', 'active')->get(['id', 'company_name']);

        foreach ($tenants as $tenant) {
            try {
                $dbPath = database_path('tenant'.$tenant->id.'.sqlite');

                if (! file_exists($dbPath)) {
                    continue;
                }

                config(['database.connections.tenant_probe' => [
                    'driver' => 'sqlite',
                    'database' => $dbPath,
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ]]);

                $count = \DB::connection('tenant_probe')
                    ->table('tenant_providers')
                    ->where('is_active', true)
                    ->whereNotNull('airline_code')
                    ->count();

                \DB::purge('tenant_probe');

                if ($count > 0) {
                    $result[] = [
                        'id' => $tenant->id,
                        'company_name' => $tenant->company_name,
                        'provider_count' => $count,
                    ];
                }
            } catch (\Throwable) {
                \DB::purge('tenant_probe');
            }
        }

        return $result;
    }

    private function formatRecord(MigrationRecord $record): array
    {
        return [
            'id' => $record->id,
            'legacy_agent_id' => $record->legacy_agent_id,
            'legacy_agent_name' => $record->legacy_agent_name,
            'legacy_agent_number' => $record->legacy_agent_number,
            'tenant_id' => $record->tenant_id,
            'status' => $record->status,
            'options' => $record->options,
            'log' => $record->log ?? [],
            'error' => $record->error,
            'orders_migrated' => $record->orders_migrated,
            'items_migrated' => $record->items_migrated,
            'customers_migrated' => $record->customers_migrated,
            'duration_seconds' => $record->durationSeconds(),
            'started_at' => $record->started_at?->toIso8601String(),
            'completed_at' => $record->completed_at?->toIso8601String(),
            'created_at' => $record->created_at?->toIso8601String(),
        ];
    }
}
