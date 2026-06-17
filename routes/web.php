<?php

use App\Http\Controllers\AgencyRegistrationController;
use App\Http\Controllers\Landlord\AgencyWalletController;
use App\Http\Controllers\Landlord\AirportController;
use App\Http\Controllers\Landlord\Auth\AuthenticatedSessionController as LandlordAuthenticatedSessionController;
use App\Http\Controllers\Landlord\CountryController;
use App\Http\Controllers\Landlord\DashboardController as LandlordDashboardController;
use App\Http\Controllers\Landlord\GlobalFlightCacheSettingsController;
use App\Http\Controllers\Landlord\MigrationController;
use App\Http\Controllers\Landlord\TenantManagementController;
use App\Http\Controllers\Landlord\TenantUserController;
use App\Http\Controllers\LanguageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

foreach (config('tenancy.central_domains', []) as $centralDomain) {
    Route::domain($centralDomain)->group(function () {
        Route::get('/', function () {
            return Inertia::render('Welcome');
        });

        Route::get('/language/switch', [LanguageController::class, 'switch']);
    });
}

Route::get('/register-agency', [AgencyRegistrationController::class, 'show'])->name('agency.register');
Route::post('/register-agency', [AgencyRegistrationController::class, 'store']);
Route::get('/register-agency/success', [AgencyRegistrationController::class, 'success'])->name('agency.registration.success');
Route::get('/agency', fn () => Inertia::render('Agency/Login'))->name('agency.login');

Route::prefix('admin')->name('landlord.')->group(function () {
    Route::get('login', [LandlordAuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [LandlordAuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('logout', [LandlordAuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::middleware('landlord.auth')->group(function () {
        Route::get('dashboard', LandlordDashboardController::class)->name('dashboard');
        Route::get('settings/flight-cache', [GlobalFlightCacheSettingsController::class, 'index'])->name('settings.flight-cache.index');
        Route::patch('settings/flight-cache', [GlobalFlightCacheSettingsController::class, 'update'])->name('settings.flight-cache.update');
        Route::get('tenants', [TenantManagementController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenantRecord}', [TenantManagementController::class, 'show'])->name('tenants.show');
        Route::patch('tenants/{tenantRecord}/status', [TenantManagementController::class, 'updateStatus'])->name('tenants.status');

        Route::post('tenants/{tenantRecord}/users', [TenantUserController::class, 'store'])->name('tenants.users.store');
        Route::put('tenants/{tenantRecord}/users/{user}', [TenantUserController::class, 'update'])->name('tenants.users.update');
        Route::delete('tenants/{tenantRecord}/users/{user}', [TenantUserController::class, 'destroy'])->name('tenants.users.destroy');

        // Agency Wallet & Master Agency Management
        Route::post('tenants/{tenantRecord}/wallet-topup', [AgencyWalletController::class, 'topUp'])->name('tenants.wallet.topup');
        Route::patch('tenants/{tenantRecord}/default-agency', [AgencyWalletController::class, 'setDefaultAgency'])->name('tenants.default-agency');
        Route::patch('tenants/{tenantRecord}/credentials-permission', [AgencyWalletController::class, 'updateCredentialsPermission'])->name('tenants.credentials-permission');
        Route::patch('tenants/{tenantRecord}/agency-settings', [AgencyWalletController::class, 'updateAgencySettings'])->name('tenants.agency-settings');
        Route::patch('tenants/{tenantRecord}/default-agency-settings', [AgencyWalletController::class, 'updateDefaultAgencySettings'])->name('tenants.default-agency-settings');

        // Airport Management
        Route::resource('airports', AirportController::class)->names('airports');
        Route::patch('airports/{airport}/toggle-registration', [AirportController::class, 'toggleRegistration'])->name('airports.toggle-registration');

        Route::get('countries', [CountryController::class, 'index'])->name('countries.index');
        Route::patch('countries/{country}/toggle-esim-featured', [CountryController::class, 'toggleEsimFeatured'])->name('countries.toggle-esim-featured');

        // Legacy Agent Migration
        Route::prefix('migration')->name('migration.')->group(function () {
            Route::get('/', [MigrationController::class, 'index'])->name('index');
            Route::get('/agents', [MigrationController::class, 'agents'])->name('agents');
            Route::post('/run', [MigrationController::class, 'run'])->name('run');
            Route::get('/status/{record}', [MigrationController::class, 'status'])->name('status');
            Route::get('/report/{record}', [MigrationController::class, 'report'])->name('report');
        });
    });
});
