<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgencyCreatedConfirmation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class AgencyRegistrationController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Agency/Register', [
            'tenantBaseDomain' => config('tenancy.tenant_base_domain'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantBaseDomain = (string) config('tenancy.tenant_base_domain');

        $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'subdomain' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(['admin', 'api', 'app', 'mail', 'www']),
                Rule::unique('domains', 'domain')->where(fn ($query) => $query->where('domain', Str::lower($request->string('subdomain')).'.'.$tenantBaseDomain)),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $subdomain = Str::lower($request->string('subdomain'));
        $tenantDomain = $subdomain.'.'.$tenantBaseDomain;

        $tenant = Tenant::create([
            'id' => $subdomain,
            'company_name' => $request->company_name,
            'owner_name' => $request->owner_name ?: $request->company_name,
            'owner_email' => $request->email,
            'owner_phone' => $request->phone,
            'status' => 'active',
            'subscription_status' => 'trial',
            'subscription_plan' => 'startup',
            'settings' => [
                'search_display_mode' => 'per_offer',
            ],
        ]);

        $tenant->domains()->create([
            'domain' => $tenantDomain,
        ]);

        $tenant->run(function () use ($request) {
            User::create([
                'name' => $request->owner_name ?: $request->company_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'is_active' => true,
            ]);
        });

        $loginUrl = config('tenancy.tenant_url_scheme').'://'.$tenantDomain.'/login';
        $ownerName = $request->owner_name ?: $request->company_name;

        $registration = [
            'agencyName' => $tenant->company_name,
            'agencyNumber' => $tenant->agency_number,
            'ownerName' => $ownerName,
            'ownerEmail' => $request->email,
            'domain' => $tenantDomain,
            'loginUrl' => $loginUrl,
        ];

        Notification::route('mail', [$request->email => $ownerName])
            ->notify(new AgencyCreatedConfirmation(...$registration));

        return redirect()
            ->route('agency.registration.success')
            ->with('agency_registration', $registration);
    }

    public function success(): Response|RedirectResponse
    {
        $registration = session('agency_registration');

        if (! is_array($registration)) {
            return redirect()->route('agency.register');
        }

        return Inertia::render('Agency/Success', [
            'registration' => $registration,
        ]);
    }
}
