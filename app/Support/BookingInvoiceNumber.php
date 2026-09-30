<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Sequential tax-invoice number (e.g. INV-000042) issued once, at check-out. The number and issue
 * time are frozen on the booking; the invoice prefix setting only affects numbers issued after it changes.
 */
final class BookingInvoiceNumber
{
    public static function issue(Booking $booking): void
    {
        if ($booking->invoice_seq !== null) {
            return;
        }

        $prefix = trim((string) Setting::get('invoice_prefix', 'INV')) ?: 'INV';

        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($booking, $prefix) {
                    $seq = (int) Booking::query()->lockForUpdate()->max('invoice_seq') + 1;
                    $booking->forceFill([
                        'invoice_seq' => $seq,
                        'invoice_number' => $prefix.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT),
                        'invoice_issued_at' => now(),
                    ])->save();
                });

                return;
            } catch (QueryException $e) {
                // Unique invoice_seq collision with a concurrent checkout: take the next number.
                $booking->forceFill(['invoice_seq' => null, 'invoice_number' => null, 'invoice_issued_at' => null]);
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }
}
