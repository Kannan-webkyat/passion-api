<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Refund register</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; margin: 0; padding: 12px; }
        .header { text-align: center; margin-bottom: 14px; border-bottom: 2px solid #eee; padding-bottom: 8px; }
        .header h1 { margin: 0; font-size: 16px; text-transform: uppercase; }
        .header p { margin: 4px 0; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f9f9f9; text-align: left; padding: 5px 4px; border-bottom: 2px solid #eee; font-size: 8px; text-transform: uppercase; }
        td { padding: 4px; border-bottom: 1px solid #eee; vertical-align: top; }
        .text-right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; font-size: 10px; }
        .footer { margin-top: 16px; text-align: center; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Refund register</h1>
        <p>{{ $restaurant->name }}</p>
        <p><strong>{{ $category === 'all' ? 'All Items' : ($category === 'bar' ? 'Bar (Liquor)' : 'Kitchen (Food)') }}</strong></p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
        <p>Rows: {{ $rows->count() }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Order #</th>
                <th>Customer / GSTIN</th>
                <th>Refund date</th>
                <th>Refunded at</th>
                <th class="text-right">Amount</th>
                <th>Method</th>
                <th>Reason</th>
                <th>Staff</th>
                <th>Refunded by</th>
            </tr>
        </thead>
        <tbody>
            @php $totalAmount = 0; @endphp
            @foreach($rows as $r)
            @php 
                $o = $r->order; 
                $totalAmount += (float) $r->amount;
            @endphp
            <tr>
                <td>#{{ $r->order_id }}</td>
                <td>
                    {{ $o?->customer_name ?: '—' }}<br>
                    @if($o?->customer_gstin)
                        <small style="color: #666;">GST: {{ $o->customer_gstin }}</small>
                    @endif
                </td>
                <td>{{ $r->business_date?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $r->refunded_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td class="text-right num">₹{{ number_format((float) $r->amount, 2) }}</td>
                <td>{{ $r->method }}</td>
                <td>{{ \Illuminate\Support\Str::limit($r->reason ?? '—', 40) }}</td>
                <td>{{ $o?->waiter?->name ?? '—' }}</td>
                <td>{{ $r->refundedBy?->name ?? '—' }}</td>
            </tr>
            @endforeach
            @if($rows->count() > 0)
            <tr style="font-weight: bold; background: #fafafa; border-top: 2px solid #ccc;">
                <td colspan="4">TOTAL REFUNDS</td>
                <td class="text-right num">₹{{ number_format($totalAmount, 2) }}</td>
                <td colspan="4"></td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
