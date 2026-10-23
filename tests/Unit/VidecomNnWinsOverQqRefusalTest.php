<?php

use App\DTOs\Airline\RoundTripPriceRequest;
use App\Services\Airline\Videcom\Airlines\LibyanWingsAirline;
use App\Services\Airline\Videcom\Airlines\MedskyAirline;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use App\Services\Airline\Videcom\Monitor\ReadOnlyCommandGuard;
use App\Services\Airline\Videcom\PricingCacheService;
use App\Services\Airline\Videcom\VidecomClient;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Live monitor 20261023-205209: classes with seats that NN prices but QQ refuses
 * ("CANNOT BE BOOKED AS OPEN") must stay sellable; only open reservation is blocked.
 * Cases: BM 0400 R 30 Oct (1 seat, 490), YL 0830 S 30 Oct (5 seats, 445),
 * YL 0841 S 29 Oct return (1 seat, 415).
 */
beforeEach(function (): void {
    Cache::flush();
    (fn () => static::$pricingAttempts = [])->bindTo(null, BaseVidecomAirline::class)();
});

function nnWinsClient(int $seats, float $price, string $class): VidecomClient
{
    return new class($seats, $price, $class) extends VidecomClient
    {
        public array $commands = [];

        public function __construct(public int $seats, public float $price, public string $class) {}

        public function runCommand(string $command): string
        {
            (new ReadOnlyCommandGuard)->check($command);
            $this->commands[] = $command;

            if (str_starts_with($command, 'A')) {
                return '';
            }

            if (preg_match('/QQ\d/', $command)) {
                return "ERROR: CLASS {$this->class} CANNOT BE BOOKED AS OPEN ON THIS ROUTE";
            }

            preg_match_all('/NN(\d)/', $command, $m);
            foreach ($m[1] as $qty) {
                if ((int) $qty > $this->seats) {
                    return "ERROR: NO AV, {$this->class} CLASS SOLD OUT ON THIS SERVICE";
                }
            }

            $segments = substr_count($command, 'NN');
            $segFs = '';
            for ($i = 1; $i <= $segments; $i++) {
                $segFs .= '<SegmentFS Seg="'.$i.'" Fare="'.($this->price - 40).'" Tax1="40" Tax2="0" Tax3="0" HoldWt="20K" HandWt="7K" HoldPcs="1" />';
            }

            return '<PNR><FareQuote><FareStore Pax="1" Cur="LYD" Total="'.($this->price * $segments).'">'.$segFs.'</FareStore></FareQuote></PNR>';
        }
    };
}

function nnWinsBind(BaseVidecomAirline $airline, VidecomClient $client): BaseVidecomAirline
{
    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $airline, $airline)($client);

    return $airline;
}

/**
 * Seed the date-agnostic failure sentinel a sold-out date leaves behind.
 */
function nnWinsSeedSentinel(string $airline, string $origin, string $dest, string $class): void
{
    foreach (['AD', 'CH', 'IN'] as $type) {
        PricingCacheService::put($airline, $origin, $dest, $class, $type, false);
    }
}

function nnWinsPrice(BaseVidecomAirline $airline, string $flight, string $class, string $origin, string $dest, string $date, int $seats): object
{
    $option = (object) [
        'flight_number' => $flight,
        'departure_airport' => $origin,
        'arrival_airport' => $dest,
        'departure_time' => "{$date} 08:00:00",
        'available_seats' => $seats,
        'segments' => [['class' => $class]],
        'pricing' => ['total' => 0],
    ];

    (new ReflectionMethod(BaseVidecomAirline::class, 'applyAccuratePricing'))->invoke($airline, $option, 1, 0, 0);

    return $option;
}

test('one-way: BM 0400 R with 1 seat is priced by NN1 despite QQ open refusal and a stale sentinel', function () {
    nnWinsSeedSentinel('BM', 'MJI', 'TUN', 'R');
    $client = nnWinsClient(1, 490, 'R');
    $airline = nnWinsBind(new MedskyAirline(['base_url' => 'https://customer3.videcom.test']), $client);

    $option = nnWinsPrice($airline, '0400', 'R', 'MJI', 'TUN', '2026-10-30', 1);

    expect((float) $option->pricing['total'])->toBe(490.0)
        ->and(collect($client->commands)->contains(fn ($c) => str_contains($c, 'QQ')))->toBeFalse();

    // Open reservation stays blocked for the same class.
    expect($airline->canBookOpenReservation([
        'origin' => 'MJI', 'dest' => 'TUN', 'class' => 'R', 'date' => '2026-10-30', 'flt_no' => '0400',
    ]))->toBeFalse();
});

