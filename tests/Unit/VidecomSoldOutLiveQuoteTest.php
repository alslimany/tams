<?php

use App\Services\Airline\Videcom\Airlines\BuraqAirline;
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
 * 0-seat classes from monitor 20261023-211400: shown only with a fresh live
 * Videcom quote (NN, then QQ), hidden when every quote fails, never priced
 * from the route/class cache.
 */
beforeEach(function (): void {
    Cache::flush();
    (fn () => static::$pricingAttempts = [])->bindTo(null, BaseVidecomAirline::class)();
});

/**
 * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $flights  [flight, date, class, av]
 */
function soldOutXml(string $airline, string $origin, string $dest, array $flights): string
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
 * NN always refuses (sold out). QQ prices the flight+class keys in $qqPrices.
 *
 * @param  array<string, float>  $qqPrices  e.g. ['0800J' => 975]
 */
function soldOutClient(string $availability, array $qqPrices): VidecomClient
{
    return new class($availability, $qqPrices) extends VidecomClient
    {
        public array $quotes = [];

        public function __construct(public string $availability, public array $qq) {}

        public function runCommand(string $command): string
        {
            (new ReadOnlyCommandGuard)->check($command);

            if (str_starts_with($command, 'A')) {
                return $this->availability;
            }

            $this->quotes[] = $command;
            preg_match('/\^0[A-Z0-9]{2}(\d{4})([A-Z])\d{2}[A-Z]{3}[A-Z]{6}(NN|QQ)\d/', $command, $m);
            $key = ($m[1] ?? '').($m[2] ?? '');

            if (($m[3] ?? '') === 'NN') {
                return 'ERROR: NO AV, CLASS SOLD OUT ON THIS SERVICE';
            }

            if (! isset($this->qq[$key])) {
                return 'ERROR: CLASS CANNOT BE BOOKED AS OPEN ON THIS ROUTE';
            }

            $price = $this->qq[$key];

            return '<PNR><FareQuote><FareStore Pax="1" Cur="LYD" Total="'.$price.'"><SegmentFS Seg="1" Fare="'.($price - 40).'" Tax1="40" HoldWt="20K" HandWt="7K" /></FareStore></FareQuote></PNR>';
        }
    };
}

function soldOutBind(BaseVidecomAirline $airline, VidecomClient $client): BaseVidecomAirline
{
    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $airline, $airline)($client);

    return $airline;
}

/**
 * @return list<array{flight: string, class: string, seats: int, total: float}>
 */
function soldOutSearch(BaseVidecomAirline $airline, string $origin, string $dest, string $date, bool $return = false): array
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

function soldOutSeedCache(string $airline, string $origin, string $dest, string $class, float $total): void
{
    foreach (['AD', 'CH', 'IN'] as $type) {
        PricingCacheService::put($airline, $origin, $dest, $class, $type, ['fare' => $total - 40, 'tax' => 40]);
    }
}

test('YL J 0800/0810/0820/0830 and Y 0830 on 26 Oct show with the live QQ quote', function () {
    // A stale cached price must not leak in: the live quote wins.
    soldOutSeedCache('YL', 'MJI', 'TUN', 'J', 111);

    $xml = soldOutXml('YL', 'MJI', 'TUN', [
        ['0800', '2026-10-26', 'J', 0], ['0810', '2026-10-26', 'J', 0],
        ['0820', '2026-10-26', 'J', 0], ['0830', '2026-10-26', 'J', 0],
        ['0830', '2026-10-26', 'Y', 0],
    ]);
    $client = soldOutClient($xml, ['0800J' => 975, '0810J' => 975, '0820J' => 975, '0830J' => 975, '0830Y' => 745]);
    $airline = soldOutBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), $client);

    expect(soldOutSearch($airline, 'MJI', 'TUN', '2026-10-26'))->toBe([
        ['flight' => '0800', 'class' => 'J', 'seats' => 0, 'total' => 975.0],
        ['flight' => '0810', 'class' => 'J', 'seats' => 0, 'total' => 975.0],
        ['flight' => '0820', 'class' => 'J', 'seats' => 0, 'total' => 975.0],
        ['flight' => '0830', 'class' => 'J', 'seats' => 0, 'total' => 975.0],
        ['flight' => '0830', 'class' => 'Y', 'seats' => 0, 'total' => 745.0],
    ]);

    // The cache is neither read nor overwritten for 0-seat classes.
    expect(PricingCacheService::get('YL', 'MJI', 'TUN', 'J', 'AD'))->toBe(['fare' => 71.0, 'tax' => 40]);
});

