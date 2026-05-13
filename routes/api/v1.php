<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AirlineController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FlightController;
use App\Http\Controllers\Api\V1\HotelController;
use App\Http\Controllers\Api\V1\InsuranceController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\OrangeInsuranceController;
use App\Http\Controllers\Tenant\TicketController;
use App\Http\Controllers\Tenant\TravelInsuranceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 Routes
|--------------------------------------------------------------------------
|
| Version 1 endpoints. Tenancy middleware and /v1 prefix are applied
| by routes/api.php. Define only route groups and endpoints here.
|
*/

// ── Public Auth ────────────────────────────────────────────────
Route::post('auth/token', [AuthController::class, 'login']);

// ── Authenticated Routes ───────────────────────────────────────
Route::middleware('auth:sanctum')->group(function (): void {
    // Auth
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::delete('auth/token', [AuthController::class, 'revoke']);
    Route::get('auth/tokens', [AuthController::class, 'tokens']);

    // ── Reference Data ──────────────────────────────────────────
    Route::get('airports/search', [\App\Http\Controllers\AirportController::class, 'search']);
    Route::get('airlines', [AirlineController::class, 'index']);

    // ── Flights ─────────────────────────────────────────────────
    Route::post('flights/search', [FlightController::class, 'search']);
    Route::get('flights/results/{uuid}', [FlightController::class, 'results']);
    Route::get('flights/calendar-hints', [BookingController::class, 'calendarHints']);
    Route::post('flights/return-options', [BookingController::class, 'getReturnOptions']);
    Route::post('flights/select', [FlightController::class, 'select']);
    Route::post('flights/book', [FlightController::class, 'book']);

    // ── Hotels ──────────────────────────────────────────────────
    Route::get('hotels/autocomplete', [HotelController::class, 'autocomplete']);
    Route::post('hotels/search', [HotelController::class, 'search']);
    Route::get('hotels/details', [HotelController::class, 'details']);
    Route::post('hotels/select', [HotelController::class, 'select']);
    Route::post('hotels/book', [HotelController::class, 'book']);

    // ── Insurance ────────────────────────────────────────────────
    Route::get('insurance/compulsory/references/{type}', [InsuranceController::class, 'compulsoryReferences'])
        ->whereIn('type', ['durations', 'document-types', 'vehicle-types', 'colors', 'licensing-authorities']);
    Route::get('insurance/travel/references', [TravelInsuranceController::class, 'references']);
    Route::get('insurance/orange/references', [OrangeInsuranceController::class, 'references']);

    Route::post('insurance/compulsory/price', [InsuranceController::class, 'compulsoryPrice']);
    Route::post('insurance/compulsory/issue', [InsuranceController::class, 'compulsoryIssue']);
    Route::post('insurance/travel/price', [TravelInsuranceController::class, 'price']);
    Route::post('insurance/orange/price', [OrangeInsuranceController::class, 'price']);

    // ── Orders ──────────────────────────────────────────────────
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::get('orders/{order}/flight-items/{item}/ticket-pdf', [OrderController::class, 'ticketPdf']);

    // ── Dashboard ───────────────────────────────────────────────
    Route::get('dashboard', [DashboardController::class, 'index']);

    // ── Reports ─────────────────────────────────────────────────
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/commissions', [ReportController::class, 'commissions']);

    // ── Wallet ──────────────────────────────────────────────────
    Route::get('wallet/balance', [WalletController::class, 'balance']);
    Route::get('wallet/transactions', [WalletController::class, 'transactions']);

    // ── Ticket Operations (Manager) ──────────────────────────────
    Route::post('flights/{booking}/tickets/issue', [TicketController::class, 'issue']);
    Route::post('flights/{booking}/tickets/{ticket}/void', [TicketController::class, 'void']);
    Route::post('flights/{booking}/tickets/{ticket}/refund', [TicketController::class, 'refund']);
});
