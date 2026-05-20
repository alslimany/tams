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

    $details    = (array) $item->item_details;
    $product    = (array) $item->product_details;
    $insurance  = is_array($details['insurance'] ?? null) ? $details['insurance'] : [];
    $passenger  = is_array($insurance['passenger'] ?? null) ? $insurance['passenger'] : (is_array($product['passenger'] ?? null) ? $product['passenger'] : []);
    $beneficiary = is_array($details['beneficiary'] ?? null) ? $details['beneficiary'] : (is_array($product['beneficiary'] ?? null) ? $product['beneficiary'] : []);
    $currency   = $value($item->currency);
    $policyNumber = $value($item->ticket_number, $insurance['policy_number'] ?? null);
    $reportRef  = $value($insurance['report_reference'] ?? null);
    $dateFrom   = $date($insurance['policy_date_from'] ?? $product['policy_date_from'] ?? null);
    $dateTo     = $date($insurance['policy_date_to'] ?? $product['policy_date_to'] ?? null);
    $providerName = $value($product['provider'] ?? null, $details['provider'] ?? null, 'Al Baraka Insurance');
    $subtype    = ucfirst((string) ($product['product_subtype'] ?? $item->product_subtype ?? 'insurance'));
    $passengerName = $value(
        trim(($passenger['first_name'] ?? '').' '.($passenger['last_name'] ?? '')),
        $details['passenger_name'] ?? null,
        $product['passenger_name'] ?? null,
    );
    $nationalId = $value($beneficiary['national_id'] ?? null, $passenger['national_id'] ?? null);
    $phone      = $value($beneficiary['phone'] ?? null, $passenger['phone'] ?? null);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Insurance Policy {{ $policyNumber }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f8fafc; color: #0f172a; font-family: Arial, Helvetica, sans-serif; font-size: 12px; }
        .page { padding: 18px; }
        .sheet { overflow: hidden; border: 1px solid #dbe3ef; border-radius: 18px; background: #ffffff; }
        .header { display: flex; justify-content: space-between; gap: 20px; padding: 22px; background: linear-gradient(135deg, #0f172a, #7c3aed); color: #ffffff; }
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
        .card { overflow: hidden; border: 1px solid #e2e8f0; border-radius: 14px; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, 1fr); }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); }
        .cell { min-height: 62px; padding: 12px; border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .cell:last-child { border-right: 0; }
        .cell-last-row { border-bottom: 0; }
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
                    <div class="brand">Booknow Insurance Policy</div>
                    <h1>{{ $subtype }} Insurance</h1>
                    <p class="muted">Order {{ $order->number }} · Policy {{ $policyNumber }}</p>
                </div>
                <div style="text-align: right;">
                    <span class="pill">{{ $item->status ?? 'issued' }}</span>
                    <p style="margin: 12px 0 0; font-size: 22px; font-weight: 900;">{{ $money($item->total_amount ?? $item->total ?? 0, $currency) }}</p>
                </div>
            </header>

            <section class="summary">
                <div>
                    <p class="label">Policy No.</p>
                    <p class="value">{{ $policyNumber }}</p>
                </div>
                <div>
                    <p class="label">Report Ref</p>
                    <p class="value">{{ $reportRef }}</p>
                </div>
                <div>
                    <p class="label">Coverage From</p>
                    <p class="value">{{ $dateFrom }}</p>
                </div>
                <div>
                    <p class="label">Coverage To</p>
                    <p class="value">{{ $dateTo }}</p>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Insured Details</h2>
                <div class="card">
                    <div class="grid-3">
                        <div class="cell cell-last-row">
                            <p class="label">Full Name</p>
                            <p class="value">{{ $passengerName }}</p>
                        </div>
                        <div class="cell cell-last-row">
                            <p class="label">National ID</p>
                            <p class="value">{{ $nationalId }}</p>
                        </div>
                        <div class="cell cell-last-row" style="border-right: 0;">
                            <p class="label">Phone</p>
                            <p class="value">{{ $phone }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="section">
                <h2 class="section-title">Policy Details</h2>
                <table>
                    <tbody>
                        <tr>
                            <th style="width: 35%;">Provider</th>
                            <td>{{ $providerName }}</td>
                        </tr>
                        <tr>
                            <th>Type</th>
                            <td>{{ $subtype }}</td>
                        </tr>
                        @if(filled($insurance['zone_id'] ?? null))
                            <tr>
                                <th>Zone</th>
                                <td>{{ $insurance['zone_id'] }}</td>
                            </tr>
                        @endif
                        @if(filled($insurance['duration_id'] ?? null))
                            <tr>
                                <th>Duration</th>
                                <td>{{ $insurance['duration_id'] }}</td>
                            </tr>
                        @endif
                        <tr>
                            <th>Issued</th>
                            <td>{{ $date($order->issued_at ?? $item->created_at) }}</td>
                        </tr>
                        <tr>
                            <th>Issued by</th>
                            <td>{{ $value($order->owner?->name) }}</td>
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
