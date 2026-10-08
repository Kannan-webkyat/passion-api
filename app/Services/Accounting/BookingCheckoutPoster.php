<?php

namespace App\Services\Accounting;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\BookingInvoiceRoomStay;
use App\Support\BookingNumber;
use App\Support\BookingPaymentLedger;
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

    /**
     * @param  float|null  $cashAvailable  group checkout: share of the group's pooled cash for this room
     * @param  array<string, float>|null  $methodMix  tender split to apply that share by
     */
    public function post(Booking $booking, ?int $postedBy = null, ?float $cashAvailable = null, ?array $methodMix = null): ?JournalEntry
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

        if ($cashAvailable !== null) {
            $retained = round(max(0.0, $cashAvailable), 2);
        } else {
            $paid = round((float) ($booking->deposit_amount ?? 0), 2);
            $refunded = round((float) ($booking->refund_amount ?? 0), 2);
            $retained = round(max(0.0, $paid - $refunded), 2);
        }

        // Apply only what covers the bill; overpayment stays on the booking until refunded.
        $appliedCash = round(min($retained, $grand), 2);
        $shortfall = round(max(0.0, $grand - $retained), 2);

        $lines = [];

        // Prefer ledger split by tender method when available.
        $byMethod = $methodMix ?? BookingPaymentLedger::netByMethod($booking);
        if ($byMethod !== [] && $appliedCash > 0.004) {
            $methodTotal = array_sum($byMethod);
            $allocated = 0.0;
            $methods = array_keys($byMethod);
            $lastIdx = count($methods) - 1;
            foreach ($methods as $i => $method) {
                $share = $methodTotal > 0.004
                    ? round($appliedCash * ((float) $byMethod[$method] / $methodTotal), 2)
                    : 0.0;
                if ($i === $lastIdx) {
                    $share = round($appliedCash - $allocated, 2);
                }
                $allocated = round($allocated + $share, 2);
                if ($share <= 0.004) {
                    continue;
                }
                $lines[] = [
                    'account_code' => AccountCodes::tenderAccount($method),
                    'debit' => $share,
                    'meta' => ['booking_id' => $booking->id, 'method' => $method],
                ];
            }
        } elseif ($appliedCash > 0) {
            $tender = AccountCodes::tenderAccount((string) ($booking->payment_method ?? 'cash'));
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
            sourceRef: 'Booking '.BookingNumber::for($booking),
            memo: 'Room checkout — revenue & folio settlement',
            lines: $lines,
            postedBy: $postedBy,
        );
    }

    /**
     * Checkout journals for group rooms leaving together. Payments are pooled on the group, so each room
     * draws on what the group has paid minus the cash earlier group checkouts already booked, instead of
     * only its own deposit (which left the paying room overpaid and the others in folio AR).
     *
     * @param  iterable<Booking>  $departing
     */
    public function postGroupDeparture(int $groupId, iterable $departing, ?int $postedBy = null): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $members = Booking::query()
            ->where('booking_group_id', $groupId)
            ->where('status', '!=', 'cancelled')
            ->get();
        $memberIds = $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        $mix = [];
        $groupNet = 0.0;
        foreach ($members as $member) {
            $groupNet += BookingPaymentLedger::totals($member)['net'];
            foreach (BookingPaymentLedger::netByMethod($member) as $method => $amount) {
                $mix[$method] = round(($mix[$method] ?? 0) + (float) $amount, 2);
            }
        }
        $mix = array_filter($mix, fn ($amount) => $amount > 0.004);
        $pool = round(max(0.0, $groupNet - self::bookedCash($memberIds)), 2);

        foreach ($departing as $booking) {
            $booking->loadMissing(['room.roomType.tax']);
            $share = round(min($pool, $this->netGrand($booking)), 2);
            $this->post($booking, $postedBy, $share, $mix !== [] ? $mix : null);
            $pool = round(max(0.0, $pool - $share), 2);
        }
    }

    /**
     * Tender cash already in the books for these bookings: checkout debits minus refund journal credits.
     *
     * @param  list<int>  $bookingIds
     */
    public static function bookedCash(array $bookingIds): float
    {
        if ($bookingIds === [] || ! Schema::hasTable('journal_entries')) {
            return 0.0;
        }

        $tenderAccountIds = ChartOfAccount::query()
            ->whereIn('code', [AccountCodes::CASH, AccountCodes::BANK_CARD, AccountCodes::BANK_UPI])
            ->pluck('id');

        $sum = static fn (string $sourceType, array $sourceIds, string $side): float => (float) JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_entries.source_type', $sourceType)
            ->whereIn('journal_entries.source_id', $sourceIds)
            ->whereIn('journal_lines.account_id', $tenderAccountIds)
            ->sum("journal_lines.{$side}");

        $refundIds = BookingPayment::query()->whereIn('booking_id', $bookingIds)->pluck('id')->all();

        return round(
            $sum('booking_checkout', $bookingIds, 'debit')
            - ($refundIds === [] ? 0.0 : $sum(BookingRefundPoster::SOURCE_TYPE, $refundIds, 'credit')),
            2
        );
    }

    public function netGrand(Booking $booking): float
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
    public function splitRoomTaxInclusive(Booking $booking, float $roomInclusive): array
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
