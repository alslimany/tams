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
