<?php

namespace App\Services\Accounting;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\JournalEntry;
use App\Support\BookingNumber;
use App\Support\BookingPaymentLedger;
use Illuminate\Support\Facades\Schema;

/**
 * Journals a room refund recorded after check-out.
 *
 * The checkout journal books only the cash that covered the bill; an overpayment stays off the books,
 * so refunding it needs no entry. Any part of the refund beyond that unbooked cash gives back money the
 * checkout recognised as room revenue: Dr room revenue + output GST (extracted from the inclusive amount),
 * Cr the tender account it was paid out of. Group stays look at the whole group, as payments are pooled.
 */
final class BookingRefundPoster
{
    public const SOURCE_TYPE = 'booking_refund';

    public function __construct(
        private readonly JournalPostingService $journal,
        private readonly BookingCheckoutPoster $checkout,
    ) {}

    /** Part of an already recorded refund that reverses booked revenue (0 when it only returns an overpayment). */
    public function reversibleAmount(Booking $booking, BookingPayment $refund): float
    {
        $amount = round((float) $refund->amount, 2);
        if ($amount <= 0.004) {
            return 0.0;
        }

        $scope = $booking->booking_group_id
            ? Booking::query()->where('booking_group_id', $booking->booking_group_id)->where('status', '!=', 'cancelled')->get()
            : collect([$booking->fresh()]);

        $netAfter = 0.0;
        foreach ($scope as $member) {
            $netAfter += BookingPaymentLedger::totals($member)['net'];
        }

        $booked = BookingCheckoutPoster::bookedCash($scope->pluck('id')->map(fn ($id) => (int) $id)->all());
        $unbooked = max(0.0, round($netAfter + $amount - $booked, 2));

        return round(max(0.0, $amount - $unbooked), 2);
    }

    public function post(Booking $booking, BookingPayment $refund, ?int $postedBy = null): ?JournalEntry
    {
        if (! Schema::hasTable('journal_entries') || $refund->type !== BookingPayment::TYPE_REFUND) {
            return null;
        }

        $reverse = $this->reversibleAmount($booking, $refund);
        if ($reverse <= 0.004) {
            return null;
        }

        $booking->loadMissing(['room.roomType.tax']);
        [$roomNet, $cgst, $sgst] = $this->checkout->splitRoomTaxInclusive($booking, $reverse);
        $meta = ['booking_id' => (int) $booking->id, 'booking_payment_id' => (int) $refund->id];

        $lines = [
            ['account_code' => AccountCodes::ROOM_REVENUE, 'debit' => $roomNet, 'meta' => $meta],
        ];
        if ($cgst > 0) {
            $lines[] = ['account_code' => AccountCodes::OUTPUT_CGST, 'debit' => $cgst, 'tax_tag' => 'output_gst', 'meta' => $meta];
        }
        if ($sgst > 0) {
            $lines[] = ['account_code' => AccountCodes::OUTPUT_SGST, 'debit' => $sgst, 'tax_tag' => 'output_gst', 'meta' => $meta];
        }
        $lines[] = [
            'account_code' => AccountCodes::tenderAccount((string) $refund->method),
            'credit' => $reverse,
            'meta' => $meta + ['method' => $refund->method],
        ];

        $entryDate = ($refund->paid_at ?? now())->toDateString();

        return $this->journal->post(
            sourceType: self::SOURCE_TYPE,
            sourceId: (int) $refund->id,
            entryDate: $entryDate,
            businessDate: $entryDate,
            sourceRef: 'Booking '.BookingNumber::for($booking),
            memo: 'Room refund after checkout',
            lines: $lines,
            postedBy: $postedBy,
        );
    }
}
