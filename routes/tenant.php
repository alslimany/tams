<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\AirlineConfigController;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\TicketController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
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
            InitializeTenancyByDomain::class,
        ])->name('sanctum.csrf-cookie');
});

Route::middleware([
    'web',
    InitializeTenancyByPath::class,
    PreventAccessFromCentralDomains::class,
])->prefix('/{tenant}')->group(function () {

    Route::get('/', function () {
        return inertia('Welcome');
    }
    )->name('home');

    Route::middleware(['auth', 'tenant.status'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Flight Bookings
        Route::get('api/airports/search', [\App\Http\Controllers\AirportController::class, 'search'])->name('api.airports.search');
        Route::get('api/airlines/logo/{code}', [\App\Http\Controllers\AirlineLogoController::class, 'show'])->name('api.airlines.logo');
        Route::get('bookings', [BookingController::class, 'index'])->name('bookings.index');
        Route::post('bookings/search', [BookingController::class, 'search'])->name('bookings.search');
        Route::match(['get', 'post'], 'bookings/results/{uuid}', [BookingController::class, 'results'])->name('bookings.results');
        Route::post('bookings/fetch-flights', [BookingController::class, 'fetchFlights'])->name('bookings.fetch-flights');
        Route::post('bookings/seatmap', [BookingController::class, 'seatmap'])->name('bookings.seatmap');
        Route::post('bookings/select', [BookingController::class, 'select'])->name('bookings.select');
        Route::post('bookings', [BookingController::class, 'store'])->name('bookings.store');
        Route::get('bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
        Route::middleware('role:manager')->group(function () {
            Route::post('bookings/{booking}/tickets/issue', [TicketController::class, 'issue'])->name('tickets.issue');
            Route::post('bookings/{booking}/tickets/{ticket}/void', [TicketController::class, 'void'])->name('tickets.void');
            Route::post('bookings/{booking}/tickets/{ticket}/refund', [TicketController::class, 'refund'])->name('tickets.refund');
        });

        // User Management (Admin Only)
        Route::middleware('role:admin')->group(function () {
            Route::resource('users', UserController::class);
            Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

            // Airline Configuration
            Route::get('settings/airlines', [AirlineConfigController::class, 'index'])->name('settings.airlines.index');
            Route::post('settings/airlines', [AirlineConfigController::class, 'store'])->name('settings.airlines.store');
            Route::post('settings/airlines/test', [AirlineConfigController::class, 'testConnection'])->name('settings.airlines.test');
            Route::patch('settings/airlines/{provider}/toggle', [AirlineConfigController::class, 'toggle'])->name('settings.airlines.toggle');

            // General Tenant Settings
            Route::get('settings/general', [\App\Http\Controllers\Tenant\SettingController::class, 'index'])->name('settings.general.index');
            Route::post('settings/general', [\App\Http\Controllers\Tenant\SettingController::class, 'update'])->name('settings.general.update');
        }
        );
    }
    );

    require __DIR__.'/settings.php';
    require __DIR__.'/auth.php';
});