test('YL J returns 0811/0821 on 29 Oct show at 1,015', function () {
    $xml = soldOutXml('YL', 'TUN', 'MJI', [['0811', '2026-10-29', 'J', 0], ['0821', '2026-10-29', 'J', 0]]);
    $airline = soldOutBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), soldOutClient($xml, ['0811J' => 1015, '0821J' => 1015]));

    expect(array_column(soldOutSearch($airline, 'TUN', 'MJI', '2026-10-29', return: true), 'total'))->toBe([1015.0, 1015.0]);
});

test('BM W returns 0401/0407 (800) and C 0401 (1,000) on 29 Oct show', function () {
    $xml = soldOutXml('BM', 'TUN', 'MJI', [
        ['0401', '2026-10-29', 'W', 0], ['0407', '2026-10-29', 'W', 0], ['0401', '2026-10-29', 'C', 0],
    ]);
    $airline = soldOutBind(new MedskyAirline(['base_url' => 'https://customer3.videcom.test']), soldOutClient($xml, ['0401W' => 800, '0407W' => 800, '0401C' => 1000]));

    expect(soldOutSearch($airline, 'TUN', 'MJI', '2026-10-29', return: true))->toBe([
        ['flight' => '0401', 'class' => 'W', 'seats' => 0, 'total' => 800.0],
        ['flight' => '0407', 'class' => 'W', 'seats' => 0, 'total' => 800.0],
        ['flight' => '0401', 'class' => 'C', 'seats' => 0, 'total' => 1000.0],
    ]);
});

test('UZ Y returns 0214/0218 show at 530 while UZ L stays hidden', function () {
    $xml = soldOutXml('UZ', 'TUN', 'MJI', [
        ['0214', '2026-10-29', 'Y', 0], ['0218', '2026-10-29', 'Y', 0], ['0214', '2026-10-29', 'L', 0],
    ]);
    $client = soldOutClient($xml, ['0214Y' => 530, '0218Y' => 530, '0214L' => 680]);
    $airline = soldOutBind(new BuraqAirline(['base_url' => 'https://booking.buraq.test']), $client);

    expect(soldOutSearch($airline, 'TUN', 'MJI', '2026-10-29', return: true))->toBe([
        ['flight' => '0214', 'class' => 'Y', 'seats' => 0, 'total' => 530.0],
        ['flight' => '0218', 'class' => 'Y', 'seats' => 0, 'total' => 530.0],
    ])->and(collect($client->quotes)->contains(fn ($c) => str_contains($c, '0214L')))->toBeFalse();
});

test('BM 0400/0402 R on 26 Oct and YL 0810/0820 S on 30 Oct stay hidden when every quote fails, even with a cached price', function () {
    soldOutSeedCache('BM', 'MJI', 'TUN', 'R', 490);
    soldOutSeedCache('YL', 'MJI', 'TUN', 'S', 445);

    $bm = soldOutBind(new MedskyAirline(['base_url' => 'https://customer3.videcom.test']), $bmClient = soldOutClient(
        soldOutXml('BM', 'MJI', 'TUN', [['0400', '2026-10-26', 'R', 0], ['0402', '2026-10-26', 'R', 0]]),
        [],
    ));
    $yl = soldOutBind(new LibyanWingsAirline(['base_url' => 'https://booking.libyanwings.test']), soldOutClient(
        soldOutXml('YL', 'MJI', 'TUN', [['0810', '2026-10-30', 'S', 0], ['0820', '2026-10-30', 'S', 0]]),
        [],
    ));

    expect(soldOutSearch($bm, 'MJI', 'TUN', '2026-10-26'))->toBeEmpty()
        ->and(soldOutSearch($bm, 'MJI', 'TUN', '2026-10-26', return: true))->toBeEmpty()
        ->and(soldOutSearch($yl, 'MJI', 'TUN', '2026-10-30'))->toBeEmpty()
        // Live NN then QQ was tried; the failure did not overwrite the cache.
        ->and(collect($bmClient->quotes)->contains(fn ($c) => str_contains($c, 'NN2')))->toBeTrue()
        ->and(collect($bmClient->quotes)->contains(fn ($c) => str_contains($c, 'QQ2')))->toBeTrue()
        ->and(PricingCacheService::get('BM', 'MJI', 'TUN', 'R', 'AD'))->toBe(['fare' => 450.0, 'tax' => 40]);
});
