<?php

namespace App\Contracts\Hotels;

interface HotelProviderInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function autocomplete(array $payload = []): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function availability(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function hotelDetails(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function checkRate(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function book(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cancel(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function bookings(array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function creditCheck(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function countries(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function cities(string $countryCode): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function hotels(int|string $cityId): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function boards(): array;
}
