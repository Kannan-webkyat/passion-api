<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Models\BookingPayment;
use App\Models\PosOrder;
use App\Models\RatePlan;
use App\Models\Setting;
use App\Support\BookingInspectionChargeLines;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Builds view data for the hotel invoice PDF (folio / GST-style layout).
 */
final class ReservationInvoiceViewData
{
    public static function build(Booking $booking): array
    {
        $booking->loadMissing(['room.roomType.tax', 'creator']);

        $fmt = static fn(float $n): string => number_format(round($n, 2), 2, '.', '');

        $guestName = strtoupper(trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? '')));
        $guestName = $guestName !== '' ? $guestName : 'GUEST';

        $checkInRaw = $booking->check_in_at ? Carbon::parse($booking->check_in_at) : Carbon::parse($booking->check_in)->startOfDay();
        $checkOutRaw = $booking->check_out_at ? Carbon::parse($booking->check_out_at) : Carbon::parse($booking->check_out)->startOfDay();

        $roomNo = (string) ($booking->room?->room_number ?? '-');
        $roomType = (string) ($booking->room?->roomType?->name ?? '-');
        $roomLabel = $roomType . ' / ' . $roomNo;

        $ratePlan = $booking->rate_plan_id ? RatePlan::find($booking->rate_plan_id) : null;
        $meal = (string) ($ratePlan?->meal_plan_type ?? '');
        // Prefer admin-configured plan name; abbreviations only when name is empty (matches Room Types).
        $planName = trim((string) ($ratePlan?->name ?? ''));
        if ($planName !== '') {
            $rateTypeLabel = Str::limit($planName, 96, '…');
        } elseif ($ratePlan && $meal !== '') {
            $rateTypeLabel = match ($meal) {
                'breakfast' => 'CP',
                'half_board' => 'MAP',
                'full_board' => 'AP',
                'room_only' => 'EP',
                default => '—',
            };
        } else {
            $rateTypeLabel = '—';
        }

        $adults = (int) ($booking->adults_count ?? 1);
        $children = (int) ($booking->children_count ?? 0);
        $personsLabel = $adults . ' (A) / ' . $children . ' (C)';

        $nights = 1;
        if (($booking->booking_unit ?? 'day') !== 'hour_package') {
            $nights = max(1, (int) $checkInRaw->copy()->startOfDay()->diffInDays($checkOutRaw->copy()->startOfDay()));
        }

        $hourPackage = ($booking->booking_unit ?? 'day') === 'hour_package';
        $checkInDisplay = self::invoiceArrivalDisplayMoment($checkInRaw, $booking);
        $checkOutDisplay = self::invoiceDepartureDisplayMoment($checkOutRaw, $booking);

        $staySummary = BookingInvoiceRoomStay::summarizeForInvoice($booking);
        $roomGrand = $staySummary['room_inclusive_grand'];
        $extraCharges = $staySummary['additive_extra_charges'];
        $inspectionLines = BookingInspectionChargeLines::forBooking($booking);
        $inspectionTotals = BookingInspectionChargeLines::totalsByKind($inspectionLines);
        $posPostedTotal = BookingInvoiceRoomStay::sumPosRoomChargePayments($booking);
        $grossBill = $staySummary['gross_before_checkout_discount'];
        $checkoutDisc = max(0.0, min((float) ($booking->checkout_discount_amount ?? 0), $grossBill));
        $grand = max(0.0, $grossBill - $checkoutDisc);
        $paid = round(max(0.0, (float) ($booking->deposit_amount ?? 0) - (float) ($booking->refund_amount ?? 0)), 2);

        /** Nearest whole rupee for settlement lines (round off shown in summary when needed). */
        $grandRounded = (float) round($grand);
        $roundOff = $grandRounded - $grand;
        $balanceRounded = $grandRounded - $paid;

        $taxRate = (float) ($booking->room?->roomType?->tax?->rate ?? 0);
        $divisor = 1 + ($taxRate > 0 ? $taxRate / 100 : 0);
        $roomSubTotal = $divisor > 0 ? ($roomGrand / $divisor) : $roomGrand;
        $roomTaxAmount = $roomGrand - $roomSubTotal;
        $halfGst = $taxRate / 2;
        $roomSgst = $roomTaxAmount / 2;
        $roomCgst = $roomTaxAmount / 2;

        $roomSac = trim((string) (Setting::get('invoice_room_sac') ?? ''));
        if ($roomSac === '') {
            $roomSac = '—';
        }

        $ratePerNightPreTax = $nights > 0 ? ($roomSubTotal / $nights) : $roomSubTotal;

        $lines = [];
        $sr = 1;

        $lines[] = self::lineRow(
            $sr++,
            'Room Charges',
            (string) $roomSac,
            (string) $nights,
            $fmt($ratePerNightPreTax),
            $fmt($roomGrand),
            $fmt(0),
            $fmt($roomSubTotal),
            $halfGst,
            $roomSgst,
            $halfGst,
            $roomCgst,
            0.0,
            0.0,
            $fmt(0)
        );

        $folioOrders = PosOrder::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'paid')
            ->whereHas('payments', fn($q) => $q->where('method', 'room_charge'))
            ->with(['restaurant', 'payments'])
            ->orderBy('id')
            ->get();

        foreach ($folioOrders as $order) {
            $rc = (float) $order->payments->where('method', 'room_charge')->sum('amount');
            if ($rc <= 0) {
                continue;
            }
            $tot = max((float) $order->total_amount, 0.0001);
            $ratio = min(1.0, $rc / $tot);
            $taxable = (float) $order->gst_net_taxable * $ratio;
            $disc = (float) $order->discount_amount * $ratio;
            $cgst = (float) $order->cgst_amount * $ratio;
            $sgst = (float) $order->sgst_amount * $ratio;
            $igst = (float) $order->igst_amount * $ratio;
            $outlet = $order->restaurant?->name ?? 'Outlet';
            $sac = trim((string) ($order->restaurant?->sac_code ?? ''));
            if ($sac === '') {
                $sac = trim((string) (Setting::get('invoice_fnb_sac') ?? ''));
            }
            if ($sac === '') {
                $sac = '—';
            }
            $qty = 1;
            $rate = $rc;
            $particular = 'Room posting (' . $outlet . ' · REC-' . $order->id . ')';

            $lines[] = self::lineRow(
                $sr++,
                $particular,
                (string) $sac,
                (string) $qty,
                $fmt($rate),
                $fmt($rc),
                $fmt($disc),
                $fmt($taxable),
                $sgst > 0.004 ? ($sgst / max($taxable, 0.01)) * 100 : 0,
                $sgst,
                $cgst > 0.004 ? ($cgst / max($taxable, 0.01)) * 100 : 0,
                $cgst,
                $igst > 0.004 ? ($igst / max($taxable, 0.01)) * 100 : 0,
                $igst,
                $fmt(0)
            );
        }

        $damageSac = trim((string) (Setting::get('invoice_damage_sac') ?? ''));
        if ($damageSac === '') {
            $damageSac = '—';
        }

        $qtyFmt = static fn(float $q): string => rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
        $fbRate = (float) Setting::get('invoice_default_food_gst_rate', (string) max(5, $taxRate));
        $fbSac = trim((string) (Setting::get('invoice_fnb_sac') ?? ''));
        $fbSac = $fbSac !== '' ? $fbSac : '—';
        /** Inclusive amount with GST backed out at the F&B default rate (minibar, laundry, other posted extras). */
        $fbLine = function (string $particular, float $qty, float $unit, float $total) use (&$sr, $fmt, $qtyFmt, $fbRate, $fbSac): array {
            $taxable = $total / (1 + $fbRate / 100);
            $gst = $total - $taxable;

            return self::lineRow(
                $sr++,
                $particular,
                $fbSac,
                $qtyFmt($qty),
                $fmt($unit),
                $fmt($total),
                $fmt(0),
                $fmt($taxable),
                $gst > 0.004 ? ($fbRate / 2) : 0.0,
                $gst / 2,
                $gst > 0.004 ? ($fbRate / 2) : 0.0,
                $gst / 2,
                0.0,
                0.0,
                $fmt(0)
            );
        };

        $nonPosExtra = max(0.0, round($extraCharges - $posPostedTotal, 2));
        if ($inspectionTotals['total'] > $nonPosExtra + 0.01) {
            // Snapshot charges not yet posted to the folio are not part of the payable.
            $inspectionLines = [];
            $inspectionTotals = BookingInspectionChargeLines::totalsByKind([]);
        }

        foreach ($inspectionLines as $line) {
            $total = round((float) ($line['resolved_total_amount'] ?? $line['total_amount'] ?? 0), 2);
            if ($total <= 0.004) {
                continue;
            }
            $qty = max(0.01, (float) ($line['qty'] ?? 1));
            $unit = round((float) ($line['resolved_unit_amount'] ?? $line['unit_amount'] ?? $total / $qty), 2);
            $label = trim((string) ($line['label'] ?? ''));
            $kind = strtolower((string) ($line['kind'] ?? ''));
            if ($kind === 'minibar') {
                $lines[] = $fbLine('Minibar — ' . ($label !== '' ? $label : 'item'), $qty, $unit, $total);
            } elseif ($kind === 'asset_penalty') {
                $lines[] = self::lineRow(
                    $sr++,
                    'Damaged / missing — ' . ($label !== '' ? $label : 'item'),
                    $damageSac,
                    $qtyFmt($qty),
                    $fmt($unit),
                    $fmt($total),
                    $fmt(0),
                    $fmt($total),
                    0.0,
                    0.0,
                    0.0,
                    0.0,
                    0.0,
                    0.0,
                    $fmt(0)
                );
            }
        }

        $orphanNonInspection = max(
            0.0,
            round($nonPosExtra - $inspectionTotals['total'], 2),
        );

        $laundryRows = BookingExtraCharge::query()
            ->where('booking_id', $booking->id)
            ->where('source', 'laundry')
            ->orderBy('id')
            ->get(['label', 'qty', 'unit_amount', 'total_amount']);
        if ($orphanNonInspection + 0.01 >= (float) $laundryRows->sum('total_amount')) {
            foreach ($laundryRows as $row) {
                $total = round((float) $row->total_amount, 2);
                if ($total <= 0.004) {
                    continue;
                }
                $label = trim((string) $row->label);
                $lines[] = $fbLine($label !== '' ? $label : 'Guest laundry', max(0.01, (float) $row->qty), round((float) $row->unit_amount, 2), $total);
                $orphanNonInspection = max(0.0, round($orphanNonInspection - $total, 2));
            }
        }

        if ($orphanNonInspection > 0.004) {
            $lines[] = $fbLine(
                $folioOrders->isEmpty() ? 'Posted to room (F&B / extras)' : 'Posted to room (extras)',
                1,
                $orphanNonInspection,
                $orphanNonInspection
            );
        }

        if ($checkoutDisc > 0.004) {
            $r = trim((string) ($booking->checkout_discount_reason ?? ''));
            $part = 'Checkout discount' . ($r !== '' ? ' — ' . Str::limit($r, 80, '') : '');
            $neg = number_format(-$checkoutDisc, 2, '.', '');
            $lines[] = [
                'sr' => (string) $sr++,
                'particular' => $part,
                'hsn' => '—',
                'qty' => '1',
                'rate' => $fmt($checkoutDisc),
                'total' => $neg,
                'discount' => $fmt($checkoutDisc),
                'taxable' => $fmt(0),
                'sgst_cell' => '—',
                'cgst_cell' => '—',
                'igst_cell' => '—',
                'cess' => $fmt(0),
                'sgst_amt' => $fmt(0),
                'cgst_amt' => $fmt(0),
                'igst_amt' => $fmt(0),
            ];
        }

        $st = 0.0;
        $sd = 0.0;
        $sx = 0.0;
        $ssgst = 0.0;
        $scgst = 0.0;
        $sigst = 0.0;
        $scess = 0.0;
        foreach ($lines as $row) {
            $st += (float) str_replace(',', '', $row['total']);
            $sd += (float) str_replace(',', '', $row['discount']);
            $sx += (float) str_replace(',', '', $row['taxable']);
            $ssgst += (float) str_replace(',', '', $row['sgst_amt']);
            $scgst += (float) str_replace(',', '', $row['cgst_amt']);
            $sigst += (float) str_replace(',', '', $row['igst_amt']);
            $scess += (float) str_replace(',', '', (string) ($row['cess'] ?? '0'));
        }
        $colTotals = [
            'total' => $fmt($st),
            'discount' => $fmt($sd),
            'taxable' => $fmt($sx),
            'sgst' => $fmt($ssgst),
            'cgst' => $fmt($scgst),
            'igst' => $fmt($sigst),
            'cess' => $fmt($scess),
        ];

        $taxGroups = [];
        foreach ($lines as $row) {
            foreach (['CGST' => 'cgst', 'SGST' => 'sgst', 'IGST' => 'igst'] as $label => $key) {
                $amt = (float) $row[$key . '_amt'];
                if ($amt <= 0.004) {
                    continue;
                }
                $pct = round((float) ($row[$key . '_pct'] ?? 0), 2);
                $groupKey = $label . '|' . $pct;
                $taxGroups[$groupKey] ??= ['order' => $label, 'pct' => $pct, 'label' => $label . ' @ ' . $pct . '%', 'taxable' => 0.0, 'tax' => 0.0];
                $taxGroups[$groupKey]['taxable'] += (float) $row['taxable'];
                $taxGroups[$groupKey]['tax'] += $amt;
            }
        }
        $typeOrder = ['CGST' => 0, 'SGST' => 1, 'IGST' => 2];
        usort($taxGroups, fn(array $a, array $b) => [$typeOrder[$a['order']], $a['pct']] <=> [$typeOrder[$b['order']], $b['pct']]);
        $taxDetailRows = array_map(
            fn(array $g) => ['label' => $g['label'], 'taxable' => $fmt($g['taxable']), 'tax' => $fmt($g['tax'])],
            $taxGroups
        );

        $paymentRows = [];
        $ledger = BookingPaymentLedger::totals($booking);
        if ($ledger['count'] > 0 && abs($ledger['net'] - $paid) < 0.01) {
            $ledgerRows = BookingPayment::query()
                ->where('booking_id', $booking->id)
                ->active()
                ->orderBy('paid_at')
                ->orderBy('id')
                ->get();
            foreach ($ledgerRows as $p) {
                $signed = match ($p->type) {
                    BookingPayment::TYPE_REFUND => -(float) $p->amount,
                    BookingPayment::TYPE_ADJUSTMENT => (float) ($p->meta['signed_amount'] ?? $p->amount),
                    default => (float) $p->amount,
                };
                $ref = trim((string) ($p->reference_no ?? ''));
                $paymentRows[] = [
                    'date' => $p->paid_at ? Carbon::parse($p->paid_at)->format('d/m/Y') : '—',
                    'description' => strtoupper((string) ($p->method ?: '—')) . ' — ' . ucfirst((string) $p->type)
                        . ($ref !== '' ? ' · Ref ' . $ref : ''),
                    'amount' => $fmt($signed),
                ];
            }
        } else {
            $deposit = (float) ($booking->deposit_amount ?? 0);
            $refunded = (float) ($booking->refund_amount ?? 0);
            $payDate = $booking->updated_at
                ? Carbon::parse($booking->updated_at)->format('d/m/Y')
                : Carbon::now()->format('d/m/Y');
            if ($deposit > 0.004) {
                $paymentRows[] = [
                    'date' => $payDate,
                    'description' => strtoupper((string) ($booking->payment_method ?? 'PAYMENT')) . ' — Advance / settlement · Ref #' . $booking->id,
                    'amount' => $fmt($deposit),
                ];
            }
            if ($refunded > 0.004) {
                $paymentRows[] = [
                    'date' => $payDate,
                    'description' => strtoupper((string) ($booking->refund_method ?? 'REFUND')) . ' — Refund',
                    'amount' => $fmt(-$refunded),
                ];
            }
        }
        $paymentTotalFmt = $fmt($paid);

        $amountInWords = ucfirst(MoneyToWords::inr(max(0.0, $grandRounded)));

        $defaults = Setting::getReceiptDefaults();
        $hotelName = Setting::get('invoice_company_name', 'Hotel');
        if ($hotelName === 'Hotel' && ! empty($defaults['address'])) {
            $first = explode("\n", (string) $defaults['address'])[0];
            $hotelName = trim($first) !== '' ? trim($first) : 'Hotel';
        }
        $hotelAddress = Setting::get('invoice_address', (string) ($defaults['address'] ?? ''));
        $hotelGstin = Setting::get('invoice_gstin', '');
        $bankCompanyName = Setting::get('invoice_bank_legal_name', $hotelName);
        $bankDetails = self::invoiceBankDetailLines();
        $sourceOfSupply = Setting::get('property_city', (string) ($booking->city ?? ''));

        $remark = trim((string) preg_replace('/\[[^\]]+\]/', '', (string) ($booking->notes ?? '')));
        if ($remark === '') {
            $remark = '—';
        }

        // Tax invoice number and date are issued at check-out; before that the document is a proforma.
        $isIssued = trim((string) ($booking->invoice_number ?? '')) !== '';
        $invoiceNo = $isIssued ? (string) $booking->invoice_number : 'Proforma';
        $folioNo = (string) $booking->id;
        $resNo = BookingNumber::for($booking);

        $invoiceDate = ($isIssued && $booking->invoice_issued_at ? Carbon::parse($booking->invoice_issued_at) : Carbon::now())
            ->format('d/m/Y h:i:s A');
        $arrivalStr = self::formatInvoiceStayInstant($checkInDisplay, $hourPackage);
        $departureStr = self::formatInvoiceStayInstant($checkOutDisplay, $hourPackage);

        $bookingSource = (string) ($booking->booking_source ?? '—');
        $taVoucher = (string) ($booking->source_reference ?? '—');
        if ($taVoucher === '') {
            $taVoucher = '—';
        }

        $billToAddress = trim(implode(', ', array_filter([
            (string) ($booking->city ?? ''),
            (string) ($booking->country ?? ''),
        ])));

        $billToNameRaw = trim((string) ($booking->bill_to_name ?? ''));
        $billToName = $billToNameRaw !== ''
            ? strtoupper($billToNameRaw)
            : $guestName;
        $guestGstin = strtoupper(trim((string) ($booking->guest_gstin ?? '')));

        $summaryLines = [
            ['label' => 'Total Charges(Rs)', 'value' => $fmt($st)],
            ['label' => 'Total Discount(Rs)', 'value' => $fmt($sd)],
            ['label' => 'Total SGST(Rs)', 'value' => $fmt($ssgst)],
            ['label' => 'Total CGST(Rs)', 'value' => $fmt($scgst)],
            ['label' => 'Total IGST(Rs)', 'value' => $fmt($sigst)],
            ['label' => 'Total Other Tax(Rs)', 'value' => $fmt(0)],
            ['label' => 'Total Balance Transfer(Rs)', 'value' => $fmt(0)],
            ['label' => 'Subtotal / Total(Rs)', 'value' => $fmt($grossBill), 'bold' => true],
            ['label' => 'Flat Discount(Rs)', 'value' => $fmt($checkoutDisc)],
        ];
        if (abs($roundOff) >= 0.0005) {
            $summaryLines[] = ['label' => 'Round Off(Rs)', 'value' => $fmt($roundOff)];
        }
        $summaryLines[] = ['label' => 'Adjustment(Rs)', 'value' => $fmt(0)];
        $summaryLines[] = ['label' => 'Total Payable(Rs)', 'value' => $fmt($grandRounded), 'bold' => true];
        $summaryLines[] = ['label' => 'Total Payment(Rs)', 'value' => $fmt($paid)];
        $summaryLines[] = ['label' => 'Balance(Rs)', 'value' => $fmt($balanceRounded), 'bold' => true];

        $cashierName = Auth::user()?->name ?? '—';
        $receptionName = $booking->creator?->name ?? '—';
        $footerDate = Carbon::now()->format('d/m/Y h:i:s A');

        return [
            'hotelName' => $hotelName,
            'hotelAddress' => $hotelAddress,
            'hotelGstin' => $hotelGstin,
            'documentTitle' => ! $isIssued ? 'Proforma Invoice' : (trim((string) $hotelGstin) !== '' ? 'Tax Invoice' : 'Invoice'),
            'isIssued' => $isIssued,
            'invoiceNo' => $invoiceNo,
            'folioNo' => $folioNo,
            'resNo' => $resNo,
            'guestName' => $guestName,
            'billToName' => $billToName,
            'billToAddress' => $billToAddress,
            'guestState' => trim((string) ($booking->city ?? '')) !== ''
                ? trim((string) $booking->city)
                : (string) ($booking->country ?? ''),
            'guestGstin' => $guestGstin,
            'bookingSource' => $bookingSource,
            'sourceOfSupply' => $sourceOfSupply,
            'grCardNo' => (string) $booking->id,
            'invoiceDate' => $invoiceDate,
            'roomLabel' => $roomLabel,
            'personsLabel' => $personsLabel,
            'rateTypeLabel' => $rateTypeLabel,
            'nights' => (string) $nights,
            'arrivalStr' => $arrivalStr,
            'departureStr' => $departureStr,
            'taVoucher' => $taVoucher,
            'lines' => $lines,
            'colTotals' => $colTotals,
            'amountInWords' => $amountInWords,
            'paymentRows' => $paymentRows,
            'paymentTotalFmt' => $paymentTotalFmt,
            'taxDetailRows' => $taxDetailRows,
            'summaryLines' => $summaryLines,
            'remark' => $remark,
            'currency' => 'Rs',
            'receptionName' => $receptionName,
            'cashierName' => $cashierName,
            'footerDate' => $footerDate,
            'bankCompanyName' => $bankCompanyName,
            'bankDetails' => $bankDetails,
        ];
    }

    /**
     * Day stays: show arrival clock when early check-in is recorded, ETA is before standard check-in,
     * or (late checkout is recorded and ETA carries a time — keeps invoice symmetric with departure).
     * Otherwise date-only when stored check-in is midnight.
     */
    private static function invoiceArrivalDisplayMoment(Carbon $raw, Booking $booking): Carbon
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return $raw->copy();
        }
        if ($raw->format('H:i:s') !== '00:00:00') {
            return $raw->copy();
        }

        $early = self::normalizeBookingTimeField($booking->early_checkin_time ?? null);
        if ($early !== '') {
            return self::mergeBookingClockOntoStayDate($raw, $early);
        }

        $eta = trim((string) ($booking->estimated_arrival_time ?? ''));
        if ($eta === '') {
            return $raw->copy()->startOfDay();
        }

        // Date-only ETA strings parse as midnight — do not treat as arrival clock.
        if (! preg_match('/\d{1,2}\s*:\s*\d{2}|am|pm/i', $eta)) {
            return $raw->copy()->startOfDay();
        }

        try {
            $etaCarbon = Carbon::parse($eta);
            $etaHi = $etaCarbon->format('H:i');
        } catch (\Throwable) {
            return $raw->copy()->startOfDay();
        }

        $standardRaw = (string) Setting::get('standard_check_in_time', '14:00');
        try {
            $standardHi = Carbon::parse($standardRaw)->format('H:i');
        } catch (\Throwable) {
            $standardHi = '14:00';
        }

        $hasLateCheckout = self::normalizeBookingTimeField($booking->late_checkout_time ?? null) !== '';

        // Before policy check-in → show (matches assignEarlyCheckinTimeFromEstimatedArrival intent).
        if ($etaHi < $standardHi) {
            return self::mergeBookingClockOntoStayDate($raw, $eta);
        }

        // Same as policy but departure shows negotiated late time — show requested arrival too.
        if ($hasLateCheckout) {
            return self::mergeBookingClockOntoStayDate($raw, $eta);
        }

        return $raw->copy()->startOfDay();
    }

    /** Day stays: show clock only when late checkout is recorded; else date-only if midnight. */
    private static function invoiceDepartureDisplayMoment(Carbon $raw, Booking $booking): Carbon
    {
        if (($booking->booking_unit ?? 'day') === 'hour_package') {
            return $raw->copy();
        }
        if ($raw->format('H:i:s') !== '00:00:00') {
            return $raw->copy();
        }
        $late = self::normalizeBookingTimeField($booking->late_checkout_time ?? null);
        if ($late === '') {
            return $raw->copy()->startOfDay();
        }

        return self::mergeBookingClockOntoStayDate($raw, $late);
    }

    private static function normalizeBookingTimeField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value instanceof \Carbon\CarbonInterface) {
            return $value->format('H:i:s');
        }
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value)->format('H:i:s');
        }

        return trim((string) $value);
    }

    /** Interpret booking time / datetime field as clock on the stay date for invoice lines. */
    private static function mergeBookingClockOntoStayDate(Carbon $raw, string $clockOrDatetime): Carbon
    {
        $clockOrDatetime = trim($clockOrDatetime);
        if ($clockOrDatetime === '') {
            return $raw->copy()->startOfDay();
        }
        try {
            $t = Carbon::parse($clockOrDatetime);
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $clockOrDatetime)) {
                return $t->copy();
            }

            return $raw->copy()->startOfDay()->setTime($t->hour, $t->minute, 0);
        } catch (\Throwable) {
            return $raw->copy()->startOfDay();
        }
    }

    /**
     * Labelled bank lines for invoice footer; falls back to legacy free-text invoice_bank_details when no structured fields are set.
     */
    private static function invoiceBankDetailLines(): string
    {
        $rows = [
            ['Bank name', trim((string) Setting::get('invoice_bank_name', ''))],
            ['Account no.', trim((string) Setting::get('invoice_bank_account_no', ''))],
            ['IFSC', trim((string) Setting::get('invoice_bank_ifsc', ''))],
            ['Branch', trim((string) Setting::get('invoice_bank_branch', ''))],
            ['SWIFT / BIC', trim((string) Setting::get('invoice_bank_swift', ''))],
        ];
        $lines = [];
        foreach ($rows as [$label, $value]) {
            if ($value !== '') {
                $lines[] = $label . ': ' . $value;
            }
        }
        $composed = implode("\n", $lines);

        return $composed !== '' ? $composed : trim((string) Setting::get('invoice_bank_details', ''));
    }

    private static function formatInvoiceStayInstant(Carbon $moment, bool $hourPackage): string
    {
        if ($hourPackage || $moment->format('H:i:s') !== '00:00:00') {
            return $moment->format('d/m/Y h:i:s A');
        }

        return $moment->format('d/m/Y');
    }

    private static function lineRow(
        int $sr,
        string $particular,
        string $hsn,
        string $qty,
        string $rate,
        string $total,
        string $discount,
        string $taxable,
        float $sgstPct,
        float $sgstAmt,
        float $cgstPct,
        float $cgstAmt,
        float $igstPct,
        float $igstAmt,
        string $cess
    ): array {
        $fmtN = static fn(float $n): string => number_format(round($n, 2), 2, '.', '');
        $sgstCell = $sgstAmt > 0.004
            ? number_format($sgstPct, 2) . '% / ' . $fmtN($sgstAmt)
            : '—';
        $cgstCell = $cgstAmt > 0.004
            ? number_format($cgstPct, 2) . '% / ' . $fmtN($cgstAmt)
            : '—';
        $igstCell = $igstAmt > 0.004
            ? number_format($igstPct, 2) . '% / ' . $fmtN($igstAmt)
            : '—';

        return [
            'sr' => (string) $sr,
            'particular' => $particular,
            'hsn' => $hsn,
            'qty' => $qty,
            'rate' => $rate,
            'total' => $total,
            'discount' => $discount,
            'taxable' => $taxable,
            'sgst_cell' => $sgstCell,
            'cgst_cell' => $cgstCell,
            'igst_cell' => $igstCell,
            'cess' => $cess,
            'sgst_amt' => $fmtN($sgstAmt),
            'cgst_amt' => $fmtN($cgstAmt),
            'igst_amt' => $fmtN($igstAmt),
            'sgst_pct' => $sgstPct,
            'cgst_pct' => $cgstPct,
            'igst_pct' => $igstPct,
        ];
    }
}
