<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Menu / item performance</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; margin: 0; padding: 12px; }
        .header { text-align: center; margin-bottom: 14px; border-bottom: 2px solid #eee; padding-bottom: 8px; }
        .header h1 { margin: 0; font-size: 16px; text-transform: uppercase; }
        .header p { margin: 4px 0; color: #666; }
        .summary { margin-bottom: 12px; font-size: 10px; color: #555; }
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
        <h1>Menu / item performance</h1>
        <p><strong>{{ $category === 'all' ? 'All Items' : ($category === 'bar' ? 'Bar (Liquor)' : 'Kitchen (Food)') }}</strong></p>
        <p>{{ $restaurant->name }}</p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
    </div>
    <div class="summary">
        SKUs: {{ $summary['sku_rows'] ?? 0 }} · Qty sold: {{ number_format((float) ($summary['qty_sold'] ?? 0), 2) }}
        · Revenue: ₹{{ number_format((float) ($summary['revenue'] ?? 0), 2) }}
        · Distinct bills: {{ (int) ($summary['bills_with_sales'] ?? 0) }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Category</th>
                <th>Item / combo</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Revenue</th>
                <th class="text-right">Lines</th>
                <th class="text-right">Bills</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $r)
            @php
                $name = (string) ($r->name ?? '');
                $variant = trim((string) ($r->variant_label ?? ''));
                $itemCol = ($r->row_kind ?? '') === 'combo'
                    ? 'Combo: '.$name
                    : ($variant !== '' ? $name.' — '.$variant : $name);
            @endphp
            <tr>
                <td>{{ \Illuminate\Support\Str::limit($r->category_name ?? '—', 28) }}</td>
                <td>{{ \Illuminate\Support\Str::limit($itemCol, 42) }}</td>
                <td class="text-right num">{{ number_format((float) ($r->qty_sold ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($r->revenue ?? 0), 2) }}</td>
                <td class="text-right num">{{ (int) ($r->lines_sold ?? 0) }}</td>
                <td class="text-right num">{{ (int) ($r->bills_count ?? 0) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">Paid &amp; refunded orders, business date in range; voided lines excluded. Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
