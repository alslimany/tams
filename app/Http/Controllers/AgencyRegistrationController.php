<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AgencyCreatedConfirmation;
use App\Services\OfficeIdGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class AgencyRegistrationController extends Controller
{
    public function show(): Response
    {
        $airports = Airport::query()
            ->where('show_in_registration', true)
            ->whereNotNull('iata_code')
            ->orderByRaw("json_extract(country, '$.en') ASC")
            ->orderByRaw("json_extract(city, '$.en') ASC")
            ->get(['id', 'iata_code', 'name', 'city', 'country'])
            ->map(fn (Airport $airport): array => [
                'iata_code' => $airport->iata_code,
                'city' => data_get($airport->city, 'en', $airport->iata_code),
                'country' => data_get($airport->country, 'en', ''),
            ]);

        return Inertia::render('Agency/Register', [
            'centralDomain' => $this->centralDomain(),
            'airports' => $airports,
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
            'city_iata' => [
                'required',
                'string',
                'size:3',
                Rule::exists('airports', 'iata_code')->where('show_in_registration', true),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'commercial_register' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'passport' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $generator = new OfficeIdGenerator;
        $cityIata = strtoupper($request->string('city_iata'));
        $officeId = $generator->generate($cityIata, $request->company_name);

        $tenant = Tenant::create([
            'id' => $officeId,
            'path' => $officeId,
            'office_id' => $officeId,
            'city_iata' => $cityIata,
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
            ->store("registrations/{$officeId}", 'public');

        $passportPath = $request->file('passport')
            ->store("registrations/{$officeId}", 'public');

        $tenant->update([
            'commercial_register_path' => $commercialRegisterPath,
            'passport_path' => $passportPath,
        ]);

        // Create a domain record
        $tenantBaseDomain = (string) config('tenancy.tenant_base_domain');
        $tenant->domains()->create([
            'domain' => strtolower($officeId).'.'.$tenantBaseDomain,
        ]);

        // Create admin user in tenant database
        $tenant->run(function () use ($request): void {
            User::create([
                'name' => $request->owner_name ?: $request->company_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
                'is_active' => true,
            ]);
        });

        $workspaceUrl = config('app.url').'/agency/'.strtolower($officeId).'/login';
        $ownerName = $request->owner_name ?: $request->company_name;

        $registration = [
            'agencyName' => $tenant->company_name,
            'agencyNumber' => $tenant->agency_number,
            'officeId' => $officeId,
            'ownerName' => $ownerName,
            'ownerEmail' => $request->email,
            'workspaceUrl' => $workspaceUrl,
            'status' => 'frozen',
        ];

        Notification::route('mail', [$request->email => $ownerName])
            ->notify(new AgencyCreatedConfirmation(
                agencyName: $tenant->company_name,
                agencyNumber: $tenant->agency_number,
                agencyPath: strtolower($officeId),
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
