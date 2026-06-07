<?php

declare(strict_types=1);

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\Tenant\Accounting\AccountingDashboardController;
use App\Http\Controllers\Tenant\Accounting\AccountingReportController;
use App\Http\Controllers\Tenant\Accounting\AccountingSettingsController;
use App\Http\Controllers\Tenant\Accounting\CancellationAuditController;
use App\Http\Controllers\Tenant\Accounting\IssuanceHistoryController;
use App\Http\Controllers\Tenant\Accounting\LedgerController;
use App\Http\Controllers\Tenant\Accounting\ProviderWalletController;
use App\Http\Controllers\Tenant\Accounting\SettlementLedgerController;
use App\Http\Controllers\Tenant\Accounting\WalletLedgerController;
use App\Http\Controllers\Tenant\AirlineConfigController;
use App\Http\Controllers\Tenant\ApiTokenController;
use App\Http\Controllers\Tenant\BookingController;
use App\Http\Controllers\Tenant\CompulsoryInsuranceController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\ESimBookingController;
use App\Http\Controllers\Tenant\ESimConfigController;
use App\Http\Controllers\Tenant\HotelBookingController;
use App\Http\Controllers\Tenant\HotelConfigController;
use App\Http\Controllers\Tenant\InsuranceBookController;
use App\Http\Controllers\Tenant\InsuranceConfigController;
use App\Http\Controllers\Tenant\InsurancePolicyController;
use App\Http\Controllers\Tenant\InsuranceQuoteController;
use App\Http\Controllers\Tenant\InsuranceSearchController;
use App\Http\Controllers\Tenant\NetworkController;
use App\Http\Controllers\Tenant\OrangeInsuranceController;
use App\Http\Controllers\Tenant\OrderController;
use App\Http\Controllers\Tenant\ReportController;
use App\Http\Controllers\Tenant\SettingController;
use App\Http\Controllers\Tenant\TicketController;
use App\Http\Controllers\Tenant\TravelInsuranceController;
use App\Http\Controllers\Tenant\UserController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Controllers\CsrfCookieController;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;

