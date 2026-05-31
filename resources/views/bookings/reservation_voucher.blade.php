<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Reservation Voucher #{{ $resNo }}</title>
    <style>
        @page { size: A4 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
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
        .lbl { color: #333; font-size: 7px; text-transform: uppercase; letter-spacing: 0.04em; }
        .val { font-weight: 600; margin-bottom: 3px; }

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

        .grid-line { border-bottom: 1px solid #000; margin: 6px 0; }

        table.simple {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            table-layout: fixed;
        }
        table.simple th, table.simple td {
            border: 1px solid #000;
            padding: 3px 4px;
            font-size: 7px;
            word-wrap: break-word;
        }
        table.simple th { background: #f0f0f0; font-weight: 700; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .cen { text-align: center; }

        .remark { border: 1px solid #000; min-height: 24px; padding: 4px; margin: 6px 0; font-size: 7px; }

        .footer { margin-top: 10px; width: 100%; border-collapse: collapse; table-layout: fixed; }
        .footer td { vertical-align: top; font-size: 7px; padding: 4px; }
        .bank-details { white-space: pre-line; line-height: 1.1; }
        .sig { text-align: center; margin-top: 12px; font-size: 8px; }
    </style>
</head>
<body>
    <p class="title">{{ $hotelName }}</p>
    @if($hotelAddress !== '')
        <p style="text-align:center;font-size:7px;margin:0 0 4px 0;white-space:pre-line;">{{ $hotelAddress }}</p>
    @endif
    @if($hotelGstin !== '')
        <p style="text-align:center;font-size:7px;margin:0 0 6px 0;">GSTIN: {{ $hotelGstin }}</p>
    @endif

    <p class="subhead">Reservation Voucher</p>

    <table class="meta">
        <tr>
            <td>
                <div class="lbl">Reservation No.</div>
                <div class="val">{{ $resNo }}</div>
                <div class="lbl">Booked On</div>
                <div class="val">{{ $bookedOn }}</div>
                <div class="lbl">Guest</div>
                <div class="val">{{ $guestName }}</div>
                <div class="lbl">Contact</div>
                <div class="val">{{ $contact }}</div>
            </td>
            <td class="right">
                <div class="lbl">Room</div>
                <div class="val">{{ $roomLabel }}</div>
                <div class="lbl">Guests</div>
                <div class="val">{{ $personsLabel }}</div>
                <div class="lbl">Arrival</div>
                <div class="val">{{ $arrivalStr }}</div>
                <div class="lbl">Departure</div>
                <div class="val">{{ $departureStr }}</div>
            </td>
        </tr>
    </table>

    <div class="grid-line"></div>

    <table class="simple">
        <tr>
            <th style="width:55%;">Particular</th>
            <th style="width:15%;" class="cen">Qty</th>
            <th style="width:30%;" class="num">Amount ({{ $currency }})</th>
        </tr>
        <tr>
            <td>Room charges</td>
            <td class="cen">{{ $nights }}</td>
            <td class="num">{{ $fmt($roomAmount) }}</td>
        </tr>
        @if($extraCharges > 0.004)
        <tr>
            <td>Early/Late / other charges</td>
            <td class="cen">—</td>
            <td class="num">{{ $fmt($extraCharges) }}</td>
        </tr>
        @endif
        <tr>
            <td style="font-weight:700;">Total</td>
            <td class="cen"> </td>
            <td class="num" style="font-weight:700;">{{ $fmt($grand) }}</td>
        </tr>
        <tr>
            <td>Amount paid</td>
            <td class="cen"> </td>
            <td class="num">{{ $fmt($paid) }}</td>
        </tr>
        <tr>
            <td style="font-weight:700;">Balance</td>
            <td class="cen"> </td>
            <td class="num" style="font-weight:700;">{{ $fmt($balance) }}</td>
        </tr>
    </table>

    <div class="remark">
        <span class="lbl">Notes</span><br>
        {{ $notes }}
    </div>

    <table class="footer">
        <tr>
            <td style="width:50%; border:1px solid #000; padding:6px;">
                <div>This voucher is in: {{ $currency }}</div>
                <div>Reception: {{ $receptionName }}</div>
                <div>Date: {{ $footerDate }}</div>
            </td>
            <td style="width:50%; border:1px solid #000; padding:6px;">
                <div style="font-weight:700;margin-bottom:4px;">{{ $bankCompanyName }}</div>
                @if($bankDetails !== '')
                    <div class="bank-details">{{ $bankDetails }}</div>
                @else
                    <div style="color:#666;">Bank details — configure under Admin → Settings → Invoice bank details.</div>
                @endif
            </td>
        </tr>
    </table>

    <p class="sig">(Guest Signature)</p>
</body>
</html>

