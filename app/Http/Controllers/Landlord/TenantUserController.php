<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TenantUserController extends Controller
{
    public function store(Request $request, Tenant $tenantRecord): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'string', Rule::in(['admin', 'manager', 'agent'])],
            'is_active' => ['boolean'],
        ]);

        $tenantRecord->run(function () use ($validated) {
            User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $validated['is_active'] ?? true,
            ]);
        });

        return back()->with('success', 'Tenant user created successfully.');
    }

    public function update(Request $request, Tenant $tenantRecord, $userId): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['nullable', Password::defaults()],
            'role' => ['required', 'string', Rule::in(['admin', 'manager', 'agent'])],
            'is_active' => ['boolean'],
        ]);

        $tenantRecord->run(function () use ($validated, $userId) {
            $user = User::findOrFail($userId);

            $updateData = [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $validated['role'],
                'is_active' => $validated['is_active'],
            ];

            if (! empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }

            $user->update($updateData);
        });

        return back()->with('success', 'Tenant user updated successfully.');
    }

    public function destroy(Tenant $tenantRecord, $userId): RedirectResponse
    {
        $tenantRecord->run(function () use ($userId) {
            $user = User::findOrFail($userId);
            $user->delete();
        });

        return back()->with('success', 'Tenant user deleted successfully.');
    }
}
