<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Controller;
use App\Models\Tenant\Order;
use App\Models\Tenant\OrderItem;
use App\Services\Airline\TicketChangeService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class FlightChangeController extends Controller
{
    public function __construct(
        private readonly TicketChangeService $ticketChangeService,
    ) {}

    /**
     * Start a change-segment flight search on the issuing airline only.
     */
    public function search(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);

        $validated = $request->validate([
            'segment_line' => ['required', 'integer', 'min:1'],
            'origin' => ['required', 'string', 'size:3'],
            'destination' => ['required', 'string', 'size:3'],
            'date' => ['required', 'date_format:Y-m-d'],
            'cabin_class' => ['nullable', 'string', 'in:all,Y,C,F,W,economy,premium_economy,business,first'],
        ]);

        $provider = $this->ticketChangeService->resolveProvider($item);

        if ($provider === null) {
            return $this->error('No active provider found for this ticket.', 422);
        }

        $searchParams = [
            'origin' => strtoupper($validated['origin']),
            'destination' => strtoupper($validated['destination']),
            'date' => $validated['date'],
            'adults' => 1,
            'children' => 0,
            'infants' => 0,
            'is_return' => false,
            'cabin_class' => $validated['cabin_class'] ?? 'all',
            'segment_line' => (int) $validated['segment_line'],
            'change_booking_id' => $order->id,
            'change_ticket_id' => $item->id,
            'locked_provider_id' => $provider->id,
        ];

        $searchUuid = (string) Str::uuid();
        Cache::put("flight_search_{$searchUuid}", $searchParams, now()->addMinutes(30));

        return $this->success([
            'uuid' => $searchUuid,
            'provider_id' => $provider->id,
            'airline_code' => $provider->airline_code,
            'airline_name' => $provider->airline_name,
            'search_params' => $searchParams,
        ], 'Change search created. Fetch offers with /flights/results/{uuid}?provider_id='.$provider->id);
    }

    /**
     * Quote change fees and penalties without applying the change.
     */
    public function changeQuote(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);

        $validated = $request->validate([
            'segment_line' => ['required', 'integer', 'min:1'],
            'new_segment_code' => ['required', 'string', 'max:64'],
        ]);

        try {
            $quote = $this->ticketChangeService->quote(
                $order,
                $item,
                (int) $validated['segment_line'],
                trim($validated['new_segment_code']),
            );
        } catch (\RuntimeException $exception) {
            $status = $exception->getCode() >= 400 ? (int) $exception->getCode() : 500;

            return $this->error($exception->getMessage(), $status);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Failed to fetch change quote from airline provider.', 500);
        }

        return $this->success($quote);
    }

    /**
     * Apply a ticket segment change after reviewing the quote.
     */
    public function confirmChange(Request $request, Order $order, OrderItem $item): JsonResponse
    {
        abort_unless($item->order_id === $order->id, 404);

        $validated = $request->validate([
            'segment_line' => ['required', 'integer', 'min:1'],
            'new_segment_code' => ['required', 'string', 'max:64'],
            'outstanding_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $result = $this->ticketChangeService->confirm(
                $order,
                $item,
                (int) $validated['segment_line'],
                trim($validated['new_segment_code']),
                (float) ($validated['outstanding_amount'] ?? 0),
            );
        } catch (\RuntimeException $exception) {
            $status = $exception->getCode() >= 400 ? (int) $exception->getCode() : 500;

            return $this->error($exception->getMessage(), $status);
        } catch (ConnectionException $exception) {
            report($exception);

            return $this->error('Airline change request timed out. Please try again.', 503);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('Failed to confirm ticket change with the airline provider.', 500);
        }

        return $this->success([
            'change_type' => $result['change_type'],
            'order' => [
                'id' => $result['order']->id,
                'number' => $result['order']->number,
                'status' => $result['order']->status,
            ],
            'item' => [
                'id' => $result['item']->id,
                'status' => $result['item']->status,
                'provider_reference' => $result['item']->provider_reference,
                'change' => data_get($result['item']->item_details, 'change'),
            ],
        ], 'Ticket changed successfully.');
    }
}
