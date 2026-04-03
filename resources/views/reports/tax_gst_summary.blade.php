<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Tax / GST summary</title>
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
        .total-row td { font-weight: bold; border-top: 2px solid #ccc; }
        .section-row td { background: #f0f0f0; font-weight: bold; font-size: 9px; text-transform: uppercase; padding: 6px 4px; border-top: 2px solid #ddd; }
        .footer { margin-top: 16px; text-align: center; font-size: 9px; color: #999; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Tax / GST summary</h1>
        <p>{{ $restaurant->name }}</p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
    </div>
    <div class="summary">
        Bills (excl. complimentary): {{ (int) ($totals['bills_count'] ?? 0) }}
        · Tax slabs: {{ (int) ($totals['bucket_count'] ?? 0) }}
    </div>

    <table>
        <thead>
            <tr>
                <th class="text-right">Rate %</th>
                <th>Label</th>
                <th class="text-right">Taxable value</th>
                <th class="text-right">Tax</th>
                <th class="text-right">Lines</th>
            </tr>
        </thead>
        <tbody>
            @php
                $gstRows = collect($rows)->filter(fn($r) => !str_starts_with($r['tax_label'] ?? '', 'Liquor VAT'));
                $vatRows = collect($rows)->filter(fn($r) => str_starts_with($r['tax_label'] ?? '', 'Liquor VAT'));
            @endphp

            @if($gstRows->count() > 0)
            <tr class="section-row">
                <td colspan="5">GST (Food & Beverages)</td>
            </tr>
            @foreach($gstRows as $row)
            <tr>
                <td class="text-right num">{{ number_format((float) ($row['rate'] ?? 0), 2) }}</td>
                <td>{{ \Illuminate\Support\Str::limit($row['tax_label'] ?? '—', 40) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['taxable_value'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['tax_amount'] ?? 0), 2) }}</td>
                <td class="text-right num">{{ (int) ($row['line_count'] ?? 0) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td></td>
                <td>GST SUBTOTAL</td>
                <td class="text-right num">₹{{ number_format((float) $gstRows->sum('taxable_value'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $gstRows->sum('tax_amount'), 2) }}</td>
                <td class="text-right num">{{ (int) $gstRows->sum('line_count') }}</td>
            </tr>
            @endif

            @if($vatRows->count() > 0)
            <tr class="section-row">
                <td colspan="5">State VAT (Liquor)</td>
            </tr>
            @foreach($vatRows as $row)
            <tr>
                <td class="text-right num">{{ number_format((float) ($row['rate'] ?? 0), 2) }}</td>
                <td>{{ \Illuminate\Support\Str::limit($row['tax_label'] ?? '—', 40) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['taxable_value'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($row['tax_amount'] ?? 0), 2) }}</td>
                <td class="text-right num">{{ (int) ($row['line_count'] ?? 0) }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td></td>
                <td>VAT SUBTOTAL</td>
                <td class="text-right num">₹{{ number_format((float) $vatRows->sum('taxable_value'), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) $vatRows->sum('tax_amount'), 2) }}</td>
                <td class="text-right num">{{ (int) $vatRows->sum('line_count') }}</td>
            </tr>
            @endif

            <tr class="total-row">
                <td></td>
                <td>GRAND TOTAL</td>
                <td class="text-right num">₹{{ number_format((float) ($totals['taxable_value'] ?? 0), 2) }}</td>
                <td class="text-right num">₹{{ number_format((float) ($totals['tax_amount'] ?? 0), 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="footer">Item tax only (excludes service charge / tip / delivery fee tax). Matches POS line math. Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
