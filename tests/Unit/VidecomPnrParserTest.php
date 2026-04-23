<?php

use App\Services\Airline\Videcom\VidecomPnrParser;

test('it parses videcom pnr xml with ancillaries tickets and taxes', function () {
    $pnr = simplexml_load_string(<<<'XML'
<PNR RLOC="AAKHSU">
    <Names>
        <PAX PaxNo="1" Title="MR" FirstName="ABDULMUHAIMEN" Surname="SALEM" PaxType="AD" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="YI" FltNo="0500" Class="L" DepDate="2025-07-07" Depart="MJI" Arrive="IST" Status="HK" ClassBandDisplayName="Oya Light" SelectSeat="True" />
    </Itinerary>
    <MPS>
        <MP Line="1" MPID="DXBC" Pax="1" Seg="2" MPSCur="LYD" MPSAmt="59.98" MPSID="3">#2 YI 0501 L TH 17JUL25IST MJI</MP>
    </MPS>
    <APFAX>
        <AFX Line="1" AFXID="SEAT" Pax="1" Seg="1" seat="25E">HK1 MJIIST 25E {SEATID=459867}</AFX>
    </APFAX>
    <FareQuote>
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="1700.00" />
        <FareStore FSID="FQC-MPS" MPSID="3" Pax="1" Cur="LYD" Total="59.98" />
        <FareTax>
            <PaxTax Seg="1" Pax="1" Code="YR" Cur="LYD" Amnt="20.00" desc="insurance " />
        </FareTax>
    </FareQuote>
    <Payments>
        <FOP Line="1" FOPID="III" PayCur="LYD" PayAmt="3400.00" PayRef="MEDIAN ABC TOURS01012" PayDate="06JUL25" />
    </Payments>
    <Tickets>
        <TKT Pax="1" TKTID="ELFT" TktNo="854 3220420747" Coupon="01" TktFltDate="07JUL2025" TktFltNo="YI0500" TktDepart="MJI" TktArrive="IST" TktBClass="L" IssueDate="06JUL2025" Status="F" SegNo="01" HoldPcs="2" HoldWt="25K" HandWt="0K" />
    </Tickets>
</PNR>
XML);

    $parsed = VidecomPnrParser::parse($pnr);

    expect($parsed['rloc'])->toBe('AAKHSU')
        ->and($parsed['names'][0]['first_name'])->toBe('ABDULMUHAIMEN')
        ->and($parsed['itinerary'][0]['airline'])->toBe('YI')
        ->and($parsed['itinerary'][0]['class_band'])->toBe('Oya Light')
        ->and($parsed['mps'][0]['code'])->toBe('DXBC')
        ->and($parsed['mps'][0]['amount'])->toBe(59.98)
        ->and($parsed['apfax'][0]['seat'])->toBe('25E')
        ->and($parsed['fare_stores'][1]['fsid'])->toBe('FQC-MPS')
        ->and($parsed['fare_taxes'][0]['code'])->toBe('YR')
        ->and($parsed['payments'][0]['reference'])->toContain('MEDIAN')
        ->and($parsed['tickets'][0]['ticket_number'])->toBe('854 3220420747')
        ->and($parsed['tickets'][0]['hold_wt'])->toBe('25K');
});

