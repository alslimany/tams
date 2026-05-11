<?php

use App\Services\Airline\Videcom\VidecomClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

it('treats api mode as soap mode', function () {
    $client = new VidecomClient([
        'mode' => 'api',
        'base_url' => 'https://customer3.videcom.com/OYA',
    ]);

    expect(fn () => $client->runCommand('*R'))
        ->toThrow(Exception::class, 'Videcom token is missing for SOAP mode.');
});

it('sends api token commands to the VRS XML service endpoint', function () {
    Http::fake([
        'customer3.videcom.com/OYA/VRSXMLService/VRSXMLWebservice3.asmx?WSDL' => Http::response('<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><RunVRSCommandResponse xmlns="http://videcom.com/"><RunVRSCommandResult>&lt;VRSResponse&gt;OK&lt;/VRSResponse&gt;</RunVRSCommandResult></RunVRSCommandResponse></soap:Body></soap:Envelope>'),
    ]);

    $client = new VidecomClient([
        'mode' => 'api',
        'base_url' => 'https://customer3.videcom.com/OYA',
        'token' => 'TOKEN-123',
        'airline_code' => 'YI',
    ]);

    $response = $client->runCommand('*R');

    expect($response)->toBe('<VRSResponse>OK</VRSResponse>');

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return $request->url() === 'https://customer3.videcom.com/OYA/VRSXMLService/VRSXMLWebservice3.asmx?WSDL'
            && $request->method() === 'POST'
            && $request->hasHeader('SOAPAction', 'http://videcom.com/RunVRSCommand')
            && str_contains($body, '<ns:msg>')
            && str_contains($body, '<ns:Token>TOKEN-123</ns:Token>')
            && str_contains($body, '<ns:Command>*R</ns:Command>');
    });

    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), '/VARS/Agent/res/EmulatorWS.asmx/SendCommand')
    );
});
