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
    $typeLabel = static function (string $type): string {
        return match ($type) {
            'flight'    => 'Flight Ticket',
            'hotel'     => 'Hotel Voucher',
            'insurance' => 'Insurance Policy',
            default     => ucfirst($type),
        };
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Order {{ $order->number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .page { padding: 18px; }
        .sheet { overflow: hidden; border: 1px solid #dbe3ef; border-radius: 18px; background: #ffffff; }
        .header { display: flex; justify-content: space-between; gap: 20px; padding: 22px; background: linear-gradient(135deg, #0f172a, #1e3a8a); color: #ffffff; }
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
        .item-card { overflow: hidden; margin-bottom: 14px; border: 1px solid #e2e8f0; border-radius: 14px; }
        .item-card:last-child { margin-bottom: 0; }
        .item-head { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .item-type { font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #64748b; }
        .item-ref { font-size: 13px; font-weight: 900; }
        .item-status { border-radius: 999px; padding: 3px 10px; font-size: 10px; font-weight: 800; text-transform: uppercase; background: #dbeafe; color: #1e40af; }
        .item-body { display: grid; grid-template-columns: repeat(4, 1fr); }
        .cell { padding: 12px; border-right: 1px solid #e2e8f0; }
        .cell:last-child { border-right: 0; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; color: #64748b; font-size: 9px; letter-spacing: .13em; text-align: left; text-transform: uppercase; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e2e8f0; }
        td { font-weight: 700; }
        tr:last-child td { border-bottom: 0; }
        .totals { padding: 16px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .totals table { width: 320px; margin-left: auto; }
        .totals td { padding: 6px 10px; }
        .totals .grand { font-size: 15px; font-weight: 900; border-top: 2px solid #0f172a; }
        .footer { display: flex; justify-content: space-between; gap: 20px; padding: 14px 16px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="page">
        <main class="sheet">
            <header class="header">
                <div>
                    <div class="brand">Booknow Order Summary</div>
                    <h1>{{ $order->number }}</h1>
                    <p class="muted">Issued {{ $date($order->issued_at ?? $order->created_at) }} · {{ $order->owner?->name }}</p>
                </div>
                <div style="text-align: right;">
                    <span class="pill">{{ $order->status }}</span>
                    <p style="margin: 12px 0 0; font-size: 22px; font-weight: 900;">{{ $money($order->grand_total, $order->currency) }}</p>
                </div>
            </header>

            <section class="summary">
                <div>
                    <p class="label">Order No.</p>
                    <p class="value">{{ $order->number }}</p>
                </div>
                <div>
                    <p class="label">Contact</p>
                    <p class="value">{{ $value(data_get($order->contact, 'first_name').' '.data_get($order->contact, 'last_name')) }}</p>
                </div>
                <div>
                    <p class="label">Payment</p>
                    <p class="value">{{ $value($order->payment_method) }}</p>
                </div>
                <div>
                    <p class="label">Items</p>
                    <p class="value">{{ $items->count() }}</p>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Order Items</h2>

                @forelse($items as $item)
                    @php
                        $product = (array) $item->product_details;
                        $details = (array) $item->item_details;
                        $type    = (string) ($item->product_type ?: $item->type);

                        // Build a human-readable description per type
                        $description = match($type) {
                            'flight' => collect([
                                data_get($details, 'itineraries.0.from'),
                                '→',
                                data_get($details, 'itineraries.0.to'),
                            ])->filter()->implode(' ') ?: data_get($details, 'segments.0.from').' → '.data_get($details, 'segments.0.to'),
                            'hotel' => $value(
                                $product['hotel']['hotel_name'] ?? null,
                                $product['hotel']['name'] ?? null,
                                $details['provider_booking']['hotel']['hotelName'] ?? null,
                            ),
                            'insurance' => $value(
                                $details['passenger_name'] ?? null,
                                $product['passenger_name'] ?? null,
                            ),
                            default => $value($item->provider_reference),
                        };
                    @endphp
                    <div class="item-card">
                        <div class="item-head">
                            <div>
                                <div class="item-type">{{ $typeLabel($type) }}</div>
                                <div class="item-ref">{{ $description ?: $value($item->provider_reference) }}</div>
                            </div>
                            <span class="item-status">{{ $item->status }}</span>
                        </div>
                        <div class="item-body">
                            <div class="cell">
                                <p class="label">Reference</p>
                                <p class="value">{{ $value($item->ticket_number, $item->provider_reference) }}</p>
                            </div>
                            <div class="cell">
                                <p class="label">Provider</p>
                                <p class="value">{{ $value($item->provider) }}</p>
                            </div>
                            <div class="cell">
                                <p class="label">Net Fare</p>
                                <p class="value">{{ $money($item->net_fare ?? 0, $item->currency) }}</p>
                            </div>
                            <div class="cell">
                                <p class="label">Total</p>
                                <p class="value">{{ $money($item->total_amount ?? $item->total ?? 0, $item->currency) }}</p>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="muted">No items found for this order.</p>
                @endforelse
            </section>

            <div class="totals">
                <table>
                    <tbody>
                        <tr>
                            <td class="muted">Subtotal</td>
                            <td style="text-align: right;">{{ $money($order->subtotal ?? $order->grand_total, $order->currency) }}</td>
                        </tr>
                        <tr>
                            <td class="muted">Amount Paid</td>
                            <td style="text-align: right;">{{ $money($order->amount_paid ?? 0, $order->currency) }}</td>
                        </tr>
                        @if(($order->amount_refunded ?? 0) > 0)
                            <tr>
                                <td class="muted">Refunded</td>
                                <td style="text-align: right;">{{ $money($order->amount_refunded, $order->currency) }}</td>
                            </tr>
                        @endif
                        <tr class="grand">
                            <td><strong>Grand Total</strong></td>
                            <td style="text-align: right;"><strong>{{ $money($order->grand_total, $order->currency) }}</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="footer">
                <span>Generated by Booknow</span>
                <span>{{ now()->format('d M Y H:i') }}</span>
            </footer>
        </main>
    </div>
</body>
</html>
