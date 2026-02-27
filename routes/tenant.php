<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Tenant\UserController;
use App\Http\Controllers\Tenant\AirlineConfigController;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::group(['prefix' => config('sanctum.prefix', 'sanctum')], static function () {
    Route::get('/csrf-cookie', [CsrfCookieController::class, 'show'])
        ->middleware([
            'web',
            InitializeTenancyByDomain::class
        ])->name('sanctum.csrf-cookie');
});

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return inertia('Welcome');
    })->name('home');

    Route::middleware(['auth'])->group(function () {
        Route::get('dashboard', function () {
            return inertia('Tenant/Dashboard');
        })->name('dashboard');
        
        // User Management (Admin Only)
        Route::middleware('role:admin')->group(function () {
            Route::resource('users', UserController::class);
            Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
            
            // Airline Configuration
            Route::get('settings/airlines', [AirlineConfigController::class, 'index'])->name('settings.airlines.index');
            Route::post('settings/airlines', [AirlineConfigController::class, 'store'])->name('settings.airlines.store');
            Route::post('settings/airlines/test', [AirlineConfigController::class, 'testConnection'])->name('settings.airlines.test');
            Route::patch('settings/airlines/{provider}/toggle', [AirlineConfigController::class, 'toggle'])->name('settings.airlines.toggle');
        });
    });

    require __DIR__.'/settings.php';
    require __DIR__.'/auth.php';
});
