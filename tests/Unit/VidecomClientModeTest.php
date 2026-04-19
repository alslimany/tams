<?php

use App\Services\Airline\Videcom\VidecomClient;

it('treats api mode as soap mode', function () {
    $client = new VidecomClient([
        'mode' => 'api',
        'base_url' => 'https://customer3.videcom.com/OYA',
    ]);

    expect(fn () => $client->runCommand('*R'))
        ->toThrow(Exception::class, 'Videcom token is missing for SOAP mode.');
});
