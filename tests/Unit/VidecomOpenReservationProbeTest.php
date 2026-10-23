<?php

use App\Services\Airline\Videcom\Airlines\MedskyAirline;
use App\Services\Airline\Videcom\Monitor\ReadOnlyCommandGuard;
use App\Services\Airline\Videcom\VidecomClient;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

/**
 * BM MJI-TUN 0400 on 30 Oct: NN quotes Y/S, QQ (open) is refused.
 *
 * @param  callable(string): string  $responder
 */
function openResAirline(callable $responder, ?VidecomClient &$client = null): MedskyAirline
{
    $client = new class($responder) extends VidecomClient
    {
        public array $commands = [];

        public function __construct(public $responder) {}

        public function runCommand(string $command): string
        {
            (new ReadOnlyCommandGuard)->check($command);
            $this->commands[] = $command;

            return ($this->responder)($command);
        }
    };

    $airline = new MedskyAirline(['base_url' => 'https://customer3.videcom.test']);
    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $airline, $airline)($client);

    return $airline;
}

$priced = '<PNR><FareQuote><FareStore Pax="1" Cur="LYD" Total="590.00"><SegmentFS Seg="1" Fare="540" Tax1="50" /></FareStore></FareQuote></PNR>';

beforeEach(fn () => Cache::flush());

test('#2 open reservation is denied when Videcom refuses the class as open', function () use ($priced) {
    $airline = openResAirline(
        fn (string $command): string => str_contains($command, 'QQ1')
            ? 'ERROR: CLASS Y CANNOT BE BOOKED AS OPEN ON THIS ROUTE'
            : $priced,
        $client,
    );

    $allowed = $airline->canBookOpenReservation([
        'origin' => 'MJI', 'dest' => 'TUN', 'class' => 'Y', 'date' => '2026-10-30', 'flt_no' => '0400',
    ]);

    expect($allowed)->toBeFalse()
        ->and($client->commands)->toHaveCount(1)
        ->and($client->commands[0])->toContain('QQ1')
        ->and($client->commands[0])->not->toContain('NN1');
});

test('#2 open reservation is allowed when Videcom prices the open segment', function () use ($priced) {
    $airline = openResAirline(fn (string $command): string => $priced);

    expect($airline->canBookOpenReservation([
        'origin' => 'MJI', 'dest' => 'TUN', 'class' => 'H', 'date' => '2026-10-30', 'flt_no' => '0400',
    ]))->toBeTrue();
});

test('#2 a probe timeout denies open reservation without caching the verdict', function () use ($priced) {
    $calls = 0;
    $airline = openResAirline(function (string $command) use (&$calls, $priced): string {
        $calls++;
        if ($calls === 1) {
            throw new RuntimeException('cURL error 28: Operation timed out');
        }

        return $priced;
    });

    $segment = ['origin' => 'MJI', 'dest' => 'TUN', 'class' => 'W', 'date' => '2026-10-30', 'flt_no' => '0400'];

    expect($airline->canBookOpenReservation($segment))->toBeFalse()
        ->and($airline->canBookOpenReservation($segment))->toBeTrue()
        ->and($calls)->toBe(2);
});
