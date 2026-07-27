<?php

namespace App\Actions\Insurance;

use App\Models\Tenant\OrderItem;
use App\Services\Insurance\InsuranceApiException;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Support\Arr;
use Throwable;

class FetchInsurancePolicyReport
{
    public function __construct(
        protected InsuranceProviderManager $providerManager,
    ) {}

    /**
     * @return array{content: string, content_type: string, reference: string, filename: string}
     */
    public function execute(OrderItem $item): array
    {
        $productType = strtolower((string) $item->product_subtype);

        if (! in_array($productType, ['compulsory', 'travel', 'orange'], true)) {
            throw new InsuranceApiException('Unsupported insurance product type for policy report.');
        }

        $references = $this->resolveReportReferences($item);

        if (count($references) === 0) {
            throw new InsuranceApiException('No printable policy reference was found for this insurance item.');
        }

        $lastException = null;

        foreach ($references as $reference) {
            try {
                $report = $this->providerManager->provider()->fetchPolicyReport(
                    productType: $productType,
                    reportReference: $reference,
                );

                $fileReference = preg_replace('/[^A-Za-z0-9_-]/', '-', $reference) ?: 'report';
                $contentType = explode(';', (string) ($report['content_type'] ?? 'application/pdf'))[0] ?: 'application/pdf';

                return [
                    'content' => (string) ($report['content'] ?? ''),
                    'content_type' => $contentType,
                    'reference' => $reference,
                    'filename' => $productType.'-policy-'.$fileReference.'.pdf',
                ];
            } catch (Throwable $exception) {
                report($exception);
                $lastException = $exception;
            }
        }

        throw new InsuranceApiException(
            $lastException?->getMessage() ?: 'Unable to fetch policy report from insurance provider.',
        );
    }

    /**
     * @return array<int, string>
     */
    public function resolveReportReferences(OrderItem $item): array
    {
        $references = [];

        $encryptedCandidates = [
            data_get($item->product_details, 'policy_details.report_reference'),
            data_get($item->item_details, 'insurance.report_reference'),
            data_get($item->item_details, 'insurance.provider_response.data.EncryptedId'),
            data_get($item->item_details, 'insurance.provider_response.EncryptedId'),
            data_get($item->item_details, 'insurance.provider_response.data.CardNumber'),
            data_get($item->item_details, 'insurance.provider_response.CardNumber'),
            $item->provider_reference,
        ];

        foreach ($encryptedCandidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);

            if ($trimmed === '') {
                continue;
            }

            $references[] = $trimmed;
        }

        $idCandidates = [
            data_get($item->product_details, 'policy_details.policy_id'),
            data_get($item->item_details, 'insurance.cancellation.insurance_policy_id'),
            data_get($item->item_details, 'insurance.provider_response.data.Id'),
            data_get($item->item_details, 'insurance.provider_response.Id'),
        ];

        foreach ($idCandidates as $candidate) {
            if (! is_numeric($candidate) || (int) $candidate <= 0) {
                continue;
            }

            $references[] = (string) ((int) $candidate);
        }

        return array_values(array_unique(Arr::where($references, fn (string $value): bool => $value !== '')));
    }
}
