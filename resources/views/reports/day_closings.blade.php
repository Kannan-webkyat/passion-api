<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Closing History</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; margin: 0; padding: 12px; }
        .header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .header h1 { margin: 0; font-size: 18px; text-transform: uppercase; }
        .header p { margin: 4px 0; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 7px; }
        th { background: #f9f9f9; text-align: left; padding: 4px 3px; border-bottom: 2px solid #eee; font-size: 6px; text-transform: uppercase; }
        td { padding: 3px; border-bottom: 1px solid #eee; vertical-align: top; }
        .text-right { text-align: right; }
        .num { font-family: DejaVu Sans Mono, monospace; font-size: 7px; }
        .footer { margin-top: 20px; text-align: center; font-size: 9px; color: #999; }
        .total-row td { font-weight: bold; border-top: 2px solid #ccc; background: #fafafa; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Closing history (Z-report)</h1>
        @if(isset($restaurant) && $restaurant)
            <p>{{ $restaurant->name ?? 'All Outlets' }}</p>
        @endif
        <p>
            @if($from && $to)
                Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}
            @elseif($from)
                From {{ date('d M Y', strtotime($from)) }}
            @elseif($to)
                Up to {{ date('d M Y', strtotime($to)) }}
            @else
                All dates in export
            @endif
        </p>
        <p>Rows: {{ $closings->count() }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Outlet</th>
                <th class="text-right">Orders</th>
                <th class="text-right">Gross Sales</th>
                <th class="text-right">Discount</th>
                <th class="text-right">Tax</th>
                <th class="text-right">Svc Chg</th>
                <th class="text-right">Tips</th>
                <th class="text-right">Paid</th>
                <th class="text-right">Cash</th>
                <th class="text-right">Card</th>
                <th class="text-right">UPI</th>
                <th class="text-right">Room</th>
                <th class="text-right">Voids</th>
                <th>By</th>
            </tr>
        </thead>
        <tbody>
            @forelse($closings as $c)
            <tr>
                <td>{{ $c->closed_date?->format('d/m/Y') ?? '—' }}</td>
                <td>{{ $c->restaurant?->name ?? '—' }}</td>
                <td class="text-right num">{{ $c->order_count }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->total_sales, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->total_discount, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->total_tax, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->total_service_charge, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->total_tip, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->total_paid, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->cash_total, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->card_total, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->upi_total, 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $c->room_charge_total, 2) }}</td>
                <td class="text-right num">{{ $c->void_count }}</td>
                <td>{{ $c->closedByUser?->name ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="15" style="text-align: center; padding: 20px; color: #888;">No closings in this range.</td>
            </tr>
            @endforelse
            @if($closings->count() > 0)
            <tr class="total-row">
                <td></td>
                <td>TOTAL</td>
                <td class="text-right num">{{ $closings->sum('order_count') }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('total_sales'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('total_discount'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('total_tax'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('total_service_charge'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('total_tip'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('total_paid'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('cash_total'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('card_total'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('upi_total'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $closings->sum('room_charge_total'), 2) }}</td>
                <td class="text-right num">{{ $closings->sum('void_count') }}</td>
                <td></td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Generated {{ now()->format('d M Y H:i') }}
    </div>
</body>
</html>
