<?php

namespace App\Http\Controllers\Tenant;

use App\DTOs\Insurance\InsuranceQuoteRequest;
use App\Http\Controllers\Controller;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class InsuranceQuoteController extends Controller
{
    public function __construct(
        protected InsuranceProviderManager $providerManager,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_type' => ['required', 'string', Rule::in(['compulsory', 'travel', 'orange'])],
            'payload' => ['required', 'array'],
        ]);

        try {
            $quote = $this->providerManager->provider()->quote(new InsuranceQuoteRequest(
                productType: $validated['product_type'],
                payload: $validated['payload'],
            ));

            return back()->with('insurance_quote', $quote->toArray());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }
}
