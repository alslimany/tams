@php
    $value = static fn (...$values) => collect($values)->first(fn ($candidate) => filled($candidate)) ?? '-';
    $money = static fn ($amount, $currency) => number_format((float) $amount, 2).' '.($currency ?: '');
    $date = static function ($raw): string {
        if (! filled($raw)) {
            return '-';
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('d M Y');
        } catch (\Throwable) {
            return (string) $raw;
        }
    };
    $passengerName = static function (array $ticket, array $passengers) use ($value): string {
        $matchedPassenger = collect($passengers)->first(fn ($passenger) => (string) ($passenger['id'] ?? '') === (string) ($ticket['passenger_id'] ?? ''));

        if (is_array($matchedPassenger)) {
            return trim(($matchedPassenger['title'] ?? '').' '.($matchedPassenger['first_name'] ?? '').' '.($matchedPassenger['last_name'] ?? '')) ?: '-';
        }

        return trim(($ticket['title'] ?? '').' '.($ticket['first_name'] ?? '').' '.($ticket['last_name'] ?? '')) ?: '-';
    };

    $pnrCode = $value($item->provider_reference, $pnr['rloc'] ?? null, $pnr['pnr'] ?? null);
    $currency = $value($pnr['currency'] ?? null, $item->currency);
    $total = $pnr['total_price'] ?? $item->total_amount ?? $item->total ?? 0;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Flight Ticket {{ $pnrCode }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .page { padding: 18px; }
        .sheet { overflow: hidden; border: 1px solid #dbe3ef; border-radius: 18px; background: #ffffff; }
        .header { display: flex; justify-content: space-between; gap: 20px; padding: 22px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #ffffff; }
        .brand { font-size: 12px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; opacity: .78; }
        h1 { margin: 7px 0 0; font-size: 28px; line-height: 1.05; }
        .muted { color: #64748b; }
        .header .muted { color: rgba(255, 255, 255, .74); }
        .pill { display: inline-block; border-radius: 999px; background: rgba(255,255,255,.14); padding: 6px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid #e2e8f0; }
        .summary div { padding: 14px 16px; border-right: 1px solid #e2e8f0; }
        .summary div:last-child { border-right: 0; }
        .label { margin: 0 0 5px; color: #64748b; font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        .value { margin: 0; font-size: 13px; font-weight: 800; }
        .section { padding: 16px; border-bottom: 1px solid #e2e8f0; }
        .section:last-child { border-bottom: 0; }
        .section-title { margin: 0 0 12px; font-size: 14px; font-weight: 900; }
        .segment { overflow: hidden; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 14px; }
        .segment:last-child { margin-bottom: 0; }
        .segment-head { display: flex; justify-content: space-between; gap: 14px; padding: 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .route { font-size: 20px; font-weight: 900; letter-spacing: -.02em; }
        .flight { text-align: right; font-size: 13px; font-weight: 900; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); }
        .cell { min-height: 62px; padding: 12px; border-right: 1px solid #e2e8f0; }
        .cell:last-child { border-right: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; color: #64748b; font-size: 9px; letter-spacing: .13em; text-align: left; text-transform: uppercase; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e2e8f0; }
        td { font-weight: 700; }
        tr:last-child td { border-bottom: 0; }
        .footer { display: flex; justify-content: space-between; gap: 20px; padding: 14px 16px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <main class="sheet">
            <header class="header">
                <div>
                    <div class="brand">Booknow Electronic Ticket</div>
                    <h1>Flight Ticket</h1>
                    <p class="muted">Order {{ $order->number }} · PNR {{ $pnrCode }}</p>
                </div>
                <div style="text-align: right;">
                    <span class="pill">{{ $item->status ?? 'pending' }}</span>
                    <p style="margin: 12px 0 0; font-size: 22px; font-weight: 900;">{{ $money($total, $currency) }}</p>
                </div>
            </header>

            <section class="summary">
                <div>
                    <p class="label">PNR</p>
                    <p class="value">{{ $pnrCode }}</p>
                </div>
                <div>
                    <p class="label">Ticket</p>
                    <p class="value">{{ $value($item->ticket_number, data_get($pnr, 'tickets.0.ticket_number')) }}</p>
                </div>
                <div>
                    <p class="label">Booked by</p>
                    <p class="value">{{ $value($pnr['booked_by'] ?? null, data_get($item->item_details, 'customer.name'), $order->owner?->name) }}</p>
                </div>
                <div>
                    <p class="label">Issued</p>
                    <p class="value">{{ $date($order->issued_at ?? $item->created_at) }}</p>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Itinerary</h2>
                @forelse ($itineraries as $itinerary)
                    @php
                        $tickets = (array) ($itinerary['tickets'] ?? []);
                        $airline = $value($itinerary['airline_id'] ?? null, $pnr['iata'] ?? null);
                        $flightNumber = $value($itinerary['flight_number'] ?? null);
                    @endphp
                    <article class="segment">
                        <div class="segment-head">
                            <div>
                                <div class="route">{{ $value($itinerary['from'] ?? null) }} → {{ $value($itinerary['to'] ?? null) }}</div>
                                <p class="muted" style="margin: 5px 0 0;">{{ $date($itinerary['date'] ?? null) }}</p>
                            </div>
                            <div class="flight">
                                {{ $airline }} {{ $flightNumber }}
                                <p class="muted" style="margin: 5px 0 0;">{{ $value($itinerary['status'] ?? null) }}</p>
                            </div>
                        </div>
                        <div class="grid">
                            <div class="cell">
                                <p class="label">Departure</p>
                                <p class="value">{{ $value($itinerary['from'] ?? null) }} · {{ $value($itinerary['departure'] ?? null) }}</p>
                            </div>
                            <div class="cell">
                                <p class="label">Arrival</p>
                                <p class="value">{{ $value($itinerary['to'] ?? null) }} · {{ $value($itinerary['arrival'] ?? null) }}</p>
                            </div>
                            <div class="cell">
                                <p class="label">Class</p>
                                <p class="value">{{ $value($itinerary['class_band_display_name'] ?? null, $itinerary['class_band'] ?? null, $itinerary['class'] ?? null) }}</p>
                            </div>
                            <div class="cell">
                                <p class="label">Cabin</p>
                                <p class="value">{{ $value($itinerary['cabin'] ?? null, $itinerary['class'] ?? null) }}</p>
                            </div>
                        </div>

                        <table>
                            <thead>
                                <tr>
                                    <th>Coupon</th>
                                    <th>Ticket No.</th>
                                    <th>Passenger</th>
                                    <th>Status</th>
                                    <th>Baggage</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tickets as $index => $ticket)
                                    <tr>
                                        <td>{{ $value($ticket['coupon'] ?? null, $ticket['coupon_number'] ?? null, $ticket['segment_number'] ?? null, $index + 1) }}</td>
                                        <td>{{ $value($ticket['ticket_number'] ?? null, $item->ticket_number) }}</td>
                                        <td>{{ $passengerName((array) $ticket, $passengers) }}</td>
                                        <td>{{ $value($ticket['status'] ?? null) }}</td>
                                        <td>{{ $value($ticket['hold_weight'] ?? null, $ticket['hold_wt'] ?? null, $ticket['hold_pcs'] ?? null) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="muted">No passenger coupon attached yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </article>
                @empty
                    <p class="muted">No itinerary details were found for this ticket.</p>
                @endforelse
            </section>

            <section class="section">
                <h2 class="section-title">Passengers & contacts</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Passengers</th>
                            <th>Contacts</th>
                            <th>Payment Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                @forelse ($passengers as $passenger)
                                    <div>{{ trim(($passenger['title'] ?? '').' '.($passenger['first_name'] ?? '').' '.($passenger['last_name'] ?? '')) ?: '-' }}</div>
                                @empty
                                    -
                                @endforelse
                            </td>
                            <td>
                                @forelse ($contacts as $contact)
                                    <div>{{ $value($contact['value'] ?? null) }}</div>
                                @empty
                                    -
                                @endforelse
                            </td>
                            <td>{{ $value(data_get($pnr, 'payments.0.reference'), $order->payment_reference) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <footer class="footer">
                <span>Generated by Booknow</span>
                <span>{{ now()->format('d M Y H:i') }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
