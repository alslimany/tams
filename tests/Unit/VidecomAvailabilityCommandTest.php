<?php

use App\Services\Airline\Videcom\Airlines\GlobalAirline;
use App\Services\Airline\Videcom\VidecomClient;
use App\Services\Airline\Videcom\VidecomResponseParser;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

test('global air availability command uses classbands false from config', function () {
    config()->set('videcom_airlines.5S.availability.classbands', false);

    $client = new class extends VidecomClient
    {
        public array $commands = [];

        public function __construct() {}

        public function runCommand(string $command): string
        {
            $this->commands[] = $command;

            return <<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-30</ddaylcl>
                <dtimlcl>20:00:00</dtimlcl>
                <adaylcl>2026-04-30</adaylcl>
                <atimlcl>21:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754</fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id>Z</id>
                <av>5</av>
                <cur>LYD</cur>
                <pri>120</pri>
                <tax>20</tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $results = $provider->searchAvailability([
        'date' => '2026-04-30',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'qty' => 1,
    ]);

    expect($client->commands[0] ?? null)
        ->toContain('~X')
        ->and($results)->toHaveCount(1)
        ->and($results[0]->available_seats)->toBe(5)
        ->and($results[0]->segments[0]['class'])->toBe('Z');
});

test('parser keeps seat available flights even when fare fields are empty', function () {
    $xml = simplexml_load_string(<<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-30</ddaylcl>
                <dtimlcl>20:00:00</dtimlcl>
                <adaylcl>2026-04-30</adaylcl>
                <atimlcl>21:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754</fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id>Z</id>
                <av>5</av>
                <cur>LYD</cur>
                <pri></pri>
                <tax></tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML);

    $options = VidecomResponseParser::parseAvailability($xml, '5S', 'Global Air');

    expect($options)
        ->toHaveCount(1)
        ->and($options[0]->pricing['total'])->toBe(0.0)
        ->and($options[0]->pricing['currency'])->toBe('LYD')
        ->and($options[0]->segments[0]['class'])->toBe('Z')
        ->and($options[0]->available_seats)->toBe(5);
});

test('parser keeps flights even when available seats are zero', function () {
    $xml = simplexml_load_string(<<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-30</ddaylcl>
                <dtimlcl>20:00:00</dtimlcl>
                <adaylcl>2026-04-30</adaylcl>
                <atimlcl>21:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754</fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id>Z</id>
                <av>0</av>
                <cur>LYD</cur>
                <pri>120</pri>
                <tax>20</tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML);

    $options = VidecomResponseParser::parseAvailability($xml, '5S', 'Global Air');

    expect($options)
        ->toHaveCount(1)
        ->and($options[0]->available_seats)->toBe(0)
        ->and($options[0]->pricing['total'])->toBe(140.0);
});

test('search availability hides offers with zero final price', function () {
    config()->set('videcom_airlines.5S.availability.classbands', false);

    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return <<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-30</ddaylcl>
                <dtimlcl>20:00:00</dtimlcl>
                <adaylcl>2026-04-30</adaylcl>
                <atimlcl>21:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754</fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id>Z</id>
                <av>5</av>
                <cur>LYD</cur>
                <pri></pri>
                <tax></tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $results = $provider->searchAvailability([
        'date' => '2026-04-30',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'qty' => 1,
    ]);

    expect($results)->toHaveCount(0);
});

test('search availability returns only requested date when provider response includes multiple dates', function () {
    config()->set('videcom_airlines.5S.availability.classbands', false);

    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return <<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-30</ddaylcl>
                <dtimlcl>20:00:00</dtimlcl>
                <adaylcl>2026-04-30</adaylcl>
                <atimlcl>21:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754</fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id>Z</id>
                <av>5</av>
                <cur>LYD</cur>
                <pri>120</pri>
                <tax>20</tax>
            </fltav>
        </flt>
    </itin>
    <itin line="2" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-05-02</ddaylcl>
                <dtimlcl>14:30:00</dtimlcl>
                <adaylcl>2026-05-02</adaylcl>
                <atimlcl>15:45:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754</fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id>Z</id>
                <av>5</av>
                <cur>LYD</cur>
                <pri>120</pri>
                <tax>20</tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function applyAccuratePricing($option, int $adults, int $children, int $infants): void {}
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $results = $provider->searchAvailability([
        'date' => '2026-04-30',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'qty' => 1,
    ]);

    expect($results)
        ->toHaveCount(1)
        ->and($results[0]->departure_time)->toStartWith('2026-04-30');
});

test('consolidated pricing command strips whitespace from flight token parts', function () {
    Cache::flush();
    config()->set('videcom_airlines.5S.availability.classbands', false);

    $client = new class extends VidecomClient
    {
        public array $commands = [];

        public function __construct() {}

        public function runCommand(string $command): string
        {
            $this->commands[] = $command;

            if (str_starts_with($command, 'A')) {
                return <<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-30</ddaylcl>
                <dtimlcl>20:00:00</dtimlcl>
                <adaylcl>2026-04-30</adaylcl>
                <atimlcl>21:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0754 </fltno>
                <eqp>ER4</eqp>
            </fltdet>
            <fltav>
                <id> </id>
                <av>5</av>
                <cur>LYD</cur>
                <pri>120</pri>
                <tax>20</tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML;
            }

            return <<<'XML'
<PNR>
    <FareQuote>
        <FareStore Pax="1">
            <SegmentFS Fare="120" Tax1="20" Tax2="0" Tax3="0" />
        </FareStore>
        <FareStore Pax="2">
            <SegmentFS Fare="100" Tax1="15" Tax2="0" Tax3="0" />
        </FareStore>
        <FareStore Pax="3">
            <SegmentFS Fare="30" Tax1="5" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
</PNR>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline {};

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $provider->searchAvailability([
        'date' => '2026-04-30',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'adults' => 1,
        'children' => 1,
        'infants' => 1,
        'qty' => 2,
    ]);

    $pricingCommand = collect($client->commands)
        ->first(fn (string $command): bool => str_starts_with($command, 'i^'));

    expect($pricingCommand)
        ->not->toBeNull()
        ->and($pricingCommand)->toContain('0'.'5S'.'0754'.'Y'.'30APR'.'MJI'.'BEN'.'NN2')
        ->and($pricingCommand)->not->toContain('0754 30APR');
});

test('search availability keeps sold out requested date when route class price can be warmed from other dates', function () {
    config()->set('videcom_airlines.5S.availability.classbands', false);

    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return <<<'XML'
<xml>
    <classbands />
    <itin line="1" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-24</ddaylcl>
                <dtimlcl>09:00:00</dtimlcl>
                <adaylcl>2026-04-24</adaylcl>
                <atimlcl>10:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0002</fltno>
                <eqp>320</eqp>
            </fltdet>
            <fltav>
                <id>H</id>
                <av>0</av>
                <cur>LYD</cur>
                <pri></pri>
                <tax></tax>
            </fltav>
        </flt>
    </itin>
    <itin line="2" dep="MJI" arr="BEN" totalduration="75">
        <flt>
            <dep>MJI</dep>
            <arr>BEN</arr>
            <time>
                <ddaylcl>2026-04-25</ddaylcl>
                <dtimlcl>17:00:00</dtimlcl>
                <adaylcl>2026-04-25</adaylcl>
                <atimlcl>18:15:00</atimlcl>
                <duration>75</duration>
            </time>
            <fltdet>
                <airid>5S</airid>
                <fltno>0002</fltno>
                <eqp>320</eqp>
            </fltdet>
            <fltav>
                <id>H</id>
                <av>81</av>
                <cur>LYD</cur>
                <pri>120</pri>
                <tax>20</tax>
            </fltav>
        </flt>
    </itin>
</xml>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline
    {
        protected function fetchAllPaxPricesFromVrs($option, string $class): array
        {
            if (($option->available_seats ?? 0) <= 0) {
                throw new Exception('Cannot price sold-out flight date');
            }

            return [
                'AD' => ['fare' => 100.0, 'tax' => 20.0],
                'CH' => ['fare' => 0.0, 'tax' => 0.0],
                'IN' => ['fare' => 0.0, 'tax' => 0.0],
            ];
        }
    };

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $results = $provider->searchAvailability([
        'date' => '2026-04-24',
        'origin' => 'MJI',
        'destination' => 'BEN',
        'adults' => 1,
        'children' => 0,
        'infants' => 0,
        'qty' => 1,
    ]);

    expect($results)
        ->toHaveCount(1)
        ->and($results[0]->available_seats)->toBe(0)
        ->and((float) $results[0]->pricing['total'])->toBe(120.0);
});

test('open reservation availability is cached by airline route and class', function () {
    Cache::flush();

    $client = new class extends VidecomClient
    {
        public int $calls = 0;

        public function __construct() {}

        public function runCommand(string $command): string
        {
            $this->calls++;

            return <<<'XML'
<PNR>
    <FareQuote>
        <FareStore Pax="1" Cur="LYD" Total="120.00">
            <SegmentFS Seg="1" Fare="100.00" Tax1="20.00" Tax2="0" Tax3="0" />
        </FareStore>
    </FareQuote>
</PNR>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline {};

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $segment = [
        'flight_number' => '5S0754',
        'class' => 'H',
        'departure_time' => '2026-04-30 20:00:00',
        'departure_airport' => 'MJI',
        'arrival_airport' => 'BEN',
    ];

    expect($provider->canBookOpenReservation($segment))->toBeTrue()
        ->and($provider->canBookOpenReservation($segment))->toBeTrue()
        ->and($client->calls)->toBe(1);
});

test('open reservation availability accepts valid pnr itinerary without fare totals', function () {
    Cache::flush();

    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return <<<'XML'
<PNR NeedFG="True">
    <Itinerary>
        <Itin Line="1" AirID="5S" FltNo="0754" Class="C" DepDate="2026-04-30" Depart="MJI" Arrive="BEN" Status="QQ" PaxQty="1" />
    </Itinerary>
    <FareQuote>
        <FQItin />
        <FareStore />
    </FareQuote>
</PNR>
XML;
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline {};

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $segment = [
        'flight_number' => '5S0754',
        'class' => 'C',
        'departure_time' => '2026-04-30 20:00:00',
        'departure_airport' => 'MJI',
        'arrival_airport' => 'BEN',
    ];

    expect($provider->canBookOpenReservation($segment))->toBeTrue();
});

test('open reservation availability returns false for explicit provider error message', function () {
    Cache::flush();

    $client = new class extends VidecomClient
    {
        public function __construct() {}

        public function runCommand(string $command): string
        {
            return 'ERROR: CLASS H CANNOT BE BOOKED AS OPEN ON THIS ROUTE';
        }
    };

    $provider = new class(['base_url' => 'https://booking.gair.test']) extends GlobalAirline {};

    \Closure::bind(function (VidecomClient $client): void {
        $this->client = $client;
    }, $provider, $provider)($client);

    $segment = [
        'flight_number' => '5S0754',
        'class' => 'H',
        'departure_time' => '2026-04-30 20:00:00',
        'departure_airport' => 'MJI',
        'arrival_airport' => 'BEN',
    ];

    expect($provider->canBookOpenReservation($segment))->toBeFalse();
});