test('one-way: YL 0830 S with 5 seats keeps its NN price despite a stale sentinel', function () {
    nnWinsSeedSentinel('YL', 'MJI', 'TUN', 'S');
    $client = nnWinsClient(5, 445, 'S');
    $airline = nnWinsBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), $client);

    $option = nnWinsPrice($airline, '0830', 'S', 'MJI', 'TUN', '2026-10-30', 5);

    expect((float) $option->pricing['total'])->toBe(445.0)
        ->and($client->commands[0])->toContain('NN2');

    expect($airline->canBookOpenReservation([
        'origin' => 'MJI', 'dest' => 'TUN', 'class' => 'S', 'date' => '2026-10-30', 'flt_no' => '0830',
    ]))->toBeFalse();
});

test('a sold-out date does not leave a sentinel that hides a later date with seats', function () {
    $client = nnWinsClient(0, 445, 'S');
    $airline = nnWinsBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), $client);

    nnWinsPrice($airline, '0830', 'S', 'MJI', 'TUN', '2026-10-26', 0);

    expect(PricingCacheService::get('YL', 'MJI', 'TUN', 'S', 'AD'))->toBeNull();
});

test('round trip: YL 0841 S return with 1 seat is priced and quoted with NN, not dropped', function () {
    nnWinsSeedSentinel('YL', 'TUN', 'MJI', 'S');
    $client = nnWinsClient(1, 415, 'S');
    $airline = nnWinsBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), $client);

    $return = nnWinsPrice($airline, '0841', 'S', 'TUN', 'MJI', '2026-10-29', 1);
    expect((float) $return->pricing['total'])->toBe(415.0);

    $client->commands = [];
    $quote = $airline->priceRoundTrip(new RoundTripPriceRequest(
        outboundSegment: ['flight_number' => '0830', 'class' => 'S', 'date' => '2026-10-26 08:00:00', 'origin' => 'MJI', 'destination' => 'TUN'],
        returnSegment: ['flight_number' => '0841', 'class' => 'S', 'date' => '2026-10-29 18:00:00', 'origin' => 'TUN', 'destination' => 'MJI'],
        passengers: ['adults' => 1, 'children' => 0, 'infants' => 0],
        outboundPrice: 415.0,
    ));

    expect($quote->totalPrice)->toBe(830.0)
        ->and($quote->returnLegPrice)->toBe(415.0)
        ->and($client->commands)->toHaveCount(1)
        ->and($client->commands[0])->toContain('NN1')
        ->and($client->commands[0])->not->toContain('QQ');
});

/**
 * Availability XML (X format) with one class per flight.
 *
 * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $flights  [flight, date, class, av]
 */
function nnWinsAvailabilityXml(string $airline, string $origin, string $dest, array $flights): string
{
    $journeys = '';
    foreach ($flights as [$flight, $date, $class, $av]) {
        $journeys .= <<<XML
<Journey><Legs><BookFlightSegmentType DepartureDateTime="{$date} 08:40" FlightNumber="{$airline}{$flight}">
<FlightNumber>{$airline}{$flight}</FlightNumber><XSDDepartureDateTime>{$date}T08:40:00</XSDDepartureDateTime>
<DepartureAirport LocationCode="{$origin}" /><ArrivalAirport LocationCode="{$dest}" />
<ArrivalDateTime>{$date} 10:00</ArrivalDateTime><XSDArrivalDateTime>{$date}T10:00:00</XSDArrivalDateTime>
<Availability><Class id="{$class}" av="{$av}" cab="Y" /></Availability>
</BookFlightSegmentType></Legs></Journey>
XML;
    }

    return "<AvailabilityResponse><Journeys>{$journeys}</Journeys></AvailabilityResponse>";
}

/**
 * Client returning availability for A commands and an NN price (seat-limited) otherwise.
 *
 * @param  array<string, int>  $seatsByFlight
 */