Route::prefix(config('tenancy.tenant_path_prefix', 'agency').'/{tenant}')->group(function (): void {
    Route::get('/sanctum/csrf-cookie', [CsrfCookieController::class, 'show'])
        ->middleware(['web'])->name('sanctum.csrf-cookie');

    Route::middleware([
        'web',
        InitializeTenancyByPath::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ])->group(function (): void {
        Route::get('/', fn () => inertia('Welcome'))->name('home');

        Route::get('/language/switch', [LanguageController::class, 'switch'])->name('language.switch');

        Route::middleware(['auth', 'tenant.status'])->group(function (): void {
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
            Route::post('flights/fare-rules', [BookingController::class, 'fareRules'])->name('flights.fare-rules');
            Route::post('flights/select', [BookingController::class, 'select'])->name('flights.select');
            Route::get('flights/passengers/{uuid}', [BookingController::class, 'passengers'])->name('flights.passengers');
            Route::post('flights/passengers/scan-passport', [BookingController::class, 'scanPassport'])->name('flights.scan-passport');
            Route::post('flights', [BookingController::class, 'store'])->middleware('wallet.balance')->name('flights.store');
            Route::get('flights/{booking}', [BookingController::class, 'show'])->name('flights.show');
            Route::get('flights/{booking}/completed', [TicketController::class, 'completed'])->name('tickets.completed');
            Route::get('flights/{booking}/tickets/{ticket}/change-offers', [BookingController::class, 'changeOffers'])->name('flights.change-offers');
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}/flight-items/{item}/ticket-pdf', [OrderController::class, 'flightTicketPdf'])->name('orders.flight-items.ticket-pdf');
            Route::get('orders/{order}/hotel-items/{item}/voucher-pdf', [OrderController::class, 'hotelVoucherPdf'])->name('orders.hotel-items.voucher-pdf');
            Route::get('orders/{order}/insurance-items/{item}/policy-pdf', [OrderController::class, 'insurancePolicyPdf'])->name('orders.insurance-items.policy-pdf');
            Route::get('orders/{order}/summary-pdf', [OrderController::class, 'orderSummaryPdf'])->name('orders.summary-pdf');
            Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');

            // Hotels
            Route::middleware('role:admin,manager,agent')->group(function (): void {
                Route::get('hotels', [HotelBookingController::class, 'index'])->name('hotels.index');
                Route::get('api/hotels/autocomplete', [HotelBookingController::class, 'autocomplete'])->name('hotels.autocomplete');
                Route::post('hotels/search', [HotelBookingController::class, 'search'])->name('hotels.search');
                Route::get('hotels/results/{uuid}', [HotelBookingController::class, 'results'])->name('hotels.results');
                Route::get('hotels/results/{uuid}/availability', [HotelBookingController::class, 'availability'])->name('hotels.availability');
                Route::get('api/hotels/hotel-info', [HotelBookingController::class, 'hotelInfo'])->name('hotels.hotel-info');
                Route::post('hotels/select', [HotelBookingController::class, 'select'])->name('hotels.select');
                Route::get('hotels/details/{uuid}', [HotelBookingController::class, 'details'])->name('hotels.details');
                Route::post('hotels/book', [HotelBookingController::class, 'book'])->name('hotels.book');
                Route::post('orders/{order}/hotel-items/{item}/cancel', [HotelBookingController::class, 'cancel'])->name('hotels.order-items.cancel');
            });

            // eSIM
            Route::middleware('role:admin,manager,agent')->group(function (): void {
                Route::get('esim', [ESimBookingController::class, 'index'])->name('esim.index');
                Route::post('esim/search', [ESimBookingController::class, 'search'])->name('esim.search');
                Route::get('esim/results/{uuid}', [ESimBookingController::class, 'results'])->name('esim.results');
                Route::get('esim/results/{uuid}/packages', [ESimBookingController::class, 'packages'])->name('esim.packages');
                Route::get('esim/results/{uuid}/networks', [ESimBookingController::class, 'networks'])->name('esim.networks');
                Route::get('esim/airport/{iata}/packages', [ESimBookingController::class, 'airportPackages'])->name('esim.airport.packages');
                Route::post('esim/select/{uuid}', [ESimBookingController::class, 'select'])->name('esim.select');
                Route::get('esim/checkout/{uuid}', [ESimBookingController::class, 'checkout'])->name('esim.checkout');
                Route::post('esim/book', [ESimBookingController::class, 'book'])->name('esim.book');
            });

            // Insurance
            Route::middleware('role:admin,manager,agent')->group(function (): void {
                Route::get('insurance/search', [InsuranceSearchController::class, 'index'])->name('insurance.search');

                Route::get('insurance/compulsory/search', [CompulsoryInsuranceController::class, 'searchPage'])->name('insurance.compulsory.search');
                Route::get('insurance/compulsory/beneficiary/{quoteToken}', [CompulsoryInsuranceController::class, 'beneficiaryPage'])->name('insurance.compulsory.beneficiary');
                Route::get('insurance/compulsory/issued/{order}', [CompulsoryInsuranceController::class, 'issuedPage'])->name('insurance.compulsory.issued');

                Route::get('insurance/travel/beneficiary/{quoteToken}', [TravelInsuranceController::class, 'beneficiaryPage'])->name('insurance.travel.beneficiary');
                Route::get('insurance/travel/references', [TravelInsuranceController::class, 'references'])->name('insurance.travel.references');
                Route::post('insurance/travel/price', [TravelInsuranceController::class, 'price'])->name('insurance.travel.price');
                Route::post('insurance/travel/issue', [TravelInsuranceController::class, 'issue'])->name('insurance.travel.issue');

                Route::get('insurance/orange/beneficiary/{quoteToken}', [OrangeInsuranceController::class, 'beneficiaryPage'])->name('insurance.orange.beneficiary');
                Route::get('insurance/orange/references', [OrangeInsuranceController::class, 'references'])->name('insurance.orange.references');
                Route::post('insurance/orange/price', [OrangeInsuranceController::class, 'price'])->name('insurance.orange.price');
                Route::post('insurance/orange/issue', [OrangeInsuranceController::class, 'issue'])->name('insurance.orange.issue');

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
            Route::middleware('role:admin')->group(function (): void {
                Route::get('reports/reconciliation', [ReportController::class, 'reconciliation'])->name('reports.reconciliation');
            });

            Route::middleware('role:manager')->group(function (): void {
                Route::post('flights/{booking}/tickets/issue', [TicketController::class, 'issue'])->name('tickets.issue');
                Route::post('flights/{booking}/tickets/{ticket}/void', [TicketController::class, 'void'])->name('tickets.void');
                Route::get('flights/{booking}/tickets/{ticket}/refund-quote', [TicketController::class, 'refundQuote'])->name('tickets.refundQuote');
                Route::post('flights/{booking}/tickets/{ticket}/refund', [TicketController::class, 'refund'])->name('tickets.refund');
                Route::get('flights/{booking}/tickets/{ticket}/change-quote', [TicketController::class, 'changeQuote'])->name('tickets.changeQuote');
                Route::post('flights/{booking}/tickets/{ticket}/change-review', [TicketController::class, 'changeReview'])->name('tickets.changeReview');
                Route::get('flights/{booking}/tickets/{ticket}/change-confirmation', [TicketController::class, 'changeConfirmation'])->name('tickets.changeConfirmation');
                Route::post('flights/{booking}/tickets/{ticket}/change-confirm', [TicketController::class, 'confirmChange'])->name('tickets.confirmChange');
            });

            // User Management (Admin Only)
            Route::middleware('role:admin')->group(function (): void {
                Route::resource('users', UserController::class);
                Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

                // Agency Network
                Route::get('network', [NetworkController::class, 'index'])->name('network.index');
                Route::post('network/invitations', [NetworkController::class, 'invite'])->name('network.invite');
                Route::post('network/join', [NetworkController::class, 'join'])->name('network.join');
                Route::post('network/{membership}/accept', [NetworkController::class, 'accept'])->name('network.accept');
                Route::patch('network/{membership}/suspend', [NetworkController::class, 'suspend'])->name('network.suspend');
                Route::patch('network/{membership}/revoke', [NetworkController::class, 'revoke'])->name('network.revoke');

                // Airline Configuration
                Route::get('settings/airlines', [AirlineConfigController::class, 'index'])->name('settings.airlines.index');
                Route::post('settings/airlines', [AirlineConfigController::class, 'store'])->name('settings.airlines.store');
                Route::post('settings/airlines/test', [AirlineConfigController::class, 'testConnection'])->name('settings.airlines.test');
                Route::post('settings/airlines/deposit', [AirlineConfigController::class, 'deposit'])->name('settings.airlines.deposit');
                Route::patch('settings/airlines/{provider}/toggle', [AirlineConfigController::class, 'toggle'])->name('settings.airlines.toggle');
                Route::get('settings/airlines/{provider}/terminal', [AirlineConfigController::class, 'terminalPage'])->name('settings.airlines.terminal');
                Route::post('settings/airlines/{provider}/terminal', [AirlineConfigController::class, 'terminal'])->name('settings.airlines.terminal.run');

                // General Tenant Settings
                Route::get('settings/general', [SettingController::class, 'index'])->name('settings.general.index');
                Route::post('settings/general', [SettingController::class, 'update'])->name('settings.general.update');

                // Insurance Provider Configuration
                Route::get('settings/insurance', [InsuranceConfigController::class, 'index'])->name('settings.insurance.index');
                Route::post('settings/insurance', [InsuranceConfigController::class, 'store'])->name('settings.insurance.store');
                Route::post('settings/insurance/deposit', [InsuranceConfigController::class, 'deposit'])->name('settings.insurance.deposit');

                // Hotel Provider Configuration
                Route::get('settings/hotels', [HotelConfigController::class, 'index'])->name('settings.hotels.index');
                Route::post('settings/hotels', [HotelConfigController::class, 'store'])->name('settings.hotels.store');
                Route::post('settings/hotels/deposit', [HotelConfigController::class, 'deposit'])->name('settings.hotels.deposit');
                Route::post('settings/hotels/credit-check', [HotelConfigController::class, 'syncCredit'])->name('settings.hotels.credit-check');

                // eSIM Provider Configuration
                Route::get('settings/esim', [ESimConfigController::class, 'index'])->name('settings.esim.index');
                Route::post('settings/esim', [ESimConfigController::class, 'store'])->name('settings.esim.store');
                Route::post('settings/esim/deposit', [ESimConfigController::class, 'deposit'])->name('settings.esim.deposit');

                // API Token Management
                Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('settings.api-tokens.index');
                Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->name('settings.api-tokens.store');
                Route::delete('settings/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('settings.api-tokens.destroy');
            });
        });

        // Accounting
        Route::prefix('accounting')->name('accounting.')->middleware('role:admin')->group(function (): void {
            Route::get('/', [AccountingDashboardController::class, 'index'])->name('dashboard');

            // Wallets
            Route::get('/wallets', [WalletLedgerController::class, 'index'])->name('wallets.index');
            Route::get('/wallets/{wallet}', [WalletLedgerController::class, 'show'])->name('wallets.show');
            Route::post('/wallets/{wallet}/deposit', [WalletLedgerController::class, 'deposit'])->name('wallets.deposit');

            // Provider wallets
            Route::get('/providers', [ProviderWalletController::class, 'index'])->name('providers.index');
            Route::get('/providers/{provider}', [ProviderWalletController::class, 'show'])->name('providers.show');

            // Ledger
            Route::get('/ledger/journal', [LedgerController::class, 'journalEntries'])->name('ledger.journal');
            Route::get('/ledger/trial-balance', [LedgerController::class, 'trialBalance'])->name('ledger.trial-balance');
            Route::get('/ledger/chart-of-accounts', [LedgerController::class, 'chartOfAccounts'])->name('ledger.coa');
            Route::get('/ledger/accounts/{code}', [LedgerController::class, 'accountDetail'])->name('ledger.account');

            // Issuance history
            Route::get('/issuances', [IssuanceHistoryController::class, 'index'])->name('issuances.index');

            // Settlement
            Route::get('/settlement', [SettlementLedgerController::class, 'index'])->name('settlement.index');
            Route::get('/settlement/aging', [SettlementLedgerController::class, 'aging'])->name('settlement.aging');
            Route::get('/settlement/{batch}', [SettlementLedgerController::class, 'batch'])->name('settlement.batch');
            Route::post('/settlement/run', [SettlementLedgerController::class, 'run'])->name('settlement.run');

            // Cancellations
            Route::get('/cancellations', [CancellationAuditController::class, 'index'])->name('cancellations.index');

            // Reports
            Route::get('/reports', [AccountingReportController::class, 'index'])->name('reports.index');
            Route::get('/reports/revenue', [AccountingReportController::class, 'revenue'])->name('reports.revenue');
            Route::get('/reports/gross-margin', [AccountingReportController::class, 'grossMargin'])->name('reports.gross-margin');
            Route::get('/reports/vat', [AccountingReportController::class, 'vat'])->name('reports.vat');
            Route::get('/reports/reconciliation', [AccountingReportController::class, 'reconciliation'])->name('reports.reconciliation');

            // Settings
            Route::get('/settings', [AccountingSettingsController::class, 'index'])->name('settings.index');
            Route::put('/settings', [AccountingSettingsController::class, 'update'])->name('settings.update');
        });

        require __DIR__.'/settings.php';
        require __DIR__.'/auth.php';
    });
});
