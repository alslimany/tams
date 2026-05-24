<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\AgencyWalletTransaction;
use App\Models\DefaultAgencySetting;
use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AgencyWalletController extends Controller
{
    /**
     * Top-up an agency wallet from the central admin panel.
     */
    public function topUp(Request $request, Tenant $tenantRecord): RedirectResponse
    {
        $validated = $request->validate([
            'currency' => ['required', 'string', 'size:3', Rule::in(['LYD', 'USD', 'EUR'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $currency = strtoupper($validated['currency']);
        $amount = (float) $validated['amount'];
        $adminId = Auth::guard('landlord')->id();

        // Also deposit into the tenant wallet package so tenant-side wallet balance is in sync.
        $tenantRecord->run(function () use ($currency, $amount, $adminId): void {
            $walletHolder = User::query()
                ->whereIn('role', ['admin', 'manager'])
                ->orderBy('id')
                ->first();

            if (! $walletHolder) {
                $walletHolder = User::query()->orderBy('id')->first();
            }

            if (! $walletHolder) {
                $walletHolder = User::query()->create([
                    'name' => 'Agency Wallet Holder',
                    'email' => sprintf('wallet-holder-%s@tenant.local', tenant('id')),
                    'password' => 'temporary-password',
                    'role' => 'admin',
                    'is_active' => true,
                ]);
            }

            $wallet = $walletHolder->getOrCreateCurrencyWallet($currency);
            $wallet->deposit($this->toMinor((string) $amount), [
                'type' => 'agency_wallet_topup',
                'description' => 'Top-up from central admin panel',
                'landlord_admin_id' => $adminId,
            ]);
        });

        $currentBalance = $this->getCurrentBalance($tenantRecord->id, $currency);
        $newBalance = $currentBalance + $amount;

        AgencyWalletTransaction::recordTopUp(
            tenantId: $tenantRecord->id,
            currency: $currency,
            amount: $amount,
            balanceAfter: $newBalance,
            description: $validated['description'] ?? null,
            adminId: $adminId,
        );

        return back()->with('success', "Wallet topped up with {$amount} {$currency} successfully.");
    }

    /**
     * Set a tenant as the default agency (or remove the designation).
     * Also creates/updates the DefaultAgencySetting row in the central DB.
     */
    public function setDefaultAgency(Request $request, Tenant $tenantRecord): RedirectResponse
    {
        $validated = $request->validate([
            'is_default_agency' => ['required', 'boolean'],
            'master_commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allowed_airline_codes' => ['nullable', 'array'],
            'allowed_airline_codes.*' => ['string'],
        ]);

        if ($validated['is_default_agency'] && ! $this->tenantHasActiveAirlineCredentials($tenant)) {
            throw ValidationException::withMessages([
                'is_default_agency' => 'The selected default agency must have at least one active airline credential in tenant providers.',
            ]);
        }

        if ($validated['is_default_agency']) {
            // Remove default agency status from any existing default agency.
            Tenant::query()
                ->where('is_default_agency', true)
                ->where('id', '!=', $tenantRecord->id)
                ->update(['is_default_agency' => false]);
        }

        $tenantRecord->update([
            'is_default_agency' => $validated['is_default_agency'],
            'master_commission_rate' => $validated['is_default_agency']
                ? ($validated['master_commission_rate'] ?? 0)
                : 0,
        ]);

        // Create or update the DefaultAgencySetting row.
        if ($validated['is_default_agency']) {
            DefaultAgencySetting::updateOrCreate(
                ['default_agency_tenant_id' => $tenantRecord->id],
                [
                    'master_commission_percent' => $validated['master_commission_rate'] ?? 0,
                    'allowed_airline_codes' => $validated['allowed_airline_codes'] ?? null,
                ],
            );
        } else {
            DefaultAgencySetting::where('default_agency_tenant_id', $tenantRecord->id)->delete();
        }

        $status = $validated['is_default_agency'] ? 'set as default agency' : 'removed as default agency';

        return back()->with('success', "Tenant {$status} successfully.");
    }

    /**
     * Update the per-agency permission for using own airline credentials.
     * Writes to both the tenant JSON settings (legacy) and agency_settings (tenant DB).
     */
    public function updateCredentialsPermission(Request $request, Tenant $tenantRecord): RedirectResponse
    {
        $validated = $request->validate([
            'use_own_airline_credentials' => ['required', 'boolean'],
        ]);

        // Update legacy JSON settings.
        $settings = $tenantRecord->settings ?? [];
        data_set($settings, 'finance.use_own_airline_credentials', $validated['use_own_airline_credentials']);
        $tenantRecord->update(['settings' => $settings]);

        // Sync to agency_settings in tenant DB.
        $tenantRecord->run(function () use ($validated): void {
            \App\Models\Tenant\AgencySetting::current()->update([
                'can_use_own_airline_credentials' => $validated['use_own_airline_credentials'],
            ]);
        });

        $label = $validated['use_own_airline_credentials'] ? 'own airline credentials' : 'master agency supply';

        return back()->with('success', "Agency updated to use {$label}.");
    }

    /**
     * Update per-agency settings in the tenant database.
     * Includes can_use_own_airline_credentials, force_use_default_agency, and master_commission_percent.
     */
    public function updateAgencySettings(Request $request, Tenant $tenantRecord): RedirectResponse
    {
        $validated = $request->validate([
            'can_use_own_airline_credentials' => ['sometimes', 'boolean'],
            'force_use_default_agency' => ['sometimes', 'boolean'],
            'master_commission_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $tenantRecord->run(function () use ($validated): void {
            $settings = \App\Models\Tenant\AgencySetting::current();

            if (array_key_exists('can_use_own_airline_credentials', $validated)) {
                $settings->can_use_own_airline_credentials = $validated['can_use_own_airline_credentials'];
            }

            if (array_key_exists('force_use_default_agency', $validated)) {
                $settings->force_use_default_agency = $validated['force_use_default_agency'];
            }

            if (array_key_exists('master_commission_percent', $validated)) {
                $settings->master_commission_percent = $validated['master_commission_percent'] ?? 0;
            }

            $settings->save();
        });

        // Sync can_use_own_airline_credentials to legacy JSON settings.
        if (array_key_exists('can_use_own_airline_credentials', $validated)) {
            $jsonSettings = $tenantRecord->settings ?? [];
            data_set($jsonSettings, 'finance.use_own_airline_credentials', $validated['can_use_own_airline_credentials']);
            $tenantRecord->update(['settings' => $jsonSettings]);
        }

        return back()->with('success', 'Agency settings updated successfully.');
    }

    /**
     * Update the default agency settings in the central database.
     * Includes allowed_airline_codes and master_commission_percent.
     */
    public function updateDefaultAgencySettings(Request $request, Tenant $tenantRecord): RedirectResponse
    {
        $validated = $request->validate([
            'allowed_airline_codes' => ['nullable', 'array'],
            'allowed_airline_codes.*' => ['string'],
            'master_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (! $tenantRecord->isDefaultAgency()) {
            return back()->with('error', 'This tenant is not the default agency.');
        }

        $settings = DefaultAgencySetting::forDefaultAgency($tenantRecord->id);
        $settings->update([
            'allowed_airline_codes' => $validated['allowed_airline_codes'] ?? $settings->allowed_airline_codes,
            'master_commission_percent' => $validated['master_commission_percent'] ?? $settings->master_commission_percent,
        ]);

        // Also sync to the tenants table for backward compatibility.
        if (isset($validated['master_commission_percent'])) {
            $tenantRecord->update(['master_commission_rate' => $validated['master_commission_percent']]);
        }

        return back()->with('success', 'Default agency settings updated successfully.');
    }

    /**
     * Get the current wallet balance for a tenant and currency from the landlord tracking table.
     */
    protected function getCurrentBalance(string $tenantId, string $currency): float
    {
        $lastTransaction = AgencyWalletTransaction::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->latest('id')
            ->first();

        return (float) ($lastTransaction?->balance_after ?? 0);
    }

    protected function tenantHasActiveAirlineCredentials(Tenant $tenant): bool
    {
        return (bool) $tenantRecord->run(function (): bool {
            return TenantProvider::query()
                ->where('is_active', true)
                ->whereNotNull('credentials')
                ->exists();
        });
    }

    protected function toMinor(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
