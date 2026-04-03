<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Sales Audit Report</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 8px;
            color: #333;
            margin: 0;
            padding: 10px;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }

        .header h1 {
            margin: 0;
            font-size: 14px;
            text-transform: uppercase;
        }

        .header p {
            margin: 5px 0;
            color: #666;
        }

        .summary-grid {
            margin-bottom: 20px;
            width: 100%;
            border-collapse: collapse;
        }

        .summary-box {
            border: 1px solid #eee;
            padding: 10px;
            text-align: center;
        }

        .summary-box .label {
            font-size: 10px;
            text-transform: uppercase;
            color: #888;
            font-weight: bold;
        }

        .summary-box .value {
            font-size: 16px;
            font-weight: bold;
            margin-top: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th {
            background: #f9f9f9;
            text-align: left;
            padding: 4px 3px;
            border-bottom: 2px solid #eee;
            font-size: 6px;
            text-transform: uppercase;
        }

        td {
            padding: 3px;
            border-bottom: 1px solid #eee;
            vertical-align: top;
            font-size: 6px;
        }

        .text-right {
            text-align: right;
        }

        .amount {
            font-family: monospace;
            font-weight: bold;
        }

        .refund {
            color: #e11d48;
        }

        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
        }

        .status-paid {
            color: #059669;
        }

        .status-void {
            text-decoration: line-through;
            color: #94a3b8;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Sales Audit Report</h1>
        <p>{{ $restaurant->name }}</p>
        <p>Period: {{ date('d M Y', strtotime($from)) }} to {{ date('d M Y', strtotime($to)) }}</p>
    </div>

    <table class="summary-grid">
        <tr>
            <td class="summary-box">
                <div class="label">Total Bills</div>
                <div class="value">{{ $summary['count'] }}</div>
            </td>
            <td class="summary-box">
                <div class="label">Net Revenue</div>
                <div class="value">₹{{ number_format($summary['net'], 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="label">GST (CGST+SGST+IGST)</div>
                <div class="value">₹{{ number_format($summary['gst_duty'] ?? 0, 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="label">Liquor VAT</div>
                <div class="value">₹{{ number_format($summary['vat_tax'] ?? 0, 2) }}</div>
            </td>
            <td class="summary-box">
                <div class="label">Total Refunds</div>
                <div class="value refund">₹{{ number_format($summary['refunds'], 2) }}</div>
            </td>
        </tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Bill #</th>
                <th>When</th>
                <th>Customer / GSTIN</th>
                <th>Staff</th>
                <th>Type</th>
                <th>Payment</th>
                <th>Status</th>
                <th class="text-right">CGST</th>
                <th class="text-right">SGST</th>
                <th class="text-right">IGST</th>
                <th class="text-right">Liq VAT</th>
                <th class="text-right">GST txbl</th>
                <th class="text-right">VAT txbl</th>
                <th class="text-right">Amount</th>
                <th class="text-right">Refund</th>
            </tr>
        </thead>
        <tbody>
            @foreach($orders as $o)
            <tr>
                <td>#{{ $o->id }}</td>
                <td>
                    {{ date('d/m/y', strtotime($o->business_date)) }}<br>
                    @if($o->closed_at || $o->voided_at)
                        <small style="color: #888;">{{ date('H:i', strtotime($o->closed_at ?: $o->voided_at)) }}</small>
                    @endif
                </td>
                <td>
                    {{ $o->customer_name ?: '—' }}<br>
                    @if($o->customer_gstin)
                        <small style="color: #666;">GST: {{ $o->customer_gstin }}</small>
                    @endif
                </td>
                <td>{{ $o->waiter?->name ?? '—' }}</td>
                <td style="text-transform: capitalize;">{{ str_replace('_', ' ', $o->order_type) }}</td>
                <td>
                    @php
                        $paymentModes = $o->payments->pluck('method')->map(fn($m) => ucfirst($m))->implode(', ');
                        if (empty($paymentModes)) $paymentModes = $o->status === 'void' ? '—' : 'Missing';
                    @endphp
                    <small>{{ $paymentModes }}</small>
                </td>
                <td class="status-{{ $o->status }}">{{ ucfirst($o->status) }}</td>
                <td class="text-right amount">₹{{ number_format($o->cgst_amount ?? 0, 2) }}</td>
                <td class="text-right amount">₹{{ number_format($o->sgst_amount ?? 0, 2) }}</td>
                <td class="text-right amount">₹{{ number_format($o->igst_amount ?? 0, 2) }}</td>
                <td class="text-right amount">₹{{ number_format($o->vat_tax_amount ?? 0, 2) }}</td>
                <td class="text-right amount">₹{{ number_format($o->gst_net_taxable ?? 0, 2) }}</td>
                <td class="text-right amount">₹{{ number_format($o->vat_net_taxable ?? 0, 2) }}</td>
                <td class="text-right amount {{ $o->status === 'void' ? 'status-void' : '' }}">
                    ₹{{ number_format($o->total_amount, 2) }}
                </td>
                <td class="text-right amount refund">
                    @if($o->refunds->sum('amount') > 0)
                    ₹{{ number_format($o->refunds->sum('amount'), 2) }}
                    @else
                    —
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Generated on {{ date('d M Y H:i:s') }} | Passions POS Audit System
    </div>
</body>

</html>