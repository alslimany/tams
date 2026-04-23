Skill File: Videcom Round-Trip Pricing & Response Parsing
Purpose
This skill file documents the Videcom API commands and XML response structure for round-trip flight pricing (without booking). It covers passenger types, fare components, taxes, baggage allowances, and how to extract per-passenger, per-segment pricing from the response. Use this as a reference when implementing the priceRoundTrip() method in BaseVidecomAirline.

1. Round-Trip Pricing Command
Command Structure
text
I^-{paxString}^{segment1}^{segment2}^FG^FS1^*r~x
I^ – initialise fresh session (uppercase I not i).

{paxString} – defines number and types of passengers.

{segment1} – outbound flight segment (hold space with NN).

{segment2} – return flight segment.

FG^FS1 – fare quote and store.

*r~x – end transaction and return XML.

Passenger String Format
text
-{totalPax}PAX/{paxDetails}
Each passenger detail:

Adult: A# (where # is a placeholder letter, e.g., A#).

Child: B#.CH{age} (e.g., B#.CH10 for child aged 10).

Infant: C#.IN{ageMonths} (e.g., C#.IN06 for infant 6 months old).

Example (2 adults, 1 child 10 years, 1 infant 6 months):

text
-4PAX/A#/B#.CH10/C#.IN06
Flight Segment Format (for pricing, no booking)
text
0{airline}{flightNumber}{class}{date}{origin}{destination}NN{paxCount}
0 – segment entry.

airline – 2-letter airline code (e.g., UZ).

flightNumber – up to 4 digits (e.g., 0002).

class – booking class (e.g., H).

date – DDMMM format, day zero‑padded, month first letter uppercase (e.g., 01MAY). Not 01MAY but 01May? Check: In your working command you used 01MAY (all caps). Actually from your example: 01MAY (all uppercase). Use dM in PHP returns 01May – but Videcom expects uppercase month? The example shows 01MAY. I'll use strtoupper($date->format('dM')) to get 01MAY.

origin, destination – 3-letter airport codes.

NN – action code (hold space). For pricing only, using NN is acceptable because we end with *r which discards the session. To be safe, you can use QQ (open segment, no hold) – test with airline.

paxCount – number of passengers (must match total in paxString minus infants? Infants are lap children and not counted in seat count. In example: 2 adults + 1 child = 3 seat‑occupying pax, so NN2 not NN3. But your command used NN2 for 2 adults + 1 child + 1 infant? Wait: You had NN2 for two segments but paxString had total 4 pax? Actually the response shows PaxQty="2" on segments. That’s because infants are lap children and do not occupy seats. So paxCount = number of seat‑occupying passengers (adults + children). Infants are added separately via passenger string but not in seat count.

Important: The number after NN is the number of seats (adults + children). Infants are not counted. In your example: 2 adults + 1 child = 3 seats, but you used NN2 – that’s inconsistent. Let’s check the response: It shows PaxQty="2". So maybe you had 1 adult + 1 child? The pax string -3PAX/A#/B#.CH10/C#.IN06 = 3 passengers (A, B, C) but C is infant. Seats = 2 (A + B). So NN2 is correct. So formula: seatCount = adults + children.

Full Command Example
text
I^-3PAX/A#/B#.CH10/C#.IN06^0UZ0002H01MAYMJIBENNN2^0UZ0003H03MAYBENMJINN2^FG^FS1^*r~x
2. XML Response Structure (Relevant for Pricing)
The response is a <PNR> element (empty RLOC because no booking was saved). Key sections:

2.1 Passenger List (<Names><PAX>)
Each passenger has:

PaxNo (1,2,3)

PaxType: AD (adult), CH (child), IN (infant)

Age (e.g., 10 YRS, 6 MTHS)

FirstName, Surname (placeholders if you used dummy names like A, B, C)

2.2 Itinerary (<Itinerary><Itin>)
Line – segment number (1 = outbound, 2 = return)

AirID, FltNo, Class, DepDate, Depart, Arrive

Status – HK (confirmed), NN (held)

PaxQty – number of seats booked (adults+children)

DepTime, ArrTime

ClassBand, ClassBandDisplayName

2.3 Fare Quote (<FareQuote>)
a) Per‑segment fare summary (<FQItin>)
One for each segment (1 and 2). Attributes:

Seg – segment number

Cur – currency (e.g., LYD)

Total – total for that segment for all passengers (including taxes)

Fare – base fare total (excluding taxes)

Tax1, Tax2, Tax3 – total taxes per segment

In the example:

Segment 1: Total="350" (2 adults? Actually total for all seat‑occupying pax on that segment? Wait, it shows 350 for segment 1. But later per‑pax fares sum to 305.50 + 229.13 + 30.55 = 565.18? That’s not matching. The Total in <FQItin> seems to be the total for the segment for all passengers? But 350 is much lower than 565. So maybe it’s the fare for one passenger? Inconsistent. Better to rely on per‑passenger <FareStore> elements.)

Recommendation: Use the per‑passenger <FareStore> elements to get accurate breakdown.

b) Per‑passenger fare store (<FareStore FSID="FQC" Pax="n">)
Pax="1" – passenger number

Total – total for that passenger for all segments

Inside, <SegmentFS Seg="1"> and Seg="2" give:

Fare – base fare for that segment for that passenger

Tax1 – taxes for that segment for that passenger

disc – discount amount

HoldPcs – number of checked bags

HoldWt – weight allowance (e.g., 25K)

HandWt – carry‑on weight

