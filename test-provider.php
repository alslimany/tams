<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\TenantProvider;
use App\Services\Airline\ProviderFactory;

tenancy()->initialize(App\Models\Tenant::first(['*']));

$providerConfig = TenantProvider::first(['*']);
if (! $providerConfig) {
    echo "No tenant providers found.\n";
    exit;
}

$provider = ProviderFactory::make($providerConfig);

$params = [
    'origin' => 'MJI',
    'destination' => 'IST',
    'date' => '2026-03-27',
    'qty' => 1,
    'adults' => 1,
    'children' => 0,
    'infants' => 0,
    'is_return' => false,
];

try {
    $flights = $provider->searchAvailability($params);
    echo json_encode($flights, JSON_PRETTY_PRINT);
} catch (\Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
}