test('it formats videcom pnr xml into structured order details json', function () {
    $pnr = simplexml_load_string(<<<'XML'
<PNR RLOC="AAJ6DU" PNRLocked="False" CanVoid="True" VoidCutoffTime="2026-04-21T22:00">
    <Names>
        <PAX GrpNo="1" GrpPaxNo="1" PaxNo="1" Title="MR" FirstName="ABDULLAH" Surname="MOHAMMED" PaxType="AD" Age="" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="5S" FltNo="0754" Class="Z" DepDate="2026-04-30" Depart="MJI" Arrive="BEN" Status="HK" PaxQty="1" DepTime="20:00:00" ArrTime="21:15:00" Stops="0" Cabin="Y" ClassBand="ECONOMY Z" ClassBandDisplayName="Z" SelectSeat="False" MMBSelectSeat="False" OpenSeating="False" MMBCheckinAllowed="False" />
    </Itinerary>
    <Contacts>
        <CTC Line="1" CTCID="M" Pax="0">911388788</CTC>
    </Contacts>
    <FareQuote>
        <FQItin Seg="1" Cur="LYD" FQI="SITI 1011" Total="300" Fare="190.41" Tax1="109.59" Tax2="0" Tax3="0" />
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="300.00">
            <SegmentFS Seg="1" Fare="190.41" Tax1="109.59" Tax2="0" Tax3="0" />
        </FareStore>
        <FareTax>
            <PaxTax Seg="1" Pax="1" Code="YR" Cur="LYD" Amnt="30.00" desc="TAX OF " />
        </FareTax>
    </FareQuote>
    <Payments>
        <FOP Line="1" FOPID="III" PayCur="LYD" PayAmt="300.00" PayRef="GLOBAL102 ABC TOURS01012" PNRCur="LYD" PNRAmt="300.00" PNRExRate="1" PayDate="21APR26" />
    </Payments>
    <Tickets>
        <TKT Pax="1" TKTID="ETKT" TktNo="301 2300303215" Coupon="01" TktFltDate="30APR2026" TktFltNo="5S0754" TktDepart="MJI" TktArrive="BEN" TktBClass="Z" IssueDate="21APR2026" Status="O" SegNo="01" Title="MR" Firstname="ABDULLAH" Surname="MOHAMMED" HoldPcs="2" HoldWt="20K" HandWt="0K" WebCheckOut="False" />
    </Tickets>
    <Basket>
        <Outstanding cur="LYD" amount="0" info="" />
        <Outstandingairmiles cur="LYD" amount="-190.41" info="Outstanding Currency and Airmiles" />
    </Basket>
</PNR>
XML);

    $formatted = VidecomPnrParser::formatForOrderDetails($pnr);

    expect($formatted['rloc'])->toBe('AAJ6DU')
        ->and($formatted['iata'])->toBe('5S')
        ->and(array_keys($formatted))->toBe([
            'itineraries',
            'passengers',
            'contacts',
            'payments',
            'timelimits',
            'tickets',
            'remarks',
            'basket',
            'mps',
            'fare_qoute',
            'fare_store',
            'taxes',
            'total_fare',
            'total_tax',
            'total_price',
            'currency',
            'is_issued',
            'is_locked',
            'is_voidable',
            'void_cutoff_time',
            'rloc',
            'iata',
        ])
        ->and($formatted['itineraries'][0]['from'])->toBe('MJI')
        ->and($formatted['itineraries'][0]['class_band'])->toBe('ECONOMY Z')
        ->and($formatted['itineraries'][0]['class_band_display_name'])->toBe('Z')
        ->and($formatted['passengers'][0]['first_name'])->toBe('ABDULLAH')
        ->and($formatted['passengers'][0]['last_name'])->toBe('MOHAMMED')
        ->and($formatted['contacts'][0]['type'])->toBe('M')
        ->and($formatted['contacts'][0]['pax_id'])->toBe(0)
        ->and($formatted['payments'][0]['form_of_payment_id'])->toBe('III')
        ->and($formatted['payments'][0]['date'])->toBe('2026-04-21')
        ->and($formatted['tickets'][0]['ticket_number'])->toBe('301 2300303215')
        ->and($formatted['tickets'][0]['hold_pices'])->toBe('2')
        ->and($formatted['remarks'])->toBe([])
        ->and($formatted['basket'][0]['id'])->toBe('outstanding')
        ->and($formatted['fare_qoute'][0]['segment_id'])->toBe('1')
        ->and($formatted['fare_store'][0]['segments'][0]['tax1'])->toBe(109.59)
        ->and($formatted['taxes'][0]['code'])->toBe('YR')
        ->and($formatted['total_price'])->toBe('300')
        ->and($formatted['is_issued'])->toBeTrue()
        ->and($formatted['void_cutoff_time'])->toBe('2026-04-21 22:00');
});

