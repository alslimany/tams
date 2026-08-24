@php
    $value = static fn (...$values) => collect($values)->first(fn ($candidate) => filled($candidate)) ?? '-';
    $money = static function ($amount, $currency): string {
        if (! filled($amount)) {
            return '-';
        }

        return number_format((float) $amount, 2).' '.($currency ?: '');
    };
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
    $stars = static function ($rating): string {
        if (! filled($rating) || ! is_numeric($rating)) {
            return '';
        }

        $count = max(0, min(5, (int) $rating));

        return str_repeat('★', $count).str_repeat('☆', 5 - $count);
    };
    $guestName = static function (array $pax): string {
        return trim(
            ($pax['civility'] ?? $pax['title'] ?? '').' '.
            ($pax['firstName'] ?? $pax['first_name'] ?? '').' '.
            ($pax['lastName'] ?? $pax['last_name'] ?? '')
        );
    };

    $details    = (array) $item->item_details;
    $product    = (array) $item->product_details;
    $hotel      = (array) ($product['hotel'] ?? $details['provider_booking']['hotel'] ?? []);
    $stay       = (array) ($product['stay'] ?? $details['provider_booking'] ?? []);
    $bookedRooms = (array) ($product['rooms'] ?? $details['provider_booking']['rooms'] ?? $details['rooms'] ?? []);
    $submittedRooms = (array) ($details['rooms'] ?? []);
    $customer   = (array) ($product['customer'] ?? $details['customer'] ?? $order->contact ?? []);
    $policies   = (array) ($details['selected_offer']['cancellation_policies'] ?? []);
    $offer      = (array) ($details['selected_offer'] ?? $product['room'] ?? []);
    $comments   = trim((string) strip_tags((string) ($product['comments'] ?? $details['comments'] ?? '')));

    $agency         = (array) ($agency ?? []);
    $providerBadge  = (array) ($providerBadge ?? []);

    $bookingRef = $value($item->ticket_number, $details['booking_ref'] ?? null, $item->provider_reference);
    $bookingId  = $value($details['booking_id'] ?? null, $item->provider_reference);
    $currency   = $value($item->currency, $details['provider_currency'] ?? null, $order->currency);

    $hotelName  = $value($hotel['hotel_name'] ?? null, $hotel['name'] ?? null);
    $hotelCity  = $hotel['city_name'] ?? null;
    $hotelCountry = $hotel['country_name'] ?? null;
    $hotelRating = $hotel['rating'] ?? null;
    $hotelLocation = collect([$hotelCity, $hotelCountry])->filter()->implode(', ');

    $roomName   = $value($offer['room_name'] ?? null, $offer['name'] ?? null);
    $boardName  = $value($offer['board_name'] ?? null);

    $checkInRaw  = $stay['from'] ?? $details['provider_booking']['from'] ?? null;
    $checkOutRaw = $stay['to'] ?? $details['provider_booking']['to'] ?? null;
    $checkIn     = $date($checkInRaw);
    $checkOut    = $date($checkOutRaw);
    $deadline    = $date($stay['deadline'] ?? $details['provider_booking']['deadline'] ?? null);

    $nights = '-';
    if (filled($checkInRaw) && filled($checkOutRaw)) {
        try {
            $nights = \Illuminate\Support\Carbon::parse($checkInRaw)
                ->diffInDays(\Illuminate\Support\Carbon::parse($checkOutRaw));
        } catch (\Throwable) {
            $nights = '-';
        }
    }

    $totalGuests = collect($bookedRooms)->sum(fn ($room) => count((array) ($room['paxes'] ?? [])));
    $totalGuests = $totalGuests > 0 ? $totalGuests : collect($submittedRooms)->sum(fn ($room) => count((array) ($room['paxes'] ?? [])));

    $totalAmount = (float) ($item->total_amount ?? $item->total ?? 0);
    $netFare     = (float) ($item->net_fare ?? $details['total_purchase'] ?? 0);
    $commission  = (float) ($item->commission_amount ?? $details['markup_amount'] ?? 0);

    $providerName    = $value($product['provider'] ?? null, $item->provider);
    $providerBadgeText = $providerBadge['name'] ?? $providerName;

    $agencyName    = $value($agency['company_name'] ?? null, config('app.name'));
    $agencyEmail   = $agency['email'] ?? null;
    $agencyPhone   = $agency['phone'] ?? null;
    $agencyAddress = $agency['address'] ?? null;

    $customerName  = $value(
        trim(($customer['name'] ?? '')),
        trim(($customer['first_name'] ?? '').' '.($customer['last_name'] ?? '')),
        $order->owner?->name,
    );
    $customerEmail = $value($customer['email'] ?? null, $order->owner?->email);
    $customerPhone = $value($customer['phone'] ?? null);

    $isConfirmed = (bool) ($details['confirmed'] ?? false);
    $status      = (string) ($item->status ?: ($isConfirmed ? 'confirmed' : 'pending'));
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
        .brand { font-size: 11px; font-weight: 700; letter-spacing: 0.18em; text-transform: uppercase; opacity: .78; }
        .header h1 { margin: 6px 0 0; font-size: 26px; line-height: 1.05; }
        .header .muted { color: rgba(255,255,255,.74); margin: 5px 0 0; font-size: 11px; }
        .pill { display: inline-block; border-radius: 999px; background: rgba(255,255,255,.14); padding: 6px 10px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .total { margin: 12px 0 0; font-size: 22px; font-weight: 900; }

        .muted { color: #64748b; }
        .label { margin: 0 0 5px; color: #64748b; font-size: 9px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
        .value { margin: 0; font-size: 13px; font-weight: 800; word-wrap: break-word; }

        .summary { display: grid; grid-template-columns: repeat(4, 1fr); border-bottom: 1px solid #e2e8f0; }
        .summary > div { padding: 14px 16px; border-right: 1px solid #e2e8f0; }
        .summary > div:last-child { border-right: 0; }

        .section { padding: 16px; border-bottom: 1px solid #e2e8f0; }
        .section:last-child { border-bottom: 0; }
        .section-title { margin: 0 0 12px; font-size: 14px; font-weight: 900; }

        .card { overflow: hidden; margin-bottom: 12px; border: 1px solid #e2e8f0; border-radius: 14px; }
        .card:last-child { margin-bottom: 0; }
        .card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; padding: 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .hotel-name { font-size: 18px; font-weight: 900; letter-spacing: -.01em; }
        .stars { font-size: 13px; font-weight: 900; color: #b45309; letter-spacing: 1px; }

        .grid-4 { display: grid; grid-template-columns: repeat(4, 1fr); }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); }
        .cell { min-height: 62px; padding: 12px; border-right: 1px solid #e2e8f0; }
        .cell:last-child { border-right: 0; }
        .cell.stacked { border-right: 0; border-bottom: 1px solid #e2e8f0; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; color: #64748b; font-size: 9px; letter-spacing: .13em; text-align: left; text-transform: uppercase; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        td { font-weight: 700; }
        tr:last-child td { border-bottom: 0; }

        .badge { display: inline-block; border-radius: 999px; padding: 3px 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .badge-confirmed { background: #dcfce7; color: #166534; }
        .badge-pending { background: #fef9c3; color: #854d0e; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }

        .notes { padding: 12px 14px; border: 1px solid #bae6fd; background: #f0f9ff; border-radius: 12px; color: #075985; font-size: 11px; line-height: 1.55; }

        .totals { width: 100%; }
        .totals td { padding: 8px 10px; font-weight: 700; }
        .totals tr td:first-child { color: #64748b; font-weight: 800; text-transform: uppercase; font-size: 10px; letter-spacing: .12em; }
        .totals tr td:last-child { text-align: right; }
        .totals tr.grand-total td { border-top: 2px solid #0f172a; font-size: 14px; font-weight: 900; color: #0f172a; padding-top: 12px; }

        .footer { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 14px 16px; color: #64748b; font-size: 10px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .footer strong { color: #0f172a; }
    </style>
</head>
<body>
    <div class="page">
        <main class="sheet">
            <header class="header">
                <div>
                    <div class="brand">{{ $agencyName }} · Hotel Reservation Voucher</div>
                    <h1>{{ $hotelName }}</h1>
                    <p class="muted">Order {{ $order->number }} · Booking Ref {{ $bookingRef }}</p>
                </div>
                <div style="text-align: right;">
                    <span class="pill">{{ strtoupper($status) }}</span>
                    <p class="total">{{ $money($totalAmount, $currency) }}</p>
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
                    <p class="label">Nights · Guests</p>
                    <p class="value">{{ $nights }} · {{ $totalGuests ?: '-' }}</p>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Property</h2>
                <article class="card">
                    <div class="card-head">
                        <div>
                            <div class="hotel-name">{{ $hotelName }}</div>
                            @if (filled($hotelLocation))
                                <p class="muted" style="margin: 5px 0 0;">{{ $hotelLocation }}</p>
                            @endif
                            @if (filled($providerName))
                                <p class="muted" style="margin: 3px 0 0; font-size: 10px;">Provided by <strong>{{ $providerName }}</strong>@if($providerBadgeText && $providerBadgeText !== $providerName) · {{ $providerBadgeText }}@endif</p>
                            @endif
                        </div>
                        <div style="text-align: right;">
                            @if (filled($hotelRating))
                                <div class="stars">{{ $stars($hotelRating) }}</div>
                                <p class="muted" style="margin: 3px 0 0;">{{ (int) $hotelRating }} / 5</p>
                            @endif
                            @if ($isConfirmed)
                                <span class="badge badge-confirmed" style="margin-top: 8px;">Confirmed</span>
                            @endif
                        </div>
                    </div>
                    <div class="grid-4">
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
                            <p class="value">{{ $value($order->owner?->name, $customerName) }}</p>
                        </div>
                    </div>
                </article>
            </section>

            @if (count($bookedRooms) > 0)
                <section class="section">
                    <h2 class="section-title">Rooms &amp; Guests</h2>
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Room</th>
                                <th>Board</th>
                                <th>Adults</th>
                                <th>Children</th>
                                <th>Guests</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bookedRooms as $index => $room)
                                @php
                                    $paxes = (array) ($room['paxes'] ?? []);
                                    if (empty($paxes)) {
                                        $paxes = (array) ($submittedRooms[$index]['paxes'] ?? []);
                                    }

                                    $adults = collect($paxes)->filter(fn ($p) => strtoupper((string) ($p['type'] ?? '')) === 'AD')->count();
                                    $children = collect($paxes)->filter(fn ($p) => strtoupper((string) ($p['type'] ?? '')) === 'CH')->count();
                                    if ($adults === 0 && $children === 0 && count($paxes) > 0) {
                                        $adults = count($paxes);
                                    }
                                    $guests = collect($paxes)->map($guestName)->filter()->implode(', ');
                                    $roomTitle = $value($room['name'] ?? null, $room['room_name'] ?? null, $roomName);
                                    $roomBoard = $value($room['boardName'] ?? null, $room['board_name'] ?? null, $boardName);
                                @endphp
                                <tr>
                                    <td>{{ $room['roomIndex'] ?? ($index + 1) }}</td>
                                    <td>{{ $roomTitle }}</td>
                                    <td>{{ $roomBoard }}</td>
                                    <td>{{ $adults ?: '-' }}</td>
                                    <td>{{ $children ?: '-' }}</td>
                                    <td>{{ $guests ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            <section class="section">
                <h2 class="section-title">Payment &amp; charges</h2>
                <div class="grid-2">
                    <div style="border-right: 1px solid #e2e8f0;">
                        <table class="totals">
                            <tbody>
                                <tr>
                                    <td>Room charges</td>
                                    <td>{{ $money($netFare > 0 ? $netFare : $totalAmount - $commission, $currency) }}</td>
                                </tr>
                                @if ($commission > 0)
                                    <tr>
                                        <td>Service fee</td>
                                        <td>{{ $money($commission, $currency) }}</td>
                                    </tr>
                                @endif
                                <tr class="grand-total">
                                    <td>Total</td>
                                    <td>{{ $money($totalAmount, $currency) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div>
                        <table class="totals">
                            <tbody>
                                <tr>
                                    <td>Payment method</td>
                                    <td>{{ $value($order->payment_method) }}</td>
                                </tr>
                                <tr>
                                    <td>Payment reference</td>
                                    <td>{{ $value($order->payment_reference) }}</td>
                                </tr>
                                <tr>
                                    <td>Amount paid</td>
                                    <td>{{ $money($order->amount_paid, $currency) }}</td>
                                </tr>
                                <tr>
                                    <td>Issued</td>
                                    <td>{{ $date($order->issued_at ?? $item->created_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            @if (count($policies) > 0)
                <section class="section">
                    <h2 class="section-title">Cancellation policy</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>Applies from</th>
                                <th>Amount</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($policies as $policy)
                                @php
                                    $amountLabel = filled($policy['amount'] ?? null)
                                        ? $money($policy['amount'], $currency)
                                        : (filled($policy['percent'] ?? null) ? ($policy['percent'].'%') : '-');
                                @endphp
                                <tr>
                                    <td>{{ $date($policy['dateFrom'] ?? $policy['date_from'] ?? $policy['from'] ?? null) }}</td>
                                    <td>{{ $amountLabel }}</td>
                                    <td>{{ $value($policy['type'] ?? null, $policy['policyType'] ?? null) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @endif

            <section class="section">
                <h2 class="section-title">Contacts</h2>
                <div class="grid-2">
                    <div style="padding-right: 16px; border-right: 1px solid #e2e8f0;">
                        <p class="label">Guest / customer</p>
                        <p class="value" style="margin-bottom: 6px;">{{ $customerName }}</p>
                        <p class="muted" style="margin: 0;">{{ $customerPhone }}{{ $customerPhone !== '-' && $customerEmail !== '-' ? ' · ' : '' }}{{ $customerEmail }}</p>
                    </div>
                    <div style="padding-left: 16px;">
                        <p class="label">Agency</p>
                        <p class="value" style="margin-bottom: 6px;">{{ $agencyName }}</p>
                        @if (filled($agencyPhone) || filled($agencyEmail))
                            <p class="muted" style="margin: 0;">{{ $agencyPhone }}{{ $agencyPhone && $agencyEmail ? ' · ' : '' }}{{ $agencyEmail }}</p>
                        @endif
                        @if (filled($agencyAddress))
                            <p class="muted" style="margin: 3px 0 0;">{{ $agencyAddress }}</p>
                        @endif
                    </div>
                </div>
            </section>

            @if ($comments !== '')
                <section class="section">
                    <h2 class="section-title">Notes &amp; remarks</h2>
                    <p class="notes">{{ $comments }}</p>
                </section>
            @endif

            <footer class="footer">
                <span>Voucher generated by <strong>{{ $agencyName }}</strong> · Present at check-in.</span>
                <span>{{ now()->format('d M Y H:i') }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
