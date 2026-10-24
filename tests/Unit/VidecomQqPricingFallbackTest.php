<?php

use App\DTOs\Airline\RoundTripPriceRequest;
use App\Exceptions\NoFareAvailableException;
use App\Services\Airline\Videcom\Airlines\BuraqAirline;
use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\BaseVidecomAirline;
use App\Services\Airline\Videcom\Monitor\ReadOnlyCommandGuard;
use App\Services\Airline\Videcom\VidecomClient;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Fake client: NN entries get the sold-out error, QQ entries get $qqXml.
 */
function qqFallbackClient(?string $qqXml): VidecomClient
{
    return new class($qqXml) extends VidecomClient
    {
        public array $commands = [];

        public function __construct(public ?string $qqXml) {}

        public function runCommand(string $command): string
        {
            // Every command this path sends must pass the monitor's read-only guard.
            (new ReadOnlyCommandGuard)->check($command);
            $this->commands[] = $command;

            if (str_contains($command, 'QQ') && $this->qqXml !== null) {
                return $this->qqXml;
            }

            return 'ERROR: NO AV, Y CLASS SOLD OUT ON THIS SERVICE';
        }
    };
}

function qqFallbackBind(BaseVidecomAirline $airline, VidecomClient $client): BaseVidecomAirline
{
    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $airline, $airline)($client);

    return $airline;
}

function qqFallbackRequest(): RoundTripPriceRequest
{
    return new RoundTripPriceRequest(
        outboundSegment: ['flight_number' => '0213', 'class' => 'H', 'date' => '2026-10-26 08:00:00', 'origin' => 'MJI', 'destination' => 'TUN'],
        returnSegment: ['flight_number' => '0214', 'class' => 'Y', 'date' => '2026-10-29 10:00:00', 'origin' => 'TUN', 'destination' => 'MJI'],
        passengers: ['adults' => 1, 'children' => 0, 'infants' => 0],
        outboundPrice: 740.0,
    );
}

test('#1 one-way pricing falls back to QQ when NN reports the class sold out', function () {
    $client = qqFallbackClient(<<<'XML'
<PNR>
    <FareQuote>
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="530.00">
            <SegmentFS Seg="1" Fare="480" Tax1="50" Tax2="0" Tax3="0" HoldWt="23K" HandWt="0K" HoldPcs="1" />
        </FareStore>
    </FareQuote>
</PNR>
XML);
    $airline = qqFallbackBind(new BuraqAirline(['base_url' => 'https://booking.buraq.test']), $client);

    $option = (object) [
        'departure_time' => '2026-10-29 10:00:00',
        'flight_number' => '0214',
        'departure_airport' => 'TUN',
        'arrival_airport' => 'MJI',
    ];

    $method = new ReflectionMethod(BaseVidecomAirline::class, 'fetchAllPaxPricesFromVrs');
    $prices = $method->invoke($airline, $option, 'Y');

    // NN2, then NN1 (single seat), and only then the QQ fallback.
    expect($client->commands)->toHaveCount(3)
        ->and($client->commands[0])->toContain('NN2')
        ->and($client->commands[1])->toContain('NN1')
        ->and($client->commands[2])->toContain('QQ2')
        ->and((float) $prices['AD']['fare'] + (float) $prices['AD']['tax'])->toBe(530.0)
        ->and($prices['_baggage']['hold_weight'])->toBe('23K');
});

test('#1 round-trip quote falls back to QQ when NN is refused', function () {
    $client = qqFallbackClient(<<<'XML'
<PNR>
    <FareQuote>
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="1270.00">
            <SegmentFS Seg="1" Fare="700" Tax1="40" Tax2="0" Tax3="0" />
            <SegmentFS Seg="2" Fare="490" Tax1="40" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
</PNR>
XML);
    $airline = qqFallbackBind(new BuraqAirline(['base_url' => 'https://booking.buraq.test']), $client);

    $result = $airline->priceRoundTrip(qqFallbackRequest());

    expect($client->commands[0])->toContain('NN1')
        ->and($client->commands[1])->toContain('QQ1')
        ->and($result->totalPrice)->toBe(1270.0)
        ->and($result->returnLegPrice)->toBe(530.0);
});

test('#1 round-trip quote still fails when both NN and QQ are refused', function () {
    $client = qqFallbackClient(null);
    $airline = qqFallbackBind(new BuraqAirline(['base_url' => 'https://booking.buraq.test']), $client);

    expect(fn () => $airline->priceRoundTrip(qqFallbackRequest()))->toThrow(NoFareAvailableException::class);
});

test('UZ no longer has a blanket hidden class (L is shown when QQ prices it)', function () {
    $hidden = new ReflectionMethod(BaseVidecomAirline::class, 'hiddenClasses');

    expect($hidden->invoke(new BuraqAirline(['base_url' => 'https://booking.buraq.test'])))->toBe([])
        ->and($hidden->invoke(new GlobalAirline(['base_url' => 'https://booking.gair.test'])))->toBe([])
        ->and(config('videcom_airlines.UZ.hidden_classes'))->toBeNull();
});

test('hidden_classes config is still honoured when an airline sets it', function () {
    $hidden = new ReflectionMethod(BaseVidecomAirline::class, 'hiddenClasses');

    expect($hidden->invoke(new BuraqAirline(['base_url' => 'https://booking.buraq.test', 'hidden_classes' => ['l', 'Q']])))->toBe(['L', 'Q']);
});

test('UZ availability keeps a seated L like the other classes', function () {
    $itin = fn (string $class, int $av): string => <<<XML
    <itin line="1" dep="MJI" arr="TUN" totalduration="75">
        <flt>
            <dep>MJI</dep><arr>TUN</arr>
            <time><ddaylcl>2026-10-26</ddaylcl><dtimlcl>08:00:00</dtimlcl><adaylcl>2026-10-26</adaylcl><atimlcl>09:15:00</atimlcl><duration>75</duration></time>
            <fltdet><airid>UZ</airid><fltno>0213</fltno><eqp>737</eqp></fltdet>
            <fltav><id>{$class}</id><av>{$av}</av><cur>LYD</cur><pri>600</pri><tax>80</tax></fltav>
        </flt>
    </itin>
XML;

    $xml = '<xml><classbands />'.$itin('H', 5).$itin('L', 3).'</xml>';

    $client = new class($xml) extends VidecomClient
    {
        public function __construct(public string $xml) {}

        public function runCommand(string $command): string
        {
            return str_starts_with($command, 'A') ? $this->xml : 'ERROR';
        }
    };

    $airline = new class(['base_url' => 'https://booking.buraq.test']) extends BuraqAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };
    qqFallbackBind($airline, $client);

    $results = $airline->searchAvailability([
        'date' => '2026-10-26', 'origin' => 'MJI', 'destination' => 'TUN',
        'adults' => 1, 'children' => 0, 'infants' => 0, 'qty' => 1,
    ]);

    $classes = array_map(fn ($o) => $o->segments[0]['class'], $results);

    expect($classes)->toContain('H')->toContain('L');
});
