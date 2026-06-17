<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Models\Tenant\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * Paginated order list.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'product_type' => ['nullable', 'string', 'in:flight,insurance,hotel'],
            'status' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $orders = Order::query()
            ->with(['owner:id,name,email,role', 'items'])
            ->when(isset($validated['product_type']), function ($q) use ($validated) {
                $q->whereHas('items', fn ($q) => $q->where('product_type', $validated['product_type']));
            })
            ->when(isset($validated['status']), function ($q) use ($validated) {
                $q->where('status', $validated['status']);
            })
            ->when(isset($validated['date_from']), function ($q) use ($validated) {
                $q->whereDate('issued_at', '>=', $validated['date_from']);
            })
            ->when(isset($validated['date_to']), function ($q) use ($validated) {
                $q->whereDate('issued_at', '<=', $validated['date_to']);
            })
            ->latest('issued_at')
            ->latest('created_at')
            ->paginate($perPage);

        return $this->success($orders);
    }

    /**
     * Order detail with items and wallet transactions.
     */
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => 'required'
        ]);

        $order = Order::query()
            ->with(['owner', 'items', 'statusLogs.user'])
            ->where('id', $validated['order'])
            ->firstOrFail();

        return $this->success([
            'order' => $this->formatOrder($order),
        ]);
    }

    /**
     * Flight ticket PDF.
     */
    public function ticketPdf(Order $order, OrderItem $item)
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless(in_array((string) $item->type, ['flight'], true) || (string) $item->product_type === 'flight', 404);

        $order->loadMissing('owner');

        $segments = data_get($item->item_details, 'segments', []);
        $passengers = data_get($item->item_details, 'passengers', []);

        return \Spatie\LaravelPdf\Support\pdf()
            ->view('pdf.flight-ticket', [
                'order' => $order,
                'item' => $item,
                'segments' => $segments,
                'passengers' => $passengers,
                'airline_code' => data_get($item->item_details, 'airline_code', ''),
            ])
            ->name('ticket-'.$item->provider_reference.'.pdf');
    }

    public function hotelVoucherPdf(Order $order, OrderItem $item): \Spatie\LaravelPdf\PdfBuilder
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless((string) $item->type === 'hotel' || (string) $item->product_type === 'hotel', 404);

        $order->loadMissing('owner');

        $filename = 'hotel-voucher-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($item->provider_reference ?: $order->number)).'.pdf';

        return \Spatie\LaravelPdf\Support\pdf()
            ->view('pdf.hotel-voucher', ['order' => $order, 'item' => $item])
            ->format(\Spatie\LaravelPdf\Enums\Format::A4)
            ->margins(8, 8, 8, 8, \Spatie\LaravelPdf\Enums\Unit::Millimeter)
            ->inline($filename);
    }

    public function insurancePolicyPdf(Order $order, OrderItem $item): \Spatie\LaravelPdf\PdfBuilder
    {
        abort_unless($item->order_id === $order->id, 404);
        abort_unless((string) $item->type === 'insurance' || (string) $item->product_type === 'insurance', 404);

        $order->loadMissing('owner');

        $filename = 'insurance-policy-'.preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($item->ticket_number ?: $order->number)).'.pdf';

        return \Spatie\LaravelPdf\Support\pdf()
            ->view('pdf.insurance-policy', ['order' => $order, 'item' => $item])
            ->format(\Spatie\LaravelPdf\Enums\Format::A4)
            ->margins(8, 8, 8, 8, \Spatie\LaravelPdf\Enums\Unit::Millimeter)
            ->inline($filename);
    }

    public function orderSummaryPdf(Order $order): \Spatie\LaravelPdf\PdfBuilder
    {
        $order->loadMissing(['owner', 'items']);

        $filename = 'order-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $order->number).'.pdf';

        return \Spatie\LaravelPdf\Support\pdf()
            ->view('pdf.order-summary', ['order' => $order, 'items' => $order->items])
            ->format(\Spatie\LaravelPdf\Enums\Format::A4)
            ->margins(8, 8, 8, 8, \Spatie\LaravelPdf\Enums\Unit::Millimeter)
            ->inline($filename);
    }

    private function formatOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'subtotal' => (float) $order->subtotal,
            'tax_total' => (float) $order->tax_total,
            'grand_total' => (float) $order->grand_total,
            'amount_paid' => (float) $order->amount_paid,
            'amount_refunded' => (float) $order->amount_refunded,
            'currency' => $order->currency,
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'issued_at' => $order->issued_at?->toISOString(),
            'created_at' => $order->created_at->toISOString(),
            'owner' => $order->owner ? [
                'id' => $order->owner->id,
                'name' => $order->owner->name,
                'email' => $order->owner->email,
                'role' => $order->owner->role,
            ] : null,
            'contact' => $order->contact,
            'items' => $order->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'product_type' => $item->product_type,
                    'product_subtype' => $item->product_subtype,
                    'provider' => $item->provider,
                    'provider_reference' => $item->provider_reference,
                    'ticket_number' => $item->ticket_number,
                    'status' => $item->status,
                    'total' => (float) $item->total_amount,
                    'net_fare' => (float) $item->net_fare,
                    'commission_amount' => (float) $item->commission_amount,
                    'currency' => $item->currency,
                    'passengers' => data_get($item->item_details, 'passengers'),
                    'segments' => data_get($item->item_details, 'segments'),
                ];
            })->values(),
        ];
    }
}
