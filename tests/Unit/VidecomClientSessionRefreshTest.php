<?php

use App\Services\Airline\Videcom\VidecomClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Cache::flush();
});

function videcomSessionClient(): VidecomClient
{
    return new VidecomClient([
        'mode' => 'session',
        'base_url' => 'https://customer3.videcom.com/OYA',
        'username' => 'SINE01',
        'password' => 'secret',
        'airline_code' => 'YI',
    ]);
}

function fakeVidecomLogin(string $sessionId = 'NEW-SESSION'): void
{
    Http::fake([
        'customer3.videcom.com/OYA/VARS/Agent/WebServices/LoginWs.asmx/DoLogin*' => Http::response([
            'd' => [
                'NextURL' => 'https://customer3.videcom.com/OYA/VARS/Agent/Home.aspx?VarsSessionID='.$sessionId,
            ],
        ]),
    ]);
}

it('re-logins and retries when cached session returns NotSinedInException in Data', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'DoLogin')) {
            return Http::response([
                'd' => [
                    'NextURL' => 'https://customer3.videcom.com/OYA/VARS/Agent/Home.aspx?VarsSessionID=FRESH-SESSION',
                ],
            ]);
        }

        if (str_contains($request->url(), 'SendCommand')) {
            if (str_contains($request->url(), 'VarsSessionID=STALE-SESSION')) {
                return Http::response([
                    'd' => [
                        'Data' => "Exception of type 'VARS.SystemLibrary.NotSinedInException' was thrown.",
                    ],
                ]);
            }

            return Http::response([
                'd' => [
                    'Data' => 'PNR EMPTY',
                ],
            ]);
        }

        return Http::response('unexpected', 500);
    });

    Cache::put(
        'videcom_session_'.md5(implode('|', [
            'central',
            'https://customer3.videcom.com/OYA',
            'SINE01',
            hash('sha256', 'secret'),
        ])),
        'VarsSessionID=STALE-SESSION',
        now()->addMinutes(20),
    );

    $response = videcomSessionClient()->runCommand('*R');

    expect($response)->toBe('PNR EMPTY');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'DoLogin'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'VarsSessionID=STALE-SESSION'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'VarsSessionID=FRESH-SESSION'));
});

it('re-logins when NotSinedInException comes back as HTTP 500', function () {
    $attempts = 0;

    Http::fake(function (Request $request) use (&$attempts) {
        if (str_contains($request->url(), 'DoLogin')) {
            return Http::response([
                'd' => [
                    'NextURL' => 'https://customer3.videcom.com/OYA/VARS/Agent/Home.aspx?VarsSessionID=AFTER-500',
                ],
            ]);
        }

        if (str_contains($request->url(), 'SendCommand')) {
            $attempts++;

            if ($attempts === 1) {
                return Http::response(
                    "Exception of type 'VARS.SystemLibrary.NotSinedInException' was thrown.",
                    500,
                );
            }

            return Http::response([
                'd' => ['Data' => 'OK AFTER RELLOGIN'],
            ]);
        }

        return Http::response('unexpected', 500);
    });

    Cache::put(
        'videcom_session_'.md5(implode('|', [
            'central',
            'https://customer3.videcom.com/OYA',
            'SINE01',
            hash('sha256', 'secret'),
        ])),
        'VarsSessionID=DEAD',
        now()->addMinutes(20),
    );

    $response = videcomSessionClient()->runCommand('A15SEP MJITUN');

    expect($response)->toBe('OK AFTER RELLOGIN')
        ->and($attempts)->toBe(2);
});

it('re-logins when ASP.NET returns Message envelope without d.Data', function () {
    $attempts = 0;

    Http::fake(function (Request $request) use (&$attempts) {
        if (str_contains($request->url(), 'DoLogin')) {
            return Http::response([
                'd' => [
                    'NextURL' => 'https://customer3.videcom.com/OYA/VARS/Agent/Home.aspx?VarsSessionID=MSG-SESSION',
                ],
            ]);
        }

        if (str_contains($request->url(), 'SendCommand')) {
            $attempts++;

            if ($attempts === 1) {
                return Http::response([
                    'Message' => "Exception of type 'VARS.SystemLibrary.NotSinedInException' was thrown.",
                    'ExceptionType' => 'System.InvalidOperationException',
                ], 500);
            }

            return Http::response([
                'd' => ['Data' => 'RECOVERED'],
            ]);
        }

        return Http::response('unexpected', 500);
    });

    Cache::put(
        'videcom_session_'.md5(implode('|', [
            'central',
            'https://customer3.videcom.com/OYA',
            'SINE01',
            hash('sha256', 'secret'),
        ])),
        'VarsSessionID=OLD',
        now()->addMinutes(20),
    );

    expect(videcomSessionClient()->runCommand('*R'))->toBe('RECOVERED');
});

it('stores a fresh session in cache after forced re-login', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'DoLogin')) {
            return Http::response([
                'd' => [
                    'NextURL' => 'https://customer3.videcom.com/OYA/VARS/Agent/Home.aspx?VarsSessionID=CACHED-NEW',
                ],
            ]);
        }

        if (str_contains($request->url(), 'SendCommand') && str_contains($request->url(), 'STALE')) {
            return Http::response([
                'd' => [
                    'Data' => "Exception of type 'VARS.SystemLibrary.NotSinedInException' was thrown.",
                ],
            ]);
        }

        return Http::response([
            'd' => ['Data' => 'OK'],
        ]);
    });

    $cacheKey = 'videcom_session_'.md5(implode('|', [
        'central',
        'https://customer3.videcom.com/OYA',
        'SINE01',
        hash('sha256', 'secret'),
    ]));

    Cache::put($cacheKey, 'VarsSessionID=STALE', now()->addMinutes(20));

    videcomSessionClient()->runCommand('*R');

    expect(Cache::get($cacheKey))->toBe('VarsSessionID=CACHED-NEW');
});
