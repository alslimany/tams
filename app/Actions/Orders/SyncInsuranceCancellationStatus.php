<?php

namespace App\Actions\Orders;

use App\Models\Tenant\OrderItem;
use App\Services\Insurance\InsuranceProviderManager;
use Carbon\Carbon;

class SyncInsuranceCancellationStatus
{
    public function __construct(
        protected InsuranceProviderManager $providerManager,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(OrderItem $item): ?array
    {
        if ((string) $item->status !== 'cancellation' || (string) $item->type !== 'insurance') {
            return null;
        }

        $insurancePolicyId = $this->resolveInsurancePolicyId($item);

        if ($insurancePolicyId === null) {
            return null;
        }

        $cancellation = (array) data_get($item->item_details, 'insurance.cancellation', []);
        $requestedAt = (string) ($cancellation['requested_at'] ?? $item->updated_at?->toIso8601String() ?? now()->toIso8601String());
        $dateFrom = Carbon::parse($requestedAt)->startOfDay()->toIso8601String();
        $dateTo = now()->addDay()->endOfDay()->toIso8601String();

        $requests = $this->providerManager->provider()->listCancellationRequests($dateFrom, $dateTo);
        $latestRequest = $this->findLatestRequest($requests, $insurancePolicyId);

        if ($latestRequest === null) {
            return null;
        }

        $latestRemark = $this->extractRemark($latestRequest);
        if ($latestRemark === null) {
            return null;
        }

        $approvedAt = (string) ($cancellation['approved_at'] ?? '');

        data_set($cancellation, 'insurance_policy_id', $insurancePolicyId);
        data_set($cancellation, 'latest_remark', $latestRemark);
        data_set($cancellation, 'last_synced_at', now()->toIso8601String());
        data_set($cancellation, 'latest_response', $latestRequest);

        if ($latestRemark === 'تم الالغاء' && $approvedAt === '') {
            data_set($cancellation, 'approved_at', now()->toIso8601String());
        }

        $itemDetails = (array) $item->item_details;
        data_set($itemDetails, 'insurance.cancellation', $cancellation);

        $item->update([
            'item_details' => $itemDetails,
        ]);

        return $cancellation;
    }

    protected function resolveInsurancePolicyId(OrderItem $item): ?int
    {
        $candidates = [
            data_get($item->item_details, 'insurance.cancellation.insurance_policy_id'),
            data_get($item->product_details, 'policy_details.policy_id'),
            data_get($item->item_details, 'insurance.provider_response.data.Id'),
            data_get($item->item_details, 'insurance.provider_response.Id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>|null
     */
    protected function findLatestRequest(array $requests, int $insurancePolicyId): ?array
    {
        $matches = array_values(array_filter($requests, function (array $request) use ($insurancePolicyId): bool {
            return $this->extractRequestInsurancePolicyId($request) === $insurancePolicyId;
        }));

        if ($matches === []) {
            return null;
        }

        $latest = null;

        foreach ($matches as $match) {
            if (! is_array($latest) || $this->isNewerCancellationRequest($match, $latest)) {
                $latest = $match;
            }
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function extractRequestInsurancePolicyId(array $request): ?int
    {
        $candidates = [
            $request['InsurancePolicyId'] ?? null,
            $request['insurancePolicyId'] ?? null,
            $request['InsurancePoliciesNo'] ?? null,
            $request['insurancePoliciesNo'] ?? null,
            $request['policyNumber'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    protected function isNewerCancellationRequest(array $left, array $right): bool
    {
        $leftCreatedAt = $this->extractRequestCreatedAtTimestamp($left);
        $rightCreatedAt = $this->extractRequestCreatedAtTimestamp($right);

        if ($leftCreatedAt !== null && $rightCreatedAt !== null && $leftCreatedAt !== $rightCreatedAt) {
            return $leftCreatedAt > $rightCreatedAt;
        }

        if ($leftCreatedAt !== null && $rightCreatedAt === null) {
            return true;
        }

        if ($leftCreatedAt === null && $rightCreatedAt !== null) {
            return false;
        }

        $leftRequestNo = $this->extractCancelRequestNumber($left);
        $rightRequestNo = $this->extractCancelRequestNumber($right);

        if ($leftRequestNo !== null && $rightRequestNo !== null && $leftRequestNo !== $rightRequestNo) {
            return $leftRequestNo > $rightRequestNo;
        }

        if ($leftRequestNo !== null && $rightRequestNo === null) {
            return true;
        }

        if ($leftRequestNo === null && $rightRequestNo !== null) {
            return false;
        }

        // When both records are equivalent by known fields, prefer the latest in provider order.
        return true;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function extractRequestCreatedAtTimestamp(array $request): ?int
    {
        $value = $request['CreatedDate'] ?? $request['createdDate'] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function extractCancelRequestNumber(array $request): ?int
    {
        $value = $request['CancelRequestNo'] ?? $request['cancelRequestNo'] ?? $request['CancelRequestNumber'] ?? null;

        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @param  array<string, mixed>  $request
     */
    protected function extractRemark(array $request): ?string
    {
        $remark = $request['Remarks'] ?? $request['Remark'] ?? null;

        if (! is_scalar($remark)) {
            return null;
        }

        $value = trim((string) $remark);

        return $value !== '' ? $value : null;
    }
}
