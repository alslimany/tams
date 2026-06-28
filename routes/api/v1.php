<?php

declare(strict_types=1);

use App\Http\Controllers\AirlineLogoController;
use App\Http\Controllers\Api\V1\AirlineController;
use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FlightChangeController;
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
| Token abilities:
|   read   — GET endpoints (search, results, orders, reports, wallet)
|   write  — booking/selection endpoints (search, select, book)
|   issue  — ticket/policy issuance, refund, void
|   report — reports and dashboard
|   *      — full access (default when no abilities specified)
|
*/

// ── Public Auth ────────────────────────────────────────────────
Route::post('auth/token', [AuthController::class, 'login']);

// ── Authenticated Routes ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'throttle:api', 'audit.api', 'idempotency'])->group(function (): void {

    // Auth — no ability restriction (token management is always allowed)
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::delete('auth/token', [AuthController::class, 'revoke']);
    Route::get('auth/tokens', [AuthController::class, 'tokens']);
    Route::post('auth/tokens', [ApiTokenController::class, 'store']);
    Route::delete('auth/tokens/{tokenId}', [ApiTokenController::class, 'destroy']);

    // ── Read-gated routes (ability: read OR *) ──────────────────
    Route::middleware('ability:read')->group(function (): void {
        // Reference data
        Route::get('airports/search', [\App\Http\Controllers\AirportController::class, 'search']);
        Route::get('airlines', [AirlineController::class, 'index']);
        Route::get('airlines/{code}/logo', [AirlineLogoController::class, 'show'])
            ->where('code', '[A-Za-z0-9]+');

        // Flight results
        Route::get('flights/results/{uuid}', [FlightController::class, 'results']);
        Route::get('flights/calendar-hints', [BookingController::class, 'calendarHints']);
        Route::post('flights/fare-rules', [FlightController::class, 'fareRules']);
        Route::post('flights/seatmap', [FlightController::class, 'seatmap']);

        // Hotel reference
        Route::get('hotels/autocomplete', [HotelController::class, 'autocomplete']);
        Route::get('hotels/details', [HotelController::class, 'details']);

        // Insurance references
        Route::get('insurance/compulsory/references/{type}', [InsuranceController::class, 'compulsoryReferences'])
            ->whereIn('type', ['durations', 'document-types', 'vehicle-types', 'colors', 'licensing-authorities']);
        Route::get('insurance/travel/references', [TravelInsuranceController::class, 'references']);
        Route::get('insurance/orange/references', [OrangeInsuranceController::class, 'references']);

        // Orders
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::get('orders/{order}/flight-items/{item}/ticket-pdf', [OrderController::class, 'ticketPdf']);
        Route::get('orders/{order}/hotel-items/{item}/voucher-pdf', [OrderController::class, 'hotelVoucherPdf']);
        Route::get('orders/{order}/insurance-items/{item}/policy-pdf', [OrderController::class, 'insurancePolicyPdf']);
        Route::get('orders/{order}/summary-pdf', [OrderController::class, 'orderSummaryPdf']);

        // Wallet
        Route::get('wallet/balance', [WalletController::class, 'balance']);
        Route::get('wallet/transactions', [WalletController::class, 'transactions']);
    });

    // ── Write-gated routes (ability: write OR *) ────────────────
    Route::middleware('ability:write')->group(function (): void {
        Route::post('flights/search', [FlightController::class, 'search']);
        Route::post('flights/return-options', [BookingController::class, 'getReturnOptions']);
        Route::post('flights/select', [FlightController::class, 'select']);
        Route::post('flights/price', [FlightController::class, 'price']);
        Route::post('flights/book', [FlightController::class, 'book']);

        Route::post('hotels/search', [HotelController::class, 'search']);
        Route::post('hotels/select', [HotelController::class, 'select']);
        Route::post('hotels/book', [HotelController::class, 'book']);

        Route::post('insurance/compulsory/price', [InsuranceController::class, 'compulsoryPrice']);
        Route::post('insurance/travel/price', [TravelInsuranceController::class, 'price']);
        Route::post('insurance/orange/price', [OrangeInsuranceController::class, 'price']);
    });

    // ── Issue-gated routes (ability: issue OR *) ────────────────
    Route::middleware('ability:issue')->group(function (): void {
        Route::post('flights/{order}/items/{item}/change/search', [FlightChangeController::class, 'search']);
        Route::get('flights/{order}/items/{item}/change-quote', [FlightChangeController::class, 'changeQuote']);
        Route::post('flights/{order}/items/{item}/change-confirm', [FlightChangeController::class, 'confirmChange']);

        Route::post('flights/{booking}/tickets/issue', [TicketController::class, 'issue']);
        Route::get('flights/{booking}/tickets/{ticket}/refund-quote', [TicketController::class, 'refundQuote']);
        Route::post('flights/{booking}/tickets/{ticket}/void', [TicketController::class, 'void']);
        Route::post('flights/{booking}/tickets/{ticket}/refund', [TicketController::class, 'refund']);
        Route::post('insurance/compulsory/issue', [InsuranceController::class, 'compulsoryIssue']);
    });

    // ── Report-gated routes (ability: report OR *) ──────────────
    Route::middleware('ability:report')->group(function (): void {
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/commissions', [ReportController::class, 'commissions']);
    });
});
