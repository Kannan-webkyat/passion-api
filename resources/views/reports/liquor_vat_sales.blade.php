<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Liquor / VAT sales</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 7px; color: #222; margin: 0; padding: 10px; }
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 6px; }
        .header h1 { margin: 0; font-size: 13px; text-transform: uppercase; }
        .header p { margin: 3px 0; color: #555; font-size: 8px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
        th { background: #f2f2f2; text-align: left; padding: 3px 2px; border: 1px solid #ddd; font-size: 6px; text-transform: uppercase; word-wrap: break-word; }
        th.num { text-align: right; }
        td { padding: 2px; border: 1px solid #eee; vertical-align: top; font-size: 6px; word-wrap: break-word; overflow: hidden; }
        td.num { text-align: right; font-family: DejaVu Sans Mono, monospace; }
        .footer { margin-top: 10px; text-align: center; font-size: 7px; color: #888; }
        .total-row td { font-weight: bold; border-top: 2px solid #ccc; background: #fafafa; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Liquor / VAT sales</h1>
        <p>{{ $restaurant?->name ?? 'Outlet' }}</p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
    </div>

    <table>
        <thead>
            <tr>
                @foreach($headers as $idx => $h)
                    @php
                        $isNumeric = $idx >= 1 && !in_array($h, ['Line ID', 'Line status', 'Bill #', 'Customer', 'Customer tax ID', 'Business date', 'Item', 'Tax type', 'Payment', 'Order status', 'Closed / voided', 'Waiter', 'Cashier']);
                    @endphp
                    <th @if($isNumeric) class="num" @endif>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @php
                $numericHeaders = [];
                foreach($headers as $idx => $h) {
                    $numericHeaders[$idx] = $idx >= 1 && !in_array($h, ['Line ID', 'Line status', 'Bill #', 'Customer', 'Customer tax ID', 'Business date', 'Item', 'Tax type', 'Payment', 'Order status', 'Closed / voided', 'Waiter', 'Cashier']);
                }
                $totals = array_fill(0, count($headers), null);
            @endphp
            @foreach($rows as $row)
                <tr>
                    @foreach($row as $idx => $cell)
                        @php
                            $isNum = $numericHeaders[$idx] ?? false;
                            if ($isNum && is_numeric($cell)) {
                                $totals[$idx] = ($totals[$idx] ?? 0) + (float) $cell;
                            }
                        @endphp
                        <td @if($isNum) class="num" @endif>{{ $cell }}</td>
                    @endforeach
                </tr>
            @endforeach
            @if(count($rows) > 0)
            <tr class="total-row">
                @foreach($headers as $idx => $h)
                    @if($idx === 0)
                        <td>TOTAL</td>
                    @elseif(isset($totals[$idx]) && $totals[$idx] !== null)
                        <td class="num">{{ number_format($totals[$idx], 2) }}</td>
                    @else
                        <td></td>
                    @endif
                @endforeach
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