c) Total fare store (<FareStore FSID="Total">)
Total – grand total for all passengers, all segments.

d) Detailed taxes (<FareTax><PaxTax>)
Seg, Pax, Code, Amnt, desc

Use only if you need tax breakdown.

2.4 Basket (<Basket><Outstanding>)
amount – total amount due (same as grand total)

3. Extracting Return Leg Price for a Specific Passenger
When you have selected an outbound flight (with known one‑way price per passenger), you can price the round‑trip and then compute the return leg price as:

text
returnLegPrice = roundTripTotal - outboundLegPrice
Where roundTripTotal is from <FareStore FSID="Total"> for the whole PNR, and outboundLegPrice is the known one‑way price for the same passenger/class.

If you need per‑passenger return leg price, use per‑passenger <FareStore>:

text
returnLegPriceForPax = (PaxTotalRoundTrip) - outboundOneWayPriceForPax
Where PaxTotalRoundTrip is the Total attribute of <FareStore FSID="FQC" Pax="n">.

4. Passenger Type Codes
PaxType	Description	Seat Occupying	Discount/Notes
AD	Adult	Yes	Full fare
CH	Child (age 2-11)	Yes	Discounted fare (e.g., 75% of adult)
IN	Infant (<2 years)	No (lap)	Usually 10% of adult fare + taxes
In the XML, infants have HoldPcs="0" and HoldWt="0K" – no baggage allowance.

5. Baggage Allowance Extraction
From <SegmentFS> inside per‑passenger <FareStore>:

HoldPcs – number of checked bags

HoldWt – weight (e.g., 25K for 25 kg)

HandWt – carry‑on weight

Different airlines may have different allowances per class and passenger type.

6. Currency and Exchange Rates
Cur – currency code (e.g., LYD)

CurInf – exchange rate info (e.g., 2,0.001,0.001). First number is decimal places, second and third are rates. Usually you can ignore and use the numeric values directly.

7. Error Handling for Round‑Trip Pricing
If the command fails, Videcom returns plain text error (not XML). Check the response: if it doesn’t start with <?xml, throw an exception with the error message.

If the return leg is not available for the same airline on the selected date, the command may fail or return 0 availability. The parser should handle missing segments gracefully.

8. Implementation Notes for priceRoundTrip()
Building the command
php
protected function buildRoundTripPricingCommand(RoundTripPriceRequest $request): string
{
    $paxString = $this->buildPaxString($request->passengers);
    $outboundSegment = $this->buildSegmentString($request->outboundSegment, $request->seatCount);
    $returnSegment = $this->buildSegmentString($request->returnSegment, $request->seatCount);
    return "I^{$paxString}^{$outboundSegment}^{$returnSegment}^FG^FS1^*r~x";
}
Parsing the response to get return leg price per passenger
php
public function parseRoundTripPriceResponse(string $xmlResponse, float $outboundOneWayTotal): RoundTripPriceResult
{
    $xml = simplexml_load_string($xmlResponse);
    $grandTotal = (float) $xml->FareQuote->xpath("//FareStore[@FSID='Total']")[0]['Total'];
    $returnTotal = $grandTotal - $outboundOneWayTotal;
    
    // Per-passenger breakdown
    $perPax = [];
    foreach ($xml->FareQuote->FareStore as $fareStore) {
        if ($fareStore['FSID'] == 'FQC') {
            $pax = (int) $fareStore['Pax'];
            $paxRoundTotal = (float) $fareStore['Total'];
            $paxOutboundPrice = $outboundOneWayTotal / $request->seatCount; // approximate if not known per pax
            $paxReturnPrice = $paxRoundTotal - $paxOutboundPrice;
            $perPax[$pax] = [
                'total' => $paxRoundTotal,
                'return_leg_price' => $paxReturnPrice,
                'baggage' => [] // extract from SegmentFS if needed
            ];
        }
    }
    
    return new RoundTripPriceResult($returnTotal, $perPax);
}
Caching Key
php
$cacheKey = sprintf(
    'roundtrip_price:%s:%s:%s:%s:%s:%s',
    $tenantId,
    $outboundSegment['airline'],
    $outboundSegment['flightNumber'].$outboundSegment['class'].$outboundSegment['date'],
    $returnSegment['flightNumber'].$returnSegment['class'].$returnSegment['date'],
    $passengerHash
);
TTL = 15 minutes (900 seconds).

9. Reference Diagrams (from previous tasks)
Round‑Trip Pricing Flow
text
User selects outbound flight (with one‑way price)
  ↓
System builds return candidates (simple availability search)
  ↓
For each return candidate with same airline:
  ├→ Build combined command (2 segments, NN seat count)
  ├→ Send to Videcom, parse XML
  ├→ Compute return leg price = grandTotal - known outboundPrice
  └→ Cache result
  ↓
Display return offers with round‑trip discounted prices
Passenger & Baggage Mapping
PaxType	Code in PaxString	Seat Occupying	Baggage Allowance (example)
Adult	A#	Yes	HoldPcs=1, HoldWt=25K
Child	B#.CH{age}	Yes	HoldPcs=1, HoldWt=20K
Infant	C#.IN{months}	No	HoldPcs=0
10. Testing Suggestions
Mock a successful XML response (like the one provided) and test that the parser correctly computes return leg price.

Test with different passenger mixes (2 adults, 1 child, 1 infant).

Test that the command builder uses uppercase month abbreviation (01MAY not 01May).

Test error handling: invalid flight, no availability, wrong class.
