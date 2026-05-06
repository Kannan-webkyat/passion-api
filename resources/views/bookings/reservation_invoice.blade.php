<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice #{{ $invoiceNo }}</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        /* DomPDF: border-box on td/th breaks % widths on MAIN grids — use content-box only on those cells.
           Do NOT use table.mid-wrap td (would hit nested summary-inner td): padding + content-box overflows past border */
        table.lines th,
        table.lines td,
        table.mid-wrap > tbody > tr > td,
        table.footer td {
            box-sizing: content-box;
        }
        body {
            font-family: DejaVu Sans, Helvetica, Arial, sans-serif;
            font-size: 8px;
            color: #000;
            margin: 0;
            line-height: 1.25;
        }
        .title {
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 6px 0;
            letter-spacing: 0.04em;
        }
        .subhead {
            text-align: center;
            font-size: 9px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        table.meta {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        table.meta td {
            width: 50%;
            vertical-align: top;
            padding: 0 6px 0 0;
            font-size: 8px;
        }
        table.meta td.right { padding: 0 0 0 6px; }
        .lbl { color: #333; font-size: 7px; text-transform: uppercase; letter-spacing: 0.04em; }
        .val { font-weight: 600; margin-bottom: 3px; }
        .grid-line { border-bottom: 1px solid #000; margin: 6px 0; }
        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            table-layout: fixed;
        }
        table.lines th,
        table.lines td {
            border: 1px solid #000;
            padding: 2px 3px;
            font-size: 6.5px;
            word-wrap: break-word;
        }
        table.lines th {
            background: #f0f0f0;
            font-weight: 700;
            text-align: center;
        }
        .num { text-align: right; white-space: nowrap; }
        .cen { text-align: center; }
        .lines-total th, .lines-total td { font-weight: 700; background: #f5f5f5; }
        table.mid-wrap {
            width: 100%;
            margin-top: 6px;
            margin-bottom: 8px;
            border-collapse: collapse;
            border-spacing: 0;
            table-layout: fixed;
        }
        .mid-wrap td {
            vertical-align: top;
            padding: 0;
        }
        /* Keep gutter inside cells so % widths stay true full-page (DomPDF aligns summary with lines table). */
        .mid-wrap-left-inner {
            padding-right: 6px;
        }
        .mid-wrap-payments-cell {
            width: 58%;
        }
        /* Specify td.mid-wrap… so padding beats `.mid-wrap td { padding: 0 }` */
        .mid-wrap td.mid-wrap-summary-cell {
            width: 42%;
            border: 1px solid #000;
            padding: 0 0 10px 0;
            font-size: 7.5px;
            vertical-align: top;
        }
        .words {
            font-size: 8px;
            font-weight: 600;
            margin-bottom: 4px;
            border: 1px solid #000;
            padding: 4px;
        }
        table.pay, table.taxd {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }
        table.pay th, table.pay td,
        table.taxd th, table.taxd td {
            border: 1px solid #000;
            padding: 2px 4px;
            font-size: 7px;
        }
        table.pay th, table.taxd th { background: #eee; }
        table.summary-inner {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        /* border-box so horizontal padding stays INSIDE column width (avoids drawing through right border) */
        table.summary-inner td {
            padding: 2px 0;
            font-size: 7.5px;
            box-sizing: border-box;
        }
        table.summary-inner .sum-l {
            text-align: left;
            width: 58%;
            word-wrap: break-word;
            overflow-wrap: break-word;
            padding: 2px 6px 2px 8px;
        }
        table.summary-inner .sum-r {
            text-align: right;
            font-weight: 600;
            width: 42%;
            white-space: nowrap;
            padding: 2px 8px 2px 6px;
        }
        table.summary-inner tr.bold td {
            font-weight: 700;
            border-top: 1px solid #999;
            padding-top: 4px;
        }
        .footer {
            margin-top: 10px;
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        .footer td { vertical-align: top; font-size: 7px; padding: 4px; }
        .bank-details {
            /* DomPDF: avoid nl2br + pre-wrap double-spacing; rely on \n rendering */
            white-space: pre-line;
            line-height: 1.1;
        }
        .remark { border: 1px solid #000; min-height: 28px; padding: 4px; margin-bottom: 6px; font-size: 7px; }
        .sig { text-align: center; margin-top: 12px; font-size: 8px; }
    </style>
</head>
<body>
    <p class="title">{{ $hotelName }}</p>
    @if($hotelAddress !== '')
        <p style="text-align:center;font-size:7px;margin:0 0 4px 0;">{!! nl2br(e($hotelAddress)) !!}</p>
    @endif
    @if($hotelGstin !== '')
        <p style="text-align:center;font-size:7px;margin:0 0 6px 0;">GSTIN: {{ $hotelGstin }}</p>
    @endif

    <p class="subhead">Invoice</p>

    <table class="meta">
        <tr>
            <td>
                <div class="lbl">Folio No. / Res No.</div>
                <div class="val">{{ $folioNo }} / {{ $resNo }}</div>
                <div class="lbl">Invoice No.</div>
                <div class="val">{{ $invoiceNo }}</div>
                <div class="lbl">Guest Name</div>
                <div class="val">{{ $guestName }}</div>
                <div class="lbl">Bill To</div>
                <div class="val">{{ $billToName }}</div>
                <div class="lbl">Bill To Address</div>
                <div class="val">{{ $billToAddress !== '' ? $billToAddress : '—' }}</div>
                <div class="lbl">State</div>
                <div class="val">{{ $guestState !== '' ? $guestState : '—' }}</div>
                <div class="lbl">Bill To GSTIN</div>
                <div class="val">{{ $guestGstin !== '' ? $guestGstin : '—' }}</div>
                <div class="lbl">Source</div>
                <div class="val">{{ $bookingSource }}</div>
                <div class="lbl">Source of Supply</div>
                <div class="val">{{ $sourceOfSupply !== '' ? $sourceOfSupply : '—' }}</div>
            </td>
            <td class="right">
                <div class="lbl">G.R. Card No.</div>
                <div class="val">{{ $grCardNo }}</div>
                <div class="lbl">Date of Invoice</div>
                <div class="val">{{ $invoiceDate }}</div>
                <div class="lbl">Room</div>
                <div class="val">{{ $roomLabel }}</div>
                <div class="lbl">No of Person</div>
                <div class="val">{{ $personsLabel }}</div>
                <div class="lbl">Rate Type</div>
                <div class="val">{{ $rateTypeLabel }}</div>
                <div class="lbl">No of Nights</div>
                <div class="val">{{ $nights }}</div>
                <div class="lbl">Date of Arrival</div>
                <div class="val">{{ $arrivalStr }}</div>
                <div class="lbl">Date of Departure</div>
                <div class="val">{{ $departureStr }}</div>
                <div class="lbl">TA Voucher No.</div>
                <div class="val">{{ $taVoucher }}</div>
            </td>
        </tr>
    </table>

    <div class="grid-line"></div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:3%">Sr</th>
                <th style="width:18%">Particular</th>
                <th style="width:7%">HSN/SAC</th>
                <th style="width:4%">Qty</th>
                <th style="width:7%">Rate</th>
                <th style="width:7%">Total</th>
                <th style="width:6%">Discount</th>
                <th style="width:7%">Taxable</th>
                <th style="width:9%">SGST</th>
                <th style="width:9%">CGST</th>
                <th style="width:9%">IGST</th>
                <th style="width:6%">CESS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $row)
                <tr>
                    <td class="cen">{{ $row['sr'] }}</td>
                    <td>{{ $row['particular'] }}</td>
                    <td class="cen">{{ $row['hsn'] }}</td>
                    <td class="num">{{ $row['qty'] }}</td>
                    <td class="num">{{ $row['rate'] }}</td>
                    <td class="num">{{ $row['total'] }}</td>
                    <td class="num">{{ $row['discount'] }}</td>
                    <td class="num">{{ $row['taxable'] }}</td>
                    <td class="num">{{ $row['sgst_cell'] }}</td>
                    <td class="num">{{ $row['cgst_cell'] }}</td>
                    <td class="num">{{ $row['igst_cell'] }}</td>
                    <td class="num">{{ $row['cess'] }}</td>
                </tr>
            @endforeach
            <tr class="lines-total">
                <td colspan="5" style="text-align:right">Total</td>
                <td class="num">{{ $colTotals['total'] }}</td>
                <td class="num">{{ $colTotals['discount'] }}</td>
                <td class="num">{{ $colTotals['taxable'] }}</td>
                <td class="num">{{ $colTotals['sgst'] }}</td>
                <td class="num">{{ $colTotals['cgst'] }}</td>
                <td class="num">{{ $colTotals['igst'] }}</td>
                <td class="num">{{ $colTotals['cess'] }}</td>
            </tr>
        </tbody>
    </table>

    <table class="mid-wrap">
        <colgroup>
            <col width="58%">
            <col width="42%">
        </colgroup>
        <tbody>
        <tr>
            <td class="mid-wrap-payments-cell" width="58%">
                <div class="mid-wrap-left-inner">
                <div class="words">
                    Total Payable Amount (In words):<br>
                    <span style="text-transform:capitalize;">{{ $amountInWords }} only</span>
                </div>
                <div class="lbl" style="margin-bottom:2px;">Payments</div>
                <table class="pay">
                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Description</th>
                            <th class="num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($paymentRows as $pr)
                            <tr>
                                <td>{{ $pr['date'] }}</td>
                                <td>{{ $pr['description'] }}</td>
                                <td class="num">{{ $pr['amount'] }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td colspan="2" style="font-weight:700;">Total</td>
                            <td class="num" style="font-weight:700;">{{ $paymentTotalFmt }}</td>
                        </tr>
                    </tbody>
                </table>
                @if(count($taxDetailRows) > 0)
                    <div class="lbl" style="margin:4px 0 2px 0;">Tax details</div>
                    <table class="taxd">
                        <thead>
                            <tr>
                                <th>Tax details</th>
                                <th class="num">Taxable Amount</th>
                                <th class="num">Tax Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($taxDetailRows as $tr)
                                <tr>
                                    <td>{{ $tr['label'] }}</td>
                                    <td class="num">{{ $tr['taxable'] }}</td>
                                    <td class="num">{{ $tr['tax'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                </div>
            </td>
            <td class="mid-wrap-summary-cell" width="42%">
                <table class="summary-inner">
                    @foreach($summaryLines as $sl)
                        <tr class="{{ !empty($sl['bold']) ? 'bold' : '' }}">
                            <td class="sum-l">{{ $sl['label'] }}</td>
                            <td class="sum-r">{{ $sl['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
        </tbody>
    </table>

    <div class="remark">
        <span class="lbl">Remark</span><br>
        {{ $remark }}
    </div>

    <table class="footer">
        <tr>
            <td style="width:50%; border:1px solid #000; padding:6px;">
                <div>This Folio is in: {{ $currency }}</div>
                <div>Reception (C/I): {{ $receptionName }}</div>
                <div>Cashier (C/O): {{ $cashierName }}</div>
                <div>Date: {{ $footerDate }}</div>
                <div>Page: Page 1 of 1</div>
            </td>
            <td style="width:50%; border:1px solid #000; padding:6px;">
                <div style="font-weight:700;margin-bottom:4px;">{{ $bankCompanyName }}</div>
                @if($bankDetails !== '')
                    <div class="bank-details">{{ $bankDetails }}</div>
                @else
                    <div style="color:#666;">Bank details — configure under Admin → Settings → Receipt defaults.</div>
                @endif
            </td>
        </tr>
    </table>

    <p class="sig">(Guest Signature)</p>
</body>
</html>
