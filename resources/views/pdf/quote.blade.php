<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #241a15; }
    .head { background: #241a15; padding: 14px 18px; }
    .head img { height: 46px; }
    h1 { font-size: 18px; margin: 18px 0 2px; }
    .muted { color: #6b5b4e; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th, td { padding: 7px 6px; border-bottom: 1px solid #e3d9cb; text-align: left; }
    td.r, th.r { text-align: right; }
    .total td { font-weight: bold; font-size: 14px; border-top: 2px solid #241a15; border-bottom: 0; }
    .note { margin-top: 16px; padding: 10px; background: #f8f4ec; font-size: 11px; }
</style>
</head>
<body>
    <div class="head"><img src="{{ $logo }}" alt="UNIQA SUITES"></div>
    <h1>Quotation {{ $quote->reference }}</h1>
    <div class="muted">Version {{ $quote->version }} · Issued {{ $quote->created_at->timezone(config('uniqa.display_timezone'))->format('M j, Y g:i A') }}</div>

    <p>
        @if($quote->guest_name)<strong>Guest:</strong> {{ $quote->guest_name }}<br>@endif
        <strong>Suite:</strong> {{ $quote->room->name }}, {{ $quote->room->property->location_label }}<br>
        <strong>Stay:</strong> {{ $quote->check_in->format('M j, Y') }} to {{ $quote->check_out->format('M j, Y') }}<br>
        <strong>Guests:</strong> {{ $quote->guest_count }}
    </p>

    <table>
        <thead><tr><th>Item</th><th class="r">Amount (PHP)</th></tr></thead>
        <tbody>
        @foreach($quote->items as $item)
            <tr><td>{{ $item->description }}</td><td class="r">{{ number_format($item->amount_cents / 100, 2) }}</td></tr>
        @endforeach
            <tr class="total"><td>Total</td><td class="r">{{ number_format($quote->total_cents / 100, 2) }}</td></tr>
        </tbody>
    </table>

    <div class="note">
        This quotation does not reserve the suite. A booking is valid only once confirmed by UNIQA SUITES staff.
        @foreach(($quote->warnings ?? []) as $warning)<br>{{ $warning }}@endforeach
    </div>
</body>
</html>
