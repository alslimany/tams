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
            'centralDomain' => $this->centralDomain(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $centralDomain = $this->centralDomain();

        $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'agency_path' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(['admin', 'api', 'app', 'mail', 'www', 'agency', 'register-agency', 'login', 'logout', 'dashboard']),
                Rule::unique('tenants', 'id'),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'commercial_register' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'passport' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $agencyPath = Str::lower($request->string('agency_path'));

        $tenant = Tenant::create([
            'id' => $agencyPath,
            'path' => $agencyPath,
            'company_name' => $request->company_name,
            'owner_name' => $request->owner_name ?: $request->company_name,
            'owner_email' => $request->email,
            'owner_phone' => $request->phone,
            'status' => 'frozen',
            'subscription_status' => 'trial',
            'subscription_plan' => 'startup',
            'settings' => [
                'search_display_mode' => 'per_offer',
            ],
        ]);

        // Store uploaded documents
        $commercialRegisterPath = $request->file('commercial_register')
            ->store("registrations/{$agencyPath}", 'public');

        $passportPath = $request->file('passport')
            ->store("registrations/{$agencyPath}", 'public');

        $tenant->update([
            'commercial_register_path' => $commercialRegisterPath,
            'passport_path' => $passportPath,
        ]);

        // Create a domain record for backward compatibility
        $tenantBaseDomain = (string) config('tenancy.tenant_base_domain');
        $tenant->domains()->create([
            'domain' => $agencyPath.'.'.$tenantBaseDomain,
        ]);

        // Create admin user in tenant database
        $tenant->run(function () use ($request) {
            User::create([
                'name' => $request->owner_name ?: $request->company_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'is_active' => true,
            ]);
        });

        $workspaceUrl = config('app.url').'/agency/'.$agencyPath.'/login';
        $ownerName = $request->owner_name ?: $request->company_name;

        $registration = [
            'agencyName' => $tenant->company_name,
            'agencyNumber' => $tenant->agency_number,
            'agencyPath' => $agencyPath,
            'ownerName' => $ownerName,
            'ownerEmail' => $request->email,
            'workspaceUrl' => $workspaceUrl,
            'status' => 'frozen',
        ];

        Notification::route('mail', [$request->email => $ownerName])
            ->notify(new AgencyCreatedConfirmation(
                agencyName: $tenant->company_name,
                agencyNumber: $tenant->agency_number,
                agencyPath: $agencyPath,
                ownerName: $ownerName,
                ownerEmail: $request->email,
                workspaceUrl: $workspaceUrl,
                status: 'frozen',
            ));

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

    private function centralDomain(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
