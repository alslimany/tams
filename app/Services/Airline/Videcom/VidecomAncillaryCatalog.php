<?php

namespace App\Services\Airline\Videcom;

class VidecomAncillaryCatalog
{
    public static function forAirline(string $airlineCode, array $providerConfig = []): array
    {
        $default = config('videcom_ancillaries.default.services', []);
        $airline = config("videcom_ancillaries.{$airlineCode}.services", []);
        $overrides = $providerConfig['ancillary_catalog'] ?? [];

        return collect([...$default, ...$airline])
            ->keyBy('code')
            ->map(function (array $service) use ($overrides): array {
                $override = collect($overrides)->firstWhere('code', $service['code']) ?? [];

                return array_merge($service, $override);
            })
            ->values()
            ->map(function (array $service): array {
                $service['enabled'] = (bool) ($service['enabled'] ?? false);
                $service['unit_price'] = (float) ($service['unit_price'] ?? 0);
                $service['min_quantity'] = (int) ($service['min_quantity'] ?? 0);
                $service['max_quantity'] = (int) ($service['max_quantity'] ?? 0);
                $service['default_quantity'] = (int) ($service['default_quantity'] ?? 0);

                return $service;
            })
            ->all();
    }

    public static function selectedTotals(array $catalog, array $selectedServices, int $passengerCount, int $segmentCount): array
    {
        $indexed = collect($catalog)->keyBy('code');
        $lines = [];
        $total = 0.0;

        foreach ($selectedServices as $selection) {
            $service = $indexed->get($selection['code'] ?? null);

            if (! $service || ! ($service['enabled'] ?? false)) {
                continue;
            }

            $quantity = max(0, (int) ($selection['quantity'] ?? 0));
            $passengers = collect($selection['passengers'] ?? [])
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->values()
                ->all();

            $eligiblePassengerCount = count($passengers) > 0 ? count($passengers) : $passengerCount;

            $multiplier = match ($service['pricing_mode'] ?? 'per_booking') {
                'per_kg' => $quantity,
                'per_passenger' => $eligiblePassengerCount,
                'per_segment' => $segmentCount,
                'per_passenger_per_segment' => $eligiblePassengerCount * $segmentCount,
                default => 1,
            };

            $lineTotal = ((float) ($service['unit_price'] ?? 0)) * $multiplier;
            $total += $lineTotal;

            $lines[] = [
                'code' => $service['code'],
                'label' => $service['label'],
                'quantity' => $quantity,
                'passengers' => $passengers,
                'unit_price' => (float) ($service['unit_price'] ?? 0),
                'total' => $lineTotal,
                'command_template' => $service['command_template'] ?? null,
            ];
        }

        return [
            'lines' => $lines,
            'total' => $total,
        ];
    }
}
