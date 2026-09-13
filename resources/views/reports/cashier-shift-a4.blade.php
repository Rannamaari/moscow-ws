<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EOD Report {{ $cashierShift->shift_number }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { color: #17212b; font: 12px/1.45 Arial, sans-serif; margin: 0; }
        .report { margin: auto; max-width: 182mm; }
        .head { border-bottom: 2px solid #17212b; display: flex; gap: 24px; justify-content: space-between; padding-bottom: 14px; }
        .title { font-size: 23px; font-weight: 800; letter-spacing: .08em; margin: 0 0 8px; }
        .muted { color: #52616b; }
        .right { text-align: right; }
        .grid { display: grid; gap: 12px; grid-template-columns: repeat(3, 1fr); margin: 20px 0; }
        .card { background: #f3f6f7; border: 1px solid #c9d3d8; border-radius: 8px; padding: 12px; }
        .label { color: #52616b; font-size: 10px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .amount { font-size: 18px; font-weight: 800; margin-top: 4px; }
        table { border-collapse: collapse; margin-top: 12px; width: 100%; }
        th, td { border: 1px solid #b9c5cc; padding: 8px; text-align: left; }
        th { background: #edf2f5; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .total { border-top: 2px solid #17212b; font-weight: 800; }
        .note { background: #fff8e8; border-left: 4px solid #d9901a; margin-top: 18px; padding: 10px 12px; }
        .footer { border-top: 1px solid #b9c5cc; color: #52616b; margin-top: 30px; padding-top: 12px; text-align: center; }
        .print-controls { position: fixed; right: 12px; top: 12px; }
        .print-controls button { background: #17212b; border: 0; border-radius: 8px; color: #fff; cursor: pointer; font-weight: 700; padding: 10px 14px; }
        @media print { .print-controls { display: none; } }
    </style>
</head>
<body>
@php($report = $cashierShift->report_snapshot ?? [])
@php($currency = $cashierShift->currency)
<div class="print-controls"><button type="button" onclick="window.print()">Print A4</button></div>
<main class="report">
    <header class="head">
        <div>
            <h1 class="title">END OF DAY REPORT</h1>
            <strong>{{ $cashierShift->company?->name }}</strong><br>
            <span class="muted">{{ $cashierShift->branch?->name }} / {{ $cashierShift->warehouse?->name }}</span>
        </div>
        <div class="right">
            <strong>{{ $cashierShift->shift_number }}</strong><br>
            <span class="muted">Cashier: {{ $cashierShift->cashier?->name }}</span><br>
            <span class="muted">Opened: {{ $cashierShift->opened_at?->timezone(config('app.business_timezone'))->format('d M Y, h:i A') }} MVT</span><br>
            <span class="muted">Closed: {{ $cashierShift->closed_at?->timezone(config('app.business_timezone'))->format('d M Y, h:i A') }} MVT</span>
        </div>
    </header>

    <section class="grid">
        <div class="card"><div class="label">Opening Cash</div><div class="amount">{{ $currency }} {{ number_format((float) $cashierShift->opening_cash, 2) }}</div></div>
        <div class="card"><div class="label">Expected Cash</div><div class="amount">{{ $currency }} {{ number_format((float) $cashierShift->expected_cash, 2) }}</div></div>
        <div class="card"><div class="label">Cash Counted</div><div class="amount">{{ $currency }} {{ number_format((float) $cashierShift->closing_cash, 2) }}</div></div>
    </section>

    <table>
        <thead><tr><th>Sales Summary</th><th class="num">Amount</th></tr></thead>
        <tbody>
            <tr><td>Completed sales ({{ $report['sales_count'] ?? 0 }})</td><td class="num">{{ $currency }} {{ number_format((float) ($report['grand_total'] ?? 0), 2) }}</td></tr>
            <tr><td>Subtotal</td><td class="num">{{ $currency }} {{ number_format((float) ($report['subtotal'] ?? 0), 2) }}</td></tr>
            <tr><td>Discounts</td><td class="num">{{ $currency }} {{ number_format((float) ($report['discount_total'] ?? 0), 2) }}</td></tr>
            <tr><td>Tax</td><td class="num">{{ $currency }} {{ number_format((float) ($report['tax_total'] ?? 0), 2) }}</td></tr>
            <tr><td>Credit payments collected ({{ $report['credit_collections_count'] ?? 0 }})</td><td class="num">{{ $currency }} {{ number_format((float) ($report['credit_collections_total'] ?? 0), 2) }}</td></tr>
            <tr class="total"><td>Outstanding credit</td><td class="num">{{ $currency }} {{ number_format((float) ($report['balance_due'] ?? 0), 2) }}</td></tr>
        </tbody>
    </table>

    <table>
        <thead><tr><th>Payment Method</th><th class="num">Applied</th><th class="num">Tendered</th><th class="num">Change</th></tr></thead>
        <tbody>
            @forelse ($report['payments'] ?? [] as $payment)
                <tr><td>{{ ucfirst(str_replace('_', ' ', $payment['method'])) }}</td><td class="num">{{ $currency }} {{ number_format((float) $payment['amount'], 2) }}</td><td class="num">{{ $currency }} {{ number_format((float) $payment['tendered'], 2) }}</td><td class="num">{{ $currency }} {{ number_format((float) $payment['change_due'], 2) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No payments were recorded in this shift.</td></tr>
            @endforelse
            <tr class="total"><td>Cash variance</td><td colspan="3" class="num">{{ $currency }} {{ number_format((float) $cashierShift->cash_variance, 2) }}</td></tr>
        </tbody>
    </table>

    @if (($report['returns_count'] ?? 0) > 0)
        <div class="note"><strong>Returns recorded:</strong> {{ $report['returns_count'] }} totaling {{ $currency }} {{ number_format((float) $report['returns_total'], 2) }}.<br>{{ $report['refund_note'] }}</div>
    @endif
    @if ($cashierShift->opening_notes || $cashierShift->closing_notes)
        <div class="note"><strong>Shift notes</strong><br>@if ($cashierShift->opening_notes) Opening: {{ $cashierShift->opening_notes }}<br>@endif @if ($cashierShift->closing_notes) Closing: {{ $cashierShift->closing_notes }}@endif</div>
    @endif
    <footer class="footer">Generated from {{ config('app.name', 'Moscow Traders Wholesale') }} cashier shift records.</footer>
</main>
</body>
</html>
