<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Tenant;
use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;

tenancy()->initialize(Tenant::where('id', 'test')->first());

$providerConfig = TenantProvider::where('airline_code', 'YI')->orWhere('airline_code', 'OYa')->first();
$provider = ProviderFactory::make($providerConfig);

try {
    echo "--- Testing Multi-Branded Offer Extraction (MJI to CAI) ---\n\n";

    $paramsProvider = [
        'origin' => 'MJI',
        'destination' => 'CAI',
        'date' => '2026-03-19',
        'qty' => 1,
        'adults' => 1,
        'is_return' => false,
    ];

    $flights = $provider->searchAvailability($paramsProvider);

    echo 'Found '.count($flights)." offers:\n\n";

    foreach ($flights as $f) {
        echo "[{$f->airline_name}] Flight: {$f->flight_number} | Class: {$f->pricing['class_code']} | Price: {$f->pricing['total']} {$f->pricing['currency']} | Seats: {$f->available_seats}\n";
    }

} catch (\Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
}
