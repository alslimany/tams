<?php

namespace App\Services\Airline;

use App\DTOs\Airline\RoundTripPriceRequest;

interface AirlineProviderInterface
{
    /**
     * Search for flight availability.
     *
     * @return mixed
     */
    public function searchAvailability(array $params);

    /**
     * Search return leg availability independently using one-way search logic.
     *
     * @return mixed
     */
    public function searchReturnLeg(array $params);

    /**
     * Get pricing for a selected itinerary.
     *
     * @return mixed
     */
    public function getPricing(array $itinerary, array $passengers);

    /**
     * Price outbound + return in one command to support round-trip fare behavior.
     *
     * @return mixed
     */
    public function priceRoundTrip(RoundTripPriceRequest $request);

    /**
     * Create a booking (PNR).
     *
     * @return mixed
     */
    public function createBooking(array $params);

    /**
     * Build the provider issuance command string without sending it.
     */
    public function previewBookingCommand(array $params): string;

    /**
     * Retrieve a booking/PNR in XML form when supported.
     *
     * @return mixed
     */
    public function retrieveBooking(string $rloc);

    /**
     * Query a PNR snapshot from provider.
     *
     * @return mixed
     */
    public function queryPnr(string $pnr);

    /**
     * Issue tickets for a booking.
     *
     * @return mixed
     */
    public function issueTicket(string $rloc, array $paymentInfo);

    /**
     * Get the seat map for a flight.
     *
     * @return mixed
     */
    public function getSeatMap(string $fltNo, string $date);

    /**
     * Select a seat for a passenger.
     *
     * @return mixed
     */
    public function selectSeat(string $rloc, int $paxNo, string $seatNo);

    /**
     * Cancel/Void a booking or ticket.
     *
     * @return mixed
     */
    public function void(string $rloc, ?string $ticketNo = null);

    /**
     * Get a refund quote (penalties + refundable amount) for a PNR without executing the refund.
     *
     * @return array{mps_penalties: array<int, array<string, mixed>>, refund_amount: float, penalty_amount: float, currency: string}
     */
    public function refundQuote(string $pnr, int $segmentCount): array;

    /**
     * Refund a ticket.
     *
     * @return mixed
     */
    public function refund(string $pnr, int $segmentCount, float $penaltyAmount);

    /**
     * Get a change quote (outstanding amount) for swapping a segment without committing.
     *
     * Sends: *{RLOC}^X{segLine}^{newSegmentCode}^FG^FS1^MB^*R~X
     *
     * @return array{outstanding_amount: float, currency: string, change_type: string, raw_response: string}
     */
    public function changeQuote(string $rloc, int $segmentLine, string $newSegmentCode): array;

    /**
     * Confirm a ticket change (revalidation or reissue).
     *
     * Revalidation (same origin + destination + class):
     *   *{RLOC}^X{segLine}^{newSegCode}^FG^FS1^MB^REZT*R^*R~X
     *
     * Reissue (different route or class):
     *   *{RLOC}^X{segLine}^{newSegCode}^FG^FS1^MB^EZV*R^EZT*R^*R~X
     *
     * @return array{success: bool, change_type: string, raw_response: string}
     */
    public function confirmChange(string $rloc, int $segmentLine, string $newSegmentCode, string $changeType, float $outstandingAmount = 0.0): array;

    /**
     * Test the connection to the provider.
     *
     * @throws Exception
     */
    public function testConnection(): bool;

    /**
     * Get the provider name.
     */
    public function getName(): string;

    /**
     * Get provider-specific ancillary options for the current itinerary.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAncillaryCatalog(array $flight = [], array $searchParams = []): array;
}
