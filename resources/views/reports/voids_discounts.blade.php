<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voids &amp; discounts</title>
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
        <h1>
            @if($section === 'void_bills') Void bills
            @elseif($section === 'void_items') Void line items
            @else Bill discounts
            @endif
        </h1>
        <p>{{ $restaurant->name }}</p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
        <p>Rows: {{ $rows->count() }}</p>
    </div>

    @if($section === 'void_bills')
    <table>
        <thead>
            <tr>
                <th>Bill #</th>
                <th>Trading day</th>
                <th>Voided at</th>
                <th class="text-right">Amount</th>
                <th>Reason</th>
                <th>Day close</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $o)
            @php
                $bd = $o->business_date?->format('Y-m-d');
                $close = $bd && isset($closingMap[$bd]) ? $closingMap[$bd] : null;
            @endphp
            <tr>
                <td>#{{ $o->id }}</td>
                <td>{{ $bd ?? '—' }}</td>
                <td>{{ $o->voided_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td class="text-right num">₹{{ number_format((float) $o->total_amount, 2) }}</td>
                <td>{{ \Illuminate\Support\Str::limit($o->void_reason ?? '—', 40) }}</td>
                <td>{{ $close ? (\Illuminate\Support\Str::limit($close['day_closed_at'] ?? '', 16).' — '.($close['day_closed_by'] ?? '')) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @elseif($section === 'void_items')
    <table>
        <thead>
            <tr>
                <th>Order #</th>
                <th>Trading day</th>
                <th>Item</th>
                <th>Cancelled</th>
                <th class="text-right">Line</th>
                <th>Day close</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $item)
            @php
                $ord = $item->order;
                $bd = $ord?->business_date?->format('Y-m-d');
                $close = $bd && isset($closingMap[$bd]) ? $closingMap[$bd] : null;
                $lineName = $item->menuItem?->name ?? ($item->combo?->name ? 'Combo: '.$item->combo->name : '—');
            @endphp
            <tr>
                <td>#{{ $item->order_id }}</td>
                <td>{{ $bd ?? '—' }}</td>
                <td>{{ \Illuminate\Support\Str::limit($lineName, 30) }}</td>
                <td>{{ $item->cancelled_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td class="text-right num">₹{{ number_format((float) $item->line_total, 2) }}</td>
                <td>{{ $close ? (\Illuminate\Support\Str::limit($close['day_closed_at'] ?? '', 16).' — '.($close['day_closed_by'] ?? '')) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @else
    <table>
        <thead>
            <tr>
                <th>Bill #</th>
                <th>Trading day</th>
                <th>Closed</th>
                <th class="text-right">Discount</th>
                <th>Approved by</th>
                <th>Day close</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $o)
            @php
                $bd = $o->business_date?->format('Y-m-d');
                $close = $bd && isset($closingMap[$bd]) ? $closingMap[$bd] : null;
            @endphp
            <tr>
                <td>#{{ $o->id }}</td>
                <td>{{ $bd ?? '—' }}</td>
                <td>{{ $o->closed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                <td class="text-right num">₹{{ number_format((float) $o->discount_amount, 2) }}</td>
                <td>{{ $o->discountApprovedBy?->name ?? '—' }}</td>
                <td>{{ $close ? (\Illuminate\Support\Str::limit($close['day_closed_at'] ?? '', 16).' — '.($close['day_closed_by'] ?? '')) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    <div class="footer">Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
