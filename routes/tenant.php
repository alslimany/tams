<?php

declare(strict_types=1);

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\Tenant\AirlineConfigController;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\CompulsoryInsuranceController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\InsuranceBookController;
use App\Http\Controllers\Tenant\InsuranceConfigController;
use App\Http\Controllers\Tenant\InsurancePolicyController;
use App\Http\Controllers\Tenant\InsuranceQuoteController;
use App\Http\Controllers\Tenant\InsuranceSearchController;
use App\Http\Controllers\Tenant\OrderController;
use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\TicketController;
use App\Http\Controllers\Tenant\TravelInsuranceController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;
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
            InitializeTenancyByDomain::class,
            PreventAccessFromCentralDomains::class,
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

    // Language switching route
    Route::get('/language/switch', [LanguageController::class, 'switch'])->name('language.switch');

    Route::middleware(['auth', 'tenant.status'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Flight search, booking and orders
        Route::get('api/airports/search', [\App\Http\Controllers\AirportController::class, 'search'])->name('api.airports.search');
        Route::get('api/airlines/logo/{code}', [\App\Http\Controllers\AirlineLogoController::class, 'show'])->name('api.airlines.logo');
        Route::get('flights', [BookingController::class, 'index'])->name('flights.index');
        Route::match(['get', 'post'], 'flights/search', [BookingController::class, 'search'])->name('flights.search');
        Route::match(['get', 'post'], 'flights/results/{uuid}', [BookingController::class, 'results'])->name('flights.results');
        Route::post('flights/fetch-flights', [BookingController::class, 'fetchFlights'])->name('flights.fetch-flights');
        Route::post('flights/return-options', [BookingController::class, 'getReturnOptions'])->name('flights.return-options');
        Route::get('api/flights/calendar-hints', [BookingController::class, 'calendarHints'])->name('flights.calendar-hints');
        Route::post('flights/open-reservation-availability', [BookingController::class, 'openReservationAvailability'])->name('flights.open-reservation-availability');
        Route::post('flights/seatmap', [BookingController::class, 'seatmap'])->name('flights.seatmap');
        Route::post('flights/select', [BookingController::class, 'select'])->name('flights.select');
        Route::get('flights/passengers/{uuid}', [BookingController::class, 'passengers'])->name('flights.passengers');
        Route::post('flights', [BookingController::class, 'store'])->middleware('wallet.balance')->name('flights.store');
        Route::get('flights/{booking}', [BookingController::class, 'show'])->name('flights.show');
        Route::get('flights/{booking}/completed', [TicketController::class, 'completed'])->name('tickets.completed');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

        // Insurance
        Route::middleware('role:admin,manager,agent')->group(function () {
            Route::get('insurance/search', [InsuranceSearchController::class, 'index'])->name('insurance.search');

            Route::get('insurance/compulsory/search', [CompulsoryInsuranceController::class, 'searchPage'])->name('insurance.compulsory.search');
            Route::get('insurance/compulsory/beneficiary/{quoteToken}', [CompulsoryInsuranceController::class, 'beneficiaryPage'])->name('insurance.compulsory.beneficiary');
            Route::get('insurance/compulsory/issued/{order}', [CompulsoryInsuranceController::class, 'issuedPage'])->name('insurance.compulsory.issued');

            Route::get('insurance/travel/beneficiary/{quoteToken}', [TravelInsuranceController::class, 'beneficiaryPage'])->name('insurance.travel.beneficiary');
            Route::get('insurance/travel/references', [TravelInsuranceController::class, 'references'])->name('insurance.travel.references');
            Route::post('insurance/travel/price', [TravelInsuranceController::class, 'price'])->name('insurance.travel.price');
            Route::post('insurance/travel/issue', [TravelInsuranceController::class, 'issue'])->name('insurance.travel.issue');

            Route::get('insurance/compulsory/references/durations', [CompulsoryInsuranceController::class, 'durationsReference'])->name('insurance.compulsory.references.durations');
            Route::get('insurance/compulsory/references/document-types', [CompulsoryInsuranceController::class, 'documentTypesReference'])->name('insurance.compulsory.references.document-types');
            Route::get('insurance/compulsory/references/vehicle-types', [CompulsoryInsuranceController::class, 'vehicleTypesReference'])->name('insurance.compulsory.references.vehicle-types');
            Route::get('insurance/compulsory/references/colors', [CompulsoryInsuranceController::class, 'colorsReference'])->name('insurance.compulsory.references.colors');
            Route::get('insurance/compulsory/references/licensing-authorities', [CompulsoryInsuranceController::class, 'licensingAuthoritiesReference'])->name('insurance.compulsory.references.licensing-authorities');
            Route::post('insurance/compulsory/price', [CompulsoryInsuranceController::class, 'price'])->name('insurance.compulsory.price');
            Route::post('insurance/compulsory/issue', [CompulsoryInsuranceController::class, 'issue'])->name('insurance.compulsory.issue');

            Route::get('api/insurance/lookups/{productType}/{lookupKey}', [InsuranceSearchController::class, 'lookup'])->name('insurance.lookups');
            Route::post('insurance/quote', [InsuranceQuoteController::class, 'store'])->name('insurance.quote');
            Route::post('insurance/book', [InsuranceBookController::class, 'store'])->name('insurance.book');
            Route::get('insurance/report', [InsurancePolicyController::class, 'report'])->name('insurance.report');
            Route::post('insurance/cancel', [InsurancePolicyController::class, 'cancel'])->name('insurance.cancel');
            Route::post('orders/{order}/insurance-items/{item}/cancel', [InsurancePolicyController::class, 'submitCancellation'])->name('insurance.order-items.cancel');
            Route::post('orders/{order}/insurance-items/{item}/finalize-cancellation', [InsurancePolicyController::class, 'finalizeCancellation'])->name('insurance.order-items.finalize-cancellation');
            Route::get('orders/{order}/insurance-items/{item}/report', [InsurancePolicyController::class, 'itemReport'])->name('insurance.order-items.report');
        });

        // Reports
        Route::get('reports/sales', [ReportController::class, 'dailySales'])->name('reports.sales');
        Route::get('reports/commissions', [ReportController::class, 'commissions'])->name('reports.commissions');
        Route::get('reports/taxes', [ReportController::class, 'taxes'])->name('reports.taxes');
        Route::get('wallet/transactions', [ReportController::class, 'walletTransactions'])->name('wallet.transactions');
        Route::middleware('role:admin')->group(function () {
            Route::get('reports/reconciliation', [ReportController::class, 'reconciliation'])->name('reports.reconciliation');
        });

        Route::middleware('role:manager')->group(function () {
            Route::post('flights/{booking}/tickets/issue', [TicketController::class, 'issue'])->name('tickets.issue');
            Route::post('flights/{booking}/tickets/{ticket}/void', [TicketController::class, 'void'])->name('tickets.void');
            Route::post('flights/{booking}/tickets/{ticket}/refund', [TicketController::class, 'refund'])->name('tickets.refund');
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

            // Insurance Provider Configuration
            Route::get('settings/insurance', [InsuranceConfigController::class, 'index'])->name('settings.insurance.index');
            Route::post('settings/insurance', [InsuranceConfigController::class, 'store'])->name('settings.insurance.store');
        });

    });

    require __DIR__.'/settings.php';
    require __DIR__.'/auth.php';

});