test('it formats videcom pnr xml using the normalized order item json structure', function () {
    $pnr = simplexml_load_string(<<<'XML'
<PNR RLOC="AD9QZQ" PNRLocked="False" CanVoid="True" VoidCutoffTime="2026-04-23T22:00">
    <Names>
        <PAX GrpNo="1" GrpPaxNo="1" PaxNo="1" Title="MR" FirstName="ABDULLAH" Surname="ISHTIWY" PaxType="AD" Age="" />
    </Names>
    <Itinerary>
        <Itin Line="1" AirID="UZ" FltNo="0006" Class="H" DepDate="2026-04-30" Depart="MJI" Arrive="BEN" Status="HK" PaxQty="1" DepTime="20:00:00" ArrTime="21:15:00" Stops="0" Cabin="Y" ClassBand="ECONOMY H" ClassBandDisplayName="H" SelectSeat="True" MMBSelectSeat="True" OpenSeating="False" MMBCheckinAllowed="False" />
    </Itinerary>
    <MPS />
    <Contacts>
        <CTC Line="1" CTCID="M" Pax="1">911388788</CTC>
        <CTC Line="2" CTCID="E" Pax="1">ALSLIMANY@GMAIL.COM</CTC>
    </Contacts>
    <FareQuote>
        <FQItin Seg="1" Cur="LYD" FQI="SITI 1974" Total="350" Fare="305.50" Tax1="44.50" Tax2="0" Tax3="0" />
        <FareStore FSID="FQC" Pax="1" Cur="LYD" Total="350.00">
            <SegmentFS Seg="1" Fare="305.50" Tax1="44.50" Tax2="0" Tax3="0" />
        </FareStore>
        <FareStore FSID="Total" Pax="" Cur="LYD" Total="350.00" />
        <FareTax>
            <PaxTax Seg="1" Pax="1" Code="ST" Cur="LYD" Amnt="1.00" desc="booking tax" />
            <PaxTax Seg="1" Pax="1" Code="WV" Cur="LYD" Amnt="5.00" desc="Libyan departure tax" />
        </FareTax>
    </FareQuote>
    <Payments>
        <FOP Line="1" FOPID="III" PayCur="LYD" PayAmt="350.00" PayRef="AG874 ABC TOURS01012" PNRCur="LYD" PNRAmt="350.00" PNRExRate="1" PayDate="23APR26" />
    </Payments>
    <TimeLimits />
    <Tickets>
        <TKT Pax="1" TKTID="ETKT" TktNo="928 2972468833" Coupon="01" TktFltDate="30APR2026" TktFltNo="UZ0006" TktDepart="MJI" TktArrive="BEN" TktBClass="H" IssueDate="23APR2026" Status="O" SegNo="01" Title="MR" Firstname="ABDULLAH" Surname="ISHTIWY" HoldPcs="1" HoldWt="25K" HandWt="0K" WebCheckOut="False" />
    </Tickets>
    <Remarks />
    <Basket>
        <Outstanding cur="LYD" amount="0" info="" />
        <Outstandingairmiles cur="LYD" amount="-305.50" info="Outstanding Currency and Airmiles" airmiles="0" />
    </Basket>
    <RLE AirID="UZ" />
</PNR>
XML);

    $formatted = VidecomPnrParser::formatForOrderDetails($pnr);

    expect($formatted)
        ->toMatchArray([
            'rloc' => 'AD9QZQ',
            'iata' => 'UZ',
            'currency' => 'LYD',
            'total_fare' => '305.5',
            'total_tax' => '44.5',
            'total_price' => '350',
            'is_issued' => true,
            'is_locked' => false,
            'is_voidable' => true,
            'void_cutoff_time' => '2026-04-23 22:00',
        ])
        ->and($formatted['itineraries'][0])->toMatchArray([
            'itinerary_id' => '1',
            'airline_id' => 'UZ',
            'flight_number' => '0006',
            'class' => 'H',
            'cabin' => 'Y',
            'class_band' => 'ECONOMY H',
            'class_band_display_name' => 'H',
            'date' => '2026-04-30',
            'from' => 'MJI',
            'to' => 'BEN',
            'departure' => '20:00:00',
            'arrival' => '21:15:00',
            'status' => 'HK',
            'number_of_passengers' => '1',
            'number_of_stops' => 0,
            'select_seat' => true,
            'mmb_select_seat' => true,
            'open_seating' => false,
            'mmb_checkin_allow' => false,
        ])
        ->and($formatted['passengers'][0])->toMatchArray([
            'id' => '1',
            'group_number' => '1',
            'passenger_group_number' => '1',
            'title' => 'MR',
            'first_name' => 'ABDULLAH',
            'last_name' => 'ISHTIWY',
            'type' => 'AD',
            'age' => '',
        ])
        ->and($formatted['contacts'][0])->toMatchArray([
            'line' => 1,
            'type' => 'M',
            'pax_id' => 0,
            'value' => '911388788',
        ])
        ->and($formatted['payments'][0])->toMatchArray([
            'itinerary_id' => '1',
            'form_of_payment_id' => 'III',
            'currency' => 'LYD',
            'amount' => 350.0,
            'reference' => 'AG874 ABC TOURS01012',
            'pnr_amount' => 350.0,
            'pnr_extchange_rate' => 1.0,
            'date' => '2026-04-23',
        ])
        ->and($formatted['tickets'][0])->toMatchArray([
            'passenger_id' => '1',
            'ticket_id' => 'ETKT',
            'ticket_number' => '928 2972468833',
            'coupon' => '01',
            'flight_date' => '2026-04-30',
            'flight_number' => 'UZ0006',
            'from' => 'MJI',
            'to' => 'BEN',
            'class' => 'H',
            'issue_date' => '2026-04-23',
            'status' => 'O',
            'segment_number' => '01',
            'title' => 'MR',
            'first_name' => 'ABDULLAH',
            'last_name' => 'ISHTIWY',
            'hold_pices' => '1',
            'hold_weight' => '25K',
            'hand_weight' => '0K',
            'web_checkout' => false,
        ])
        ->and($formatted['basket'][0])->toMatchArray([
            'id' => 'outstanding',
            'currency' => 'LYD',
            'amount' => '0',
        ])
        ->and($formatted['fare_qoute'][0])->toMatchArray([
            'segment_id' => '1',
            'basic_fare' => 'SITI 1974',
            'currency' => 'LYD',
            'fare' => '305.50',
            'tax' => 44.5,
            'total' => 350.0,
        ])
        ->and($formatted['fare_store'][0]['segments'][0])->toMatchArray([
            'segment_id' => '1',
            'fare' => 305.5,
            'tax1' => 44.5,
            'tax2' => 0.0,
            'tax3' => 0.0,
        ])
        ->and($formatted['taxes'][1])->toMatchArray([
            'segment_id' => '1',
            'pax_id' => '1',
            'code' => 'WV',
            'currency' => 'LYD',
            'amount' => '5.00',
            'description' => 'Libyan departure tax',
        ])
        ->and($formatted['timelimits'])->toBe([])
        ->and($formatted['remarks'])->toBe([])
        ->and($formatted['mps'])->toBe([]);
});
