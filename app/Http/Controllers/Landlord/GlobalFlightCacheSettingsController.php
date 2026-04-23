<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Services\GlobalCache\GlobalFlightCacheSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GlobalFlightCacheSettingsController extends Controller
{
    public function update(Request $request, GlobalFlightCacheSettingsService $settingsService): RedirectResponse
    {
        $validated = $request->validate([
            'route_availability_enabled' => 'required|boolean',
            'schedule_cache_enabled' => 'required|boolean',
        ]);

        $settingsService->setRouteAvailabilityEnabled((bool) $validated['route_availability_enabled']);
        $settingsService->setScheduleCacheEnabled((bool) $validated['schedule_cache_enabled']);

        return back()->with('success', 'Global flight cache settings updated.');
    }
}
