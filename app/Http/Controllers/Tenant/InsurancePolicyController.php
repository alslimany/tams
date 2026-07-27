<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Finance\FinalizeInsuranceCancellation;
use App\Actions\Insurance\FetchInsurancePolicyReport;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Insurance\InsuranceApiException;
use App\Services\Insurance\InsuranceProviderManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class InsurancePolicyController extends Controller
{
    public function __construct(
        protected InsuranceProviderManager $providerManager,
    ) {}

    public function report(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_type' => ['required', 'string', Rule::in(['compulsory', 'travel', 'orange'])],
            'reference' => ['required', 'string'],
        ]);

        try {
            $url = $this->providerManager->provider()->policyReportUrl(
                productType: $validated['product_type'],
                reportReference: $validated['reference'],
            );

            return redirect()->away($url);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function cancel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_type' => ['required', 'string', Rule::in(['compulsory', 'travel', 'orange'])],
            'insurance_policy_id' => ['required', 'integer', 'min:1'],
            'remarks' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->providerManager->provider()->cancel(
                productType: $validated['product_type'],
                insurancePolicyId: (int) $validated['insurance_policy_id'],
                remarks: $validated['remarks'],
            );

            return back()->with('success', 'Insurance policy cancellation request sent successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function submitCancellation(Request $request, Order $order, OrderItem $item): RedirectResponse
    {
        abort_unless($item->order_id === $order->id, 404);

        $validated = $request->validate([
            'remarks' => ['required', 'string', 'max:500'],
        ]);

        if ((string) $item->type !== 'insurance' || (string) $item->status !== 'issued') {
            return back()->with('error', 'Only issued insurance items can be cancelled.');
        }

        $insurancePolicyId = $this->resolveInsurancePolicyId($item);

        if ($insurancePolicyId === null) {
            return back()->with('error', 'Insurance policy id is missing for this order item.');
        }

        try {
            $response = $this->providerManager->provider()->cancel(
                productType: (string) $item->product_subtype,
                insurancePolicyId: $insurancePolicyId,
                remarks: (string) $validated['remarks'],
            );

            DB::transaction(function () use ($order, $item, $validated, $response, $insurancePolicyId, $request): void {
                $itemDetails = (array) $item->item_details;
                $cancellation = (array) data_get($itemDetails, 'insurance.cancellation', []);
                $latestRemark = (string) ($response['remarks'] ?? '');

                data_set($cancellation, 'insurance_policy_id', $response['insurance_policy_id'] ?? $insurancePolicyId);
                data_set($cancellation, 'note', (string) $validated['remarks']);
                data_set($cancellation, 'latest_remark', $latestRemark);
                data_set($cancellation, 'requested_at', now()->toIso8601String());
                data_set($cancellation, 'last_synced_at', now()->toIso8601String());
                data_set($cancellation, 'latest_response', $response['raw'] ?? []);

                if ($latestRemark === 'تم الالغاء') {
                    data_set($cancellation, 'approved_at', now()->toIso8601String());
                }

                data_set($itemDetails, 'insurance.cancellation', $cancellation);

                $oldStatus = (string) $item->status;

                $item->update([
                    'item_details' => $itemDetails,
                    'status' => 'cancellation',
                ]);

                $order->update([
                    'status' => 'cancellation',
                ]);

                $order->statusLogs()->create([
                    'old_status' => $oldStatus,
                    'new_status' => 'cancellation',
                    'user_id' => $request->user()?->id,
                    'comment' => 'Insurance cancellation requested: '.(string) $validated['remarks'],
                ]);
            });

            return back()->with('success', 'Insurance cancellation request sent successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function finalizeCancellation(
        Request $request,
        Order $order,
        OrderItem $item,
        FinalizeInsuranceCancellation $finalizeInsuranceCancellation,
    ): RedirectResponse {
        abort_unless($item->order_id === $order->id, 404);

        if ((string) $item->type !== 'insurance' || (string) $item->status !== 'cancellation') {
            return back()->with('error', 'This insurance item is not awaiting cancellation confirmation.');
        }

        $latestRemark = (string) data_get($item->item_details, 'insurance.cancellation.latest_remark', '');

        if ($latestRemark !== 'تم الالغاء') {
            return back()->with('error', 'Insurance company approval has not been received yet.');
        }

        try {
            $result = $finalizeInsuranceCancellation->execute($order, $item, $request->user());

            $order->statusLogs()->create([
                'old_status' => 'cancellation',
                'new_status' => 'cancelled',
                'user_id' => $request->user()?->id,
                'comment' => 'Insurance cancellation confirmed. Refund '.$result['net_wallet_effect'].' '.$order->currency.' applied after commission reversal.',
            ]);

            return back()->with('success', 'Insurance cancellation completed successfully.');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }
    }

    public function itemReport(Request $request, Order $order, OrderItem $item, FetchInsurancePolicyReport $fetchInsurancePolicyReport): Response
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless((string) $item->type === 'insurance', 404);

        try {
            $report = $fetchInsurancePolicyReport->execute($item);
        } catch (InsuranceApiException $exception) {
            $status = str_contains($exception->getMessage(), 'No printable policy reference')
                ? 422
                : 502;

            abort($status, $exception->getMessage());
        }

        return response($report['content'], 200, [
            'Content-Type' => $report['content_type'],
            'Content-Disposition' => 'inline; filename="'.$report['filename'].'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
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
}
