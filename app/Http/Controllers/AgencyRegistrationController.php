<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Illuminate\Validation\Rules;

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
            'email' => 'required|string|email|max:255|unique:users',
            'subdomain' => 'required|string|alpha_dash|max:255|unique:domains,domain',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $tenant = Tenant::create([
            'id' => $request->subdomain,
        ]);

        $tenant->domains()->create([
            'domain' => $request->subdomain . '.' . parse_url(config('app.url'), PHP_URL_HOST),
        ]);

        $tenant->run(function () use ($request) {
            User::create([
                'name' => $request->company_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
        });

        return redirect()->to('http://' . $request->subdomain . '.' . parse_url(config('app.url'), PHP_URL_HOST) . '/login');
    }
}
