<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class AgencyRegistrationController extends Controller
{
    public function show()
    {
        return Inertia::render('Agency/Register');
    }

    public function store(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255',
            'owner_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255',
            'subdomain' => 'required|string|alpha_dash|max:255|unique:domains,domain',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $tenant = Tenant::create([
            'id' => $request->subdomain,
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
            'domain' => $request->subdomain.'.'.parse_url(config('app.url'), PHP_URL_HOST),
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

        return redirect()->to('http://'.$request->subdomain.'.'.parse_url(config('app.url'), PHP_URL_HOST).'/login');
    }
}
