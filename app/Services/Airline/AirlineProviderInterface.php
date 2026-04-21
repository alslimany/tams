<?php

namespace App\Services\Airline;

interface AirlineProviderInterface
{
    /**
     * Search for flight availability.
     *
     * @return mixed
     */
    public function searchAvailability(array $params);

    /**
     * Get pricing for a selected itinerary.
     *
     * @return mixed
     */
    public function getPricing(array $itinerary, array $passengers);

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
     * Refund a ticket.
     *
     * @return mixed
     */
    public function refund(string $ticketNo, array $params = []);

    /**
     * Change a booking.
     *
     * @return mixed
     */
    public function change(string $rloc, array $changes);

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
