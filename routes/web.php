<?php

use App\Http\Controllers\AgencyRegistrationController;
use App\Http\Controllers\Landlord\Auth\AuthenticatedSessionController as LandlordAuthenticatedSessionController;
use App\Http\Controllers\Landlord\DashboardController as LandlordDashboardController;
use App\Http\Controllers\Landlord\TenantManagementController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/register-agency', [AgencyRegistrationController::class, 'show'])->name('agency.register');
Route::post('/register-agency', [AgencyRegistrationController::class, 'store']);

Route::prefix('admin')->name('landlord.')->group(function () {
    Route::get('login', [LandlordAuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [LandlordAuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('logout', [LandlordAuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('landlord.auth')->group(function () {
        Route::get('dashboard', LandlordDashboardController::class)->name('dashboard');
        Route::get('tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [TenantManagementController::class, 'show'])->name('tenants.show');
        Route::patch('tenants/{tenant}/status', [TenantManagementController::class, 'updateStatus'])->name('tenants.status');
    });
});
