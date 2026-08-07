<?php

namespace App\Services\Accounting;

use App\Models\Booking;
use App\Models\JournalEntry;
use App\Support\BookingInvoiceRoomStay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Posts room-stay revenue and clears folio AR when a booking checks out.
 *
 * Folio F&B (room_charge POS) is already recognized at POS settle (Dr AR / Cr sales).
 * This entry clears AR and recognizes room revenue + output GST on the room portion.
 *
 * {@see BookingInvoiceRoomStay::summarizeForInvoice} `room_inclusive_grand` is always
 * tax-inclusive (rate card may be ex-GST, but the stored/recomputed grand already
 * folds GST in). Split tax by extracting from that inclusive amount — never add GST again.
 */
final class BookingCheckoutPoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function post(Booking $booking, ?int $postedBy = null): ?JournalEntry
    {
        if (! Schema::hasTable('journal_entries')) {
            return null;
        }

        $booking->loadMissing(['room.roomType.tax']);

        $grand = $this->netGrand($booking);
        if ($grand <= 0) {
            return null;
        }

        $folioAr = min(round(BookingInvoiceRoomStay::sumPosRoomChargePayments($booking), 2), $grand);
        $roomInclusive = max(0.0, round($grand - $folioAr, 2));

        [$roomNet, $cgst, $sgst] = $this->splitRoomTaxInclusive($booking, $roomInclusive);

        $paid = round((float) ($booking->deposit_amount ?? 0), 2);
        $refunded = round((float) ($booking->refund_amount ?? 0), 2);
        $retained = round(max(0.0, $paid - $refunded), 2);

        // Apply only what covers the bill; overpayment stays on the booking until refunded.
        $appliedCash = round(min($retained, $grand), 2);
        $shortfall = round(max(0.0, $grand - $retained), 2);

        $lines = [];
        $tender = AccountCodes::tenderAccount((string) ($booking->payment_method ?? 'cash'));

        if ($appliedCash > 0) {
            $lines[] = [
                'account_code' => $tender,
                'debit' => $appliedCash,
                'meta' => ['booking_id' => $booking->id],
            ];
        }

        if ($shortfall > 0.01) {
            $lines[] = [
                'account_code' => AccountCodes::FOLIO_AR,
                'debit' => $shortfall,
                'meta' => ['booking_id' => $booking->id, 'kind' => 'checkout_shortfall'],
            ];
        }

        if ($folioAr > 0) {
            $lines[] = [
                'account_code' => AccountCodes::FOLIO_AR,
                'credit' => $folioAr,
                'meta' => ['booking_id' => $booking->id],
            ];
        }

        if ($roomNet > 0) {
            $lines[] = [
                'account_code' => AccountCodes::ROOM_REVENUE,
                'credit' => $roomNet,
                'meta' => ['booking_id' => $booking->id],
            ];
        }

        if ($cgst > 0) {
            $lines[] = [
                'account_code' => AccountCodes::OUTPUT_CGST,
                'credit' => $cgst,
                'tax_tag' => 'output_gst',
                'meta' => ['booking_id' => $booking->id],
            ];
        }

        if ($sgst > 0) {
            $lines[] = [
                'account_code' => AccountCodes::OUTPUT_SGST,
                'credit' => $sgst,
                'tax_tag' => 'output_gst',
                'meta' => ['booking_id' => $booking->id],
            ];
        }

        $entryDate = Carbon::parse($booking->check_out_at ?? $booking->check_out ?? now())->toDateString();

        return $this->journal->post(
            sourceType: 'booking_checkout',
            sourceId: (int) $booking->id,
            entryDate: $entryDate,
            businessDate: $entryDate,
            sourceRef: 'Booking #'.$booking->id,
            memo: 'Room checkout — revenue & folio settlement',
            lines: $lines,
            postedBy: $postedBy,
        );
    }

    private function netGrand(Booking $booking): float
    {
        $gross = BookingInvoiceRoomStay::summarizeForInvoice($booking)['gross_before_checkout_discount'];
        $discount = max(0.0, (float) ($booking->checkout_discount_amount ?? 0));

        return max(0.0, round($gross - min($discount, $gross), 2));
    }

    /**
     * Extract CGST/SGST from a tax-inclusive room amount.
     *
     * @return array{0: float, 1: float, 2: float} roomNet, cgst, sgst
     */
    private function splitRoomTaxInclusive(Booking $booking, float $roomInclusive): array
    {
        $taxRate = (float) ($booking->room?->roomType?->tax?->rate ?? 0);
        if ($roomInclusive <= 0 || $taxRate <= 0.004) {
            return [$roomInclusive, 0.0, 0.0];
        }

        $net = round($roomInclusive / (1 + ($taxRate / 100)), 2);
        $tax = round($roomInclusive - $net, 2);
        $half = round($tax / 2, 2);
        $other = round($tax - $half, 2);

        return [$net, $half, $other];
    }
}
