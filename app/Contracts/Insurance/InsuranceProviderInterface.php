<?php

namespace App\Contracts\Insurance;

use App\DTOs\Insurance\InsuranceBookingRequest;
use App\DTOs\Insurance\InsuranceBookingResult;
use App\DTOs\Insurance\InsuranceQuoteRequest;
use App\DTOs\Insurance\InsuranceQuoteResult;

interface InsuranceProviderInterface
{
    public function quote(InsuranceQuoteRequest $request): InsuranceQuoteResult;

    public function book(InsuranceBookingRequest $request): InsuranceBookingResult;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lookup(string $productType, string $lookupKey): array;

    public function policyReportUrl(string $productType, string $reportReference): string;

    /**
     * @return array{content:string,content_type:string}
     */
    public function fetchPolicyReport(string $productType, string $reportReference): array;

    /**
     * @return array{insurance_policy_id:int|null,remarks:?string,raw:array<string,mixed>}
     */
    public function cancel(string $productType, int $insurancePolicyId, string $remarks): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCancellationRequests(string $dateFrom, string $dateTo): array;
}
