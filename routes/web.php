<?php

use App\Http\Controllers\AgencyRegistrationController;
use App\Http\Controllers\Landlord\Auth\AuthenticatedSessionController as LandlordAuthenticatedSessionController;
use App\Http\Controllers\Landlord\DashboardController as LandlordDashboardController;
use App\Http\Controllers\Landlord\GlobalFlightCacheSettingsController;
use App\Http\Controllers\Landlord\TenantManagementController;
use App\Http\Controllers\Landlord\TenantUserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

foreach (config('tenancy.central_domains', []) as $centralDomain) {
    Route::domain($centralDomain)->group(function () {
        Route::get('/', function () {
            return Inertia::render('Welcome');
        });
    });
}

Route::get('/register-agency', [AgencyRegistrationController::class, 'show'])->name('agency.register');
Route::post('/register-agency', [AgencyRegistrationController::class, 'store']);

Route::prefix('admin')->name('landlord.')->group(function () {
    Route::get('login', [LandlordAuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [LandlordAuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('logout', [LandlordAuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('landlord.auth')->group(function () {
        Route::get('dashboard', LandlordDashboardController::class)->name('dashboard');
        Route::get('settings/flight-cache', [GlobalFlightCacheSettingsController::class, 'index'])->name('settings.flight-cache.index');
        Route::patch('settings/flight-cache', [GlobalFlightCacheSettingsController::class, 'update'])->name('settings.flight-cache.update');
        Route::get('tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [TenantManagementController::class, 'show'])->name('tenants.show');
        Route::patch('tenants/{tenant}/status', [TenantManagementController::class, 'updateStatus'])->name('tenants.status');

        Route::post('tenants/{tenant}/users', [TenantUserController::class, 'store'])->name('tenants.users.store');
        Route::put('tenants/{tenant}/users/{user}', [TenantUserController::class, 'update'])->name('tenants.users.update');
        Route::delete('tenants/{tenant}/users/{user}', [TenantUserController::class, 'destroy'])->name('tenants.users.destroy');
    });
});
