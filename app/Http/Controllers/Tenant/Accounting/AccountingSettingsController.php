<?php

namespace App\Http\Controllers\Tenant\Accounting;

use App\Http\Controllers\Controller;
use App\Models\TenantProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountingSettingsController extends Controller
{
    public function index(): Response
    {
        $providers = TenantProvider::query()
            ->orderBy('airline_name')
            ->get(['id', 'airline_name', 'account_name', 'provider_type'])
            ->map(fn (TenantProvider $provider) => [
                'id' => $provider->id,
                'name' => $provider->airline_name ?? $provider->account_name,
                'type' => $provider->provider_type,
                'lowBalanceThreshold' => (float) (tenant()->data['accounting_thresholds'][$provider->id] ?? 500),
            ])
            ->values()
            ->all();

        $settings = [
            'currency' => 'LYD',
            'fiscalYearStartMonth' => (int) (tenant()->data['fiscal_year_start_month'] ?? 1),
            'vatRate' => (float) (tenant()->data['vat_rate'] ?? 0),
            'vatRegistrationNumber' => tenant()->data['vat_registration_number'] ?? '',
            'alertEmailRecipients' => tenant()->data['alert_email_recipients'] ?? '',
            'autoLockAfterClose' => (bool) (tenant()->data['auto_lock_after_close'] ?? false),
            'closeDateCurrentPeriod' => tenant()->data['close_date_current_period'] ?? null,
            'autoReconcileSchedule' => tenant()->data['auto_reconcile_schedule'] ?? 'manual',
            'alertOnMismatch' => (bool) (tenant()->data['alert_on_mismatch'] ?? true),
        ];

        return Inertia::render('Accounting/Settings/Index', [
            'settings' => $settings,
            'providers' => $providers,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'fiscalYearStartMonth' => 'required|integer|min:1|max:12',
            'vatRate' => 'required|numeric|min:0|max:100',
            'vatRegistrationNumber' => 'nullable|string|max:50',
            'alertEmailRecipients' => 'nullable|string|max:500',
            'autoLockAfterClose' => 'boolean',
            'closeDateCurrentPeriod' => 'nullable|date',
            'autoReconcileSchedule' => 'required|in:daily,weekly,manual',
            'alertOnMismatch' => 'boolean',
            'thresholds' => 'nullable|array',
            'thresholds.*' => 'numeric|min:0',
        ]);

        $t = tenant();
        $data = $t->data ?? [];

        $data['fiscal_year_start_month'] = $validated['fiscalYearStartMonth'];
        $data['vat_rate'] = $validated['vatRate'];
        $data['vat_registration_number'] = $validated['vatRegistrationNumber'] ?? '';
        $data['alert_email_recipients'] = $validated['alertEmailRecipients'] ?? '';
        $data['auto_lock_after_close'] = $validated['autoLockAfterClose'] ?? false;
        $data['close_date_current_period'] = $validated['closeDateCurrentPeriod'] ?? null;
        $data['auto_reconcile_schedule'] = $validated['autoReconcileSchedule'];
        $data['alert_on_mismatch'] = $validated['alertOnMismatch'] ?? true;

        if (! empty($validated['thresholds'])) {
            $data['accounting_thresholds'] = $validated['thresholds'];
        }

        $t->update(['data' => $data]);

        return back()->with('success', 'Settings saved.');
    }
}
