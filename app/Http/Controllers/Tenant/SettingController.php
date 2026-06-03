<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    /**
     * Display the general settings page.
     */
    public function index(): Response
    {
        return Inertia::render('Tenant/Settings/General', [
            'settings' => [
                'search_display_mode' => tenant()->getInternal('search_display_mode') ?? 'per_offer',
                'show_soldout_classes' => (bool) (tenant()->getInternal('show_soldout_classes') ?? true),
            ],
        ]);
    }

    /**
     * Update the tenant settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'search_display_mode' => 'required|in:per_offer,per_flight',
            'show_soldout_classes' => 'required|boolean',
        ]);

        $tenant = tenant();
        $tenant->setInternal('search_display_mode', $validated['search_display_mode']);
        $tenant->setInternal('show_soldout_classes', $validated['show_soldout_classes']);
        $tenant->save();

        return back()->with('success', 'Settings updated successfully.');
    }
}
