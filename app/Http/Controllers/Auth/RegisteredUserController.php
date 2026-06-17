<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Debugging via Log
        // \Illuminate\Support\Facades\Log::error('RegisteredUserController HIT');
        // \Illuminate\Support\Facades\Log::error('Tenancy Initialized: ' . (function_exists('tenancy') && tenancy()->initialized ? 'YES' : 'NO'));

        // Check plan limits if in tenant context
        if (function_exists('tenancy') && tenancy()->initialized) {
            $tenant = tenancy()->tenant;
            $plan = $tenant->plan;

            // \Illuminate\Support\Facades\Log::error('User Count: ' . User::count());
            // \Illuminate\Support\Facades\Log::error('Max Users: ' . ($plan ? $plan->max_users : 'null'));

            if ($plan && $plan->max_users) {
                if (User::count() >= $plan->max_users) {
                    return back()->withErrors(['email' => 'Maximum number of users reached for this plan ('.$plan->max_users.'). Please upgrade your plan.']);
                }
            }
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
