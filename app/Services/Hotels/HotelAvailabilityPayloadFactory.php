<?php

namespace App\Services\Hotels;

class HotelAvailabilityPayloadFactory
{
    /**
     * Build a provider availability payload from a normalized search criteria array.
     *
     * Expected search shape (tenant + API):
     * - city (string, required for 3T)
     * - city_id (int|string|null)
     * - check_in / check_out (Y-m-d)
     * - rooms: list of { adult: int, children?: int[] ages }
     * - language (optional, e.g. fr-FR or fr_FR)
     * - page (optional)
     *
     * @param  array<string, mixed>  $search
     * @return array<string, mixed>
     */
    public function make(array $search, int $page = 1): array
    {
        $payload = [
            'checkIn' => (string) $search['check_in'],
            'checkOut' => (string) $search['check_out'],
            'city' => $this->cityNameForAvailability((string) ($search['city'] ?? '')),
            'hotelName' => '',
            'boards' => [],
            'rating' => [],
            'hotelId' => [],
            'occupancies' => $this->occupanciesPayload($this->normalizeRooms($search)),
            'language' => str_replace('-', '_', (string) ($search['language'] ?? 'fr_FR')),
            'onlyAvailableHotels' => true,
            'channel' => 'b2b',
            'filtreSearch' => [],
            'page' => max(1, $page),
        ];

        if ((int) ($search['city_id'] ?? 0) > 0) {
            $payload['cityId'] = (int) $search['city_id'];
        }

        return $payload;
    }

    /**
     * Accept either structured rooms or a flat rooms/adults/children shortcut.
     *
     * @param  array<string, mixed>  $search
     * @return array<int, array{adult: int, children: array<int, int>}>
     */
    public function normalizeRooms(array $search): array
    {
        if (isset($search['rooms']) && is_array($search['rooms']) && array_is_list($search['rooms'])) {
            return array_values(array_map(function (mixed $room): array {
                $room = is_array($room) ? $room : [];
                $children = array_values(array_filter(
                    (array) ($room['children'] ?? []),
                    fn (mixed $age): bool => is_numeric($age),
                ));

                return [
                    'adult' => max(1, (int) ($room['adult'] ?? $room['adults'] ?? 1)),
                    'children' => array_map(fn (mixed $age): int => (int) $age, $children),
                ];
            }, $search['rooms']));
        }

        $roomCount = max(1, (int) ($search['rooms'] ?? 1));
        $adults = max(1, (int) ($search['adults'] ?? 1));
        $childrenCount = max(0, (int) ($search['children'] ?? 0));
        $childAges = array_values(array_filter(
            (array) ($search['children_ages'] ?? []),
            fn (mixed $age): bool => is_numeric($age),
        ));

        // Flat children count without ages is only safe when zero.
        if ($childrenCount > 0 && count($childAges) !== $childrenCount) {
            throw new HotelApiException(
                'Each child requires an age. Send rooms[].children as ages, or children_ages matching children count.'
            );
        }

        $rooms = [];

        for ($i = 0; $i < $roomCount; $i++) {
            $rooms[] = [
                'adult' => $adults,
                'children' => array_map(fn (mixed $age): int => (int) $age, $childAges),
            ];
        }

        return $rooms;
    }

    /**
     * @param  array<int, array{adult: int, children: array<int, int>}>  $rooms
     * @return array<string, array<string, mixed>>
     */
    public function occupanciesPayload(array $rooms): array
    {
        $occupancies = [];

        foreach (array_values($rooms) as $index => $room) {
            $children = array_values($room['children'] ?? []);

            $occupancies[(string) ($index + 1)] = [
                'adult' => (string) (int) ($room['adult'] ?? 1),
                'child' => [
                    'value' => count($children),
                    'age' => implode(',', array_map(fn (int $age): string => (string) $age, $children)),
                ],
            ];
        }

        return $occupancies;
    }

    protected function cityNameForAvailability(string $city): string
    {
        return trim((string) str($city)->before(','));
    }
}
