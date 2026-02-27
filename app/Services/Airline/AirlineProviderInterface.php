<?php

namespace App\Services\Airline;

interface AirlineProviderInterface
{
    /**
     * Search for flight availability.
     *
     * @param array $params
     * @return mixed
     */
    public function searchAvailability(array $params);

    /**
     * Get pricing for a selected itinerary.
     *
     * @param array $itinerary
     * @param array $passengers
     * @return mixed
     */
    public function getPricing(array $itinerary, array $passengers);

    /**
     * Create a booking (PNR).
     *
     * @param array $params
     * @return mixed
     */
    public function createBooking(array $params);

    /**
     * Issue tickets for a booking.
     *
     * @param string $rloc
     * @param array $paymentInfo
     * @return mixed
     */
    public function issueTicket(string $rloc, array $paymentInfo);

    /**
     * Get the seat map for a flight.
     *
     * @param string $fltNo
     * @param string $date
     * @param string $origin
     * @param string $destination
     * @return mixed
     */
    public function getSeatMap(string $fltNo, string $date, string $origin, string $destination);

    /**
     * Select a seat for a passenger.
     *
     * @param string $rloc
     * @param int $paxNo
     * @param string $seatNo
     * @return mixed
     */
    public function selectSeat(string $rloc, int $paxNo, string $seatNo);

    /**
     * Cancel/Void a booking or ticket.
     *
     * @param string $rloc
     * @param string|null $ticketNo
     * @return mixed
     */
    public function void(string $rloc, string $ticketNo = null);

    /**
     * Refund a ticket.
     *
     * @param string $ticketNo
     * @param array $params
     * @return mixed
     */
    public function refund(string $ticketNo, array $params = []);

    /**
     * Change a booking.
     *
     * @param string $rloc
     * @param array $changes
     * @return mixed
     */
    public function change(string $rloc, array $changes);

    /**
     * Test the connection to the provider.
     *
     * @return bool
     * @throws Exception
     */
    public function testConnection(): bool;

    /**
     * Get the provider name.
     *
     * @return string
     */
    public function getName(): string;
}
