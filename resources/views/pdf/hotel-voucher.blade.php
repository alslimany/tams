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

    $details   = (array) $item->item_details;
    $product   = (array) $item->product_details;
    $hotel     = is_array($product['hotel'] ?? null) ? $product['hotel'] : (is_array($details['provider_booking']['hotel'] ?? null) ? $details['provider_booking']['hotel'] : []);
    $stay      = is_array($product['stay'] ?? null) ? $product['stay'] : [];
    $rooms     = is_array($product['rooms'] ?? null) ? $product['rooms'] : (is_array($details['rooms'] ?? null) ? $details['rooms'] : []);
    $customer  = is_array($product['customer'] ?? null) ? $product['customer'] : (is_array($details['customer'] ?? null) ? $details['customer'] : []);
    $policies  = is_array($details['selected_offer']['cancellation_policies'] ?? null) ? $details['selected_offer']['cancellation_policies'] : [];
    $bookingRef = $value($item->ticket_number, $details['booking_ref'] ?? null, $item->provider_reference);
    $currency   = $value($item->currency, $details['provider_currency'] ?? null);
    $hotelName  = $value($hotel['hotel_name'] ?? null, $hotel['name'] ?? null, $product['hotel']['name'] ?? null);
    $roomName   = $value($product['room']['room_name'] ?? null, $details['selected_offer']['room_name'] ?? null);
    $boardName  = $value($product['room']['board_name'] ?? null, $details['selected_offer']['board_name'] ?? null);
    $checkIn    = $date($stay['from'] ?? $details['provider_booking']['from'] ?? null);
    $checkOut   = $date($stay['to'] ?? $details['provider_booking']['to'] ?? null);
    $deadline   = $date($stay['deadline'] ?? $details['provider_booking']['deadline'] ?? null);
    $nights     = (filled($stay['from'] ?? null) && filled($stay['to'] ?? null))
        ? \Illuminate\Support\Carbon::parse($stay['from'])->diffInDays(\Illuminate\Support\Carbon::parse($stay['to']))
        : '-';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Hotel Voucher {{ $bookingRef }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .page { padding: 18px; }
        .sheet { overflow: hidden; border: 1px solid #dbe3ef; border-radius: 18px; background: #ffffff; }
        .header { display: flex; justify-content: space-between; gap: 20px; padding: 22px; background: linear-gradient(135deg, #0f172a, #065f46); color: #ffffff; }
        .brand { font-size: 12px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; opacity: .78; }
        h1 { margin: 7px 0 0; font-size: 28px; line-height: 1.05; }
        .muted { color: #64748b; }
        .header .muted { color: rgba(255,255,255,.74); }
        .pill { display: inline-block; border-radius: 999px; background: rgba(255,255,255,.14); padding: 6px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .summary { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid #e2e8f0; }
        .summary div { padding: 14px 16px; border-right: 1px solid #e2e8f0; }
        .summary div:last-child { border-right: 0; }
        .label { margin: 0 0 5px; color: #64748b; font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        .value { margin: 0; font-size: 13px; font-weight: 800; }
        .section { padding: 16px; border-bottom: 1px solid #e2e8f0; }
        .section:last-child { border-bottom: 0; }
        .section-title { margin: 0 0 12px; font-size: 14px; font-weight: 900; }
        .card { overflow: hidden; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 14px; }
        .card:last-child { margin-bottom: 0; }
        .card-head { display: flex; justify-content: space-between; gap: 14px; padding: 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .grid { display: grid; grid-template-columns: repeat(4, 1fr); }
        .cell { min-height: 62px; padding: 12px; border-right: 1px solid #e2e8f0; }
        .cell:last-child { border-right: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; color: #64748b; font-size: 9px; letter-spacing: .13em; text-align: left; text-transform: uppercase; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e2e8f0; }
        td { font-weight: 700; }
        tr:last-child td { border-bottom: 0; }
        .badge { display: inline-block; border-radius: 999px; padding: 3px 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; background: #dcfce7; color: #166534; }
        .footer { display: flex; justify-content: space-between; gap: 20px; padding: 14px 16px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <main class="sheet">
            <header class="header">
                <div>
                    <div class="brand">Booknow Hotel Voucher</div>
                    <h1>{{ $hotelName }}</h1>
                    <p class="muted">Order {{ $order->number }} · Ref {{ $bookingRef }}</p>
                </div>
                <div style="text-align: right;">
                    <span class="pill">{{ $item->status ?? 'confirmed' }}</span>
                    <p style="margin: 12px 0 0; font-size: 22px; font-weight: 900;">{{ $money($item->total_amount ?? $item->total ?? 0, $currency) }}</p>
                </div>
            </header>

            <section class="summary">
                <div>
                    <p class="label">Booking Ref</p>
                    <p class="value">{{ $bookingRef }}</p>
                </div>
                <div>
                    <p class="label">Check-in</p>
                    <p class="value">{{ $checkIn }}</p>
                </div>
                <div>
                    <p class="label">Check-out</p>
                    <p class="value">{{ $checkOut }}</p>
                </div>
                <div>
                    <p class="label">Nights</p>
                    <p class="value">{{ $nights }}</p>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Accommodation</h2>
                <div class="card">
                    <div class="card-head">
                        <div>
                            <div style="font-size: 18px; font-weight: 900;">{{ $hotelName }}</div>
                            @if(filled($hotel['city_name'] ?? null) || filled($hotel['country_name'] ?? null))
                                <p class="muted" style="margin: 5px 0 0;">{{ collect([$hotel['city_name'] ?? null, $hotel['country_name'] ?? null])->filter()->implode(', ') }}</p>
                            @endif
                        </div>
                        @if(filled($hotel['rating'] ?? null))
                            <div style="font-size: 13px; font-weight: 900;">{{ $hotel['rating'] }} ★</div>
                        @endif
                    </div>
                    <div class="grid">
                        <div class="cell">
                            <p class="label">Room Type</p>
                            <p class="value">{{ $roomName }}</p>
                        </div>
                        <div class="cell">
                            <p class="label">Board</p>
                            <p class="value">{{ $boardName }}</p>
                        </div>
                        <div class="cell">
                            <p class="label">Cancellation Deadline</p>
                            <p class="value">{{ $deadline }}</p>
                        </div>
                        <div class="cell">
                            <p class="label">Booked by</p>
                            <p class="value">{{ $value($order->owner?->name, data_get($customer, 'name')) }}</p>
                        </div>
                    </div>
                </div>
            </section>

            @if(count($rooms) > 0)
                <section class="section">
                    <h2 class="section-title">Rooms & Guests</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Guests</th>
                                <th>Adults</th>
                                <th>Children</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rooms as $index => $room)
                                @php
                                    $paxes = is_array($room['paxes'] ?? null) ? $room['paxes'] : [];
                                    $adults = collect($paxes)->where('type', 'AD')->count() ?: count($paxes);
                                    $children = collect($paxes)->where('type', 'CH')->count();
                                    $guestNames = collect($paxes)->map(fn($p) => trim(($p['civility'] ?? '').' '.($p['firstName'] ?? $p['first_name'] ?? '').' '.($p['lastName'] ?? $p['last_name'] ?? '')))->filter()->implode(', ');
                                @endphp
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $guestNames ?: '-' }}</td>
                                    <td>{{ $adults }}</td>
                                    <td>{{ $children ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            @if(count($policies) > 0)
                <section class="section">
                    <h2 class="section-title">Cancellation Policy</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>From</th>
                                <th>Amount</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($policies as $policy)
                                <tr>
                                    <td>{{ $date($policy['dateFrom'] ?? $policy['date_from'] ?? null) }}</td>
                                    <td>{{ $value($policy['amount'] ?? null, $policy['percent'] ?? null) }}</td>
                                    <td>{{ $value($policy['type'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            <footer class="footer">
                <span>Generated by Booknow</span>
                <span>{{ now()->format('d M Y H:i') }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
