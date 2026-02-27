<?php

namespace App\DTOs\Airline;

class FlightOption
{
    public function __construct(
        public string $id,
        public string $airline_code,
        public string $airline_name,
        public string $flight_number,
        public string $departure_airport,
        public string $arrival_airport,
        public string $departure_time,
        public string $arrival_time,
        public array $segments,
        public array $pricing, // ['currency' => 'LYD', 'total' => 1500.00, 'breakdown' => [...]]
        public int $available_seats,
        public array $raw_data = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['airline_code'],
            $data['airline_name'],
            $data['flight_number'],
            $data['departure_airport'],
            $data['arrival_airport'],
            $data['departure_time'],
            $data['arrival_time'],
            $data['segments'],
            $data['pricing'],
            $data['available_seats'],
            $data['raw_data'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'airline_code' => $this->airline_code,
            'airline_name' => $this->airline_name,
            'flight_number' => $this->flight_number,
            'departure_airport' => $this->departure_airport,
            'arrival_airport' => $this->arrival_airport,
            'departure_time' => $this->departure_time,
            'arrival_time' => $this->arrival_time,
            'segments' => $this->segments,
            'pricing' => $this->pricing,
            'available_seats' => $this->available_seats,
        ];
    }
}
