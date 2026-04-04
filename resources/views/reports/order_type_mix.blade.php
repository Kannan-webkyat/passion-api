<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Order-type mix</title>
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
        .total-row td { font-weight: bold; border-top: 2px solid #ccc; }
        .footer { margin-top: 16px; text-align: center; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    @php
        $mixLabel = function (string $t): string {
            return match ($t) {
                'walk_in' => 'Walk-in',
                'dine_in' => 'Dine-in',
                'takeaway' => 'Takeaway',
                'room_service' => 'Room service',
                'delivery' => 'Delivery',
                default => \Illuminate\Support\Str::title(str_replace('_', ' ', $t)),
            };
        };
        $netTotal = max(0.01, (float) ($totals['net_revenue'] ?? 0));
    @endphp
    <div class="header">
        <h1>Order-type mix</h1>
        <p><strong>{{ $category === 'all' ? 'All Revenue' : ($category === 'liquor' ? 'Bar (Liquor)' : 'Kitchen (Food)') }}</strong></p>
        <p>{{ $restaurant->name }}</p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>Channel</th>
                <th class="text-right">Bills</th>
                <th class="text-right">Gross</th>
                <th class="text-right">Refunds</th>
                <th class="text-right">Net</th>
                <th class="text-right">Mix %</th>
            </tr>
        </thead>
        <tbody>
            @foreach($by_type as $row)
            @php
                $net = (float) ($row['net_revenue'] ?? 0);
                $pct = $netTotal > 0 ? round(100 * $net / $netTotal, 1) : 0;
            @endphp
            <tr>
                <td>{{ $mixLabel($row['order_type'] ?? '') }}</td>
                <td class="text-right num">{{ (int) ($row['orders_count'] ?? 0) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['gross_revenue'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['refunded_amount'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['net_revenue'] ?? 0), 2) }}</td>
                <td class="text-right num">{{ $pct }}%</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td>TOTAL</td>
                <td class="text-right num">{{ (int) ($totals['orders_count'] ?? 0) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($totals['gross_revenue'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($totals['refunded_amount'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($totals['net_revenue'] ?? 0), 2) }}</td>
                <td class="text-right num">100%</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">Gross: paid/refunded bills on business date. Refunds: by refund date, per order type. Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