function nnWinsSearchClient(string $availability, array $seatsByFlight, float $price): VidecomClient
{
    return new class($availability, $seatsByFlight, $price) extends VidecomClient
    {
        public function __construct(public string $availability, public array $seats, public float $price) {}

        public function runCommand(string $command): string
        {
            (new ReadOnlyCommandGuard)->check($command);

            if (str_starts_with($command, 'A')) {
                return $this->availability;
            }

            if (preg_match('/QQ\d/', $command)) {
                return 'ERROR: CLASS CANNOT BE BOOKED AS OPEN ON THIS ROUTE';
            }

            preg_match('/0[A-Z0-9]{2}(\d{4})[A-Z]\d{2}[A-Z]{3}[A-Z]{6}NN(\d)/', $command, $m);
            if (($m[2] ?? 9) > ($this->seats[$m[1] ?? ''] ?? 0)) {
                return 'ERROR: NO AV, CLASS SOLD OUT ON THIS SERVICE';
            }

            return '<PNR><FareQuote><FareStore Pax="1" Cur="LYD" Total="'.$this->price.'"><SegmentFS Seg="1" Fare="'.($this->price - 40).'" Tax1="40" /></FareStore></FareQuote></PNR>';
        }
    };
}

function nnWinsSearch(BaseVidecomAirline $airline, string $origin, string $dest, string $date, bool $return = false): array
{
    $params = ['date' => $date, 'origin' => $origin, 'destination' => $dest, 'adults' => 1, 'children' => 0, 'infants' => 0, 'qty' => 1];
    $results = $return ? $airline->searchReturnLeg($params) : $airline->searchAvailability($params);

    return array_map(fn ($o) => [
        'flight' => $o->flight_number,
        'class' => $o->segments[0]['class'],
        'seats' => $o->available_seats,
        'total' => (float) ($o->pricing['total'] ?? 0),
    ], $results);
}

test('sold out BM 0400 R and 0402 R on 26 Oct are hidden even with a cached route price', function () {
    // 490 cached for BM MJI-TUN R from the seated 30 Oct flight.
    foreach (['AD' => ['fare' => 450, 'tax' => 40], 'CH' => ['fare' => 450, 'tax' => 40], 'IN' => ['fare' => 50, 'tax' => 0]] as $type => $data) {
        PricingCacheService::put('BM', 'MJI', 'TUN', 'R', $type, $data);
    }

    $xml = nnWinsAvailabilityXml('BM', 'MJI', 'TUN', [['0400', '2026-10-26', 'R', 0], ['0402', '2026-10-26', 'R', 0]]);
    $airline = nnWinsBind(new MedskyAirline(['base_url' => 'https://customer3.videcom.test']), nnWinsSearchClient($xml, [], 490));

    expect(nnWinsSearch($airline, 'MJI', 'TUN', '2026-10-26'))->toBeEmpty()
        ->and(nnWinsSearch($airline, 'MJI', 'TUN', '2026-10-26', return: true))->toBeEmpty();
});

test('sold out YL 0810 S and 0820 S on 30 Oct are hidden while seated YL 0830 S shows with 5 seats', function () {
    $xml = nnWinsAvailabilityXml('YL', 'MJI', 'TUN', [
        ['0810', '2026-10-30', 'S', 0],
        ['0820', '2026-10-30', 'S', 0],
        ['0830', '2026-10-30', 'S', 5],
    ]);
    $airline = nnWinsBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), nnWinsSearchClient($xml, ['0830' => 5], 445));

    expect(nnWinsSearch($airline, 'MJI', 'TUN', '2026-10-30'))
        ->toBe([['flight' => '0830', 'class' => 'S', 'seats' => 5, 'total' => 445.0]]);
});

test('BM 0400 R on 30 Oct shows with its real 1 seat one-way', function () {
    $xml = nnWinsAvailabilityXml('BM', 'MJI', 'TUN', [['0400', '2026-10-30', 'R', 1]]);
    $airline = nnWinsBind(new MedskyAirline(['base_url' => 'https://customer3.videcom.test']), nnWinsSearchClient($xml, ['0400' => 1], 490));

    expect(nnWinsSearch($airline, 'MJI', 'TUN', '2026-10-30'))
        ->toBe([['flight' => '0400', 'class' => 'R', 'seats' => 1, 'total' => 490.0]]);
});

test('YL 0841 S return on 29 Oct shows with its real 1 seat', function () {
    $xml = nnWinsAvailabilityXml('YL', 'TUN', 'MJI', [['0841', '2026-10-29', 'S', 1]]);
    $airline = nnWinsBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), nnWinsSearchClient($xml, ['0841' => 1], 415));

    expect(nnWinsSearch($airline, 'TUN', 'MJI', '2026-10-29', return: true))
        ->toBe([['flight' => '0841', 'class' => 'S', 'seats' => 1, 'total' => 415.0]]);
});
