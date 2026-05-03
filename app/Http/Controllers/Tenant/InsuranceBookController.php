<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\CreateOrderFromInsuranceBooking;
use App\DTOs\Insurance\InsuranceBookingRequest;
use App\Http\Controllers\Controller;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class InsuranceBookController extends Controller
{
    public function __construct(
        protected InsuranceProviderManager $providerManager,
        protected CreateOrderFromInsuranceBooking $createOrderFromInsuranceBooking,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_type' => ['required', 'string', Rule::in(['compulsory', 'travel', 'orange'])],
            'payload' => ['required', 'array'],
        ]);

        try {
            $provider = $this->providerManager->activeProvider();
            $booking = $this->providerManager->provider()->book(new InsuranceBookingRequest(
                productType: $validated['product_type'],
                payload: $validated['payload'],
            ));

            $order = $this->createOrderFromInsuranceBooking->execute(
                productSubtype: $validated['product_type'],
                bookingResult: $booking,
                requestPayload: $validated['payload'],
                insuranceProvider: $provider,
            );

            return redirect()
                ->route('orders.show', $order)
                ->with('success', 'Insurance policy issued and order posted successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }
}
