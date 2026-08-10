<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Line-item cashiering ledger for guest reservations.
 * Keeps bookings.deposit_amount / refund_amount / payment_method / payment_status in sync.
 */
final class BookingPaymentLedger
{
    public const METHODS = ['cash', 'card', 'upi', 'bank_transfer'];

    public static function enabled(): bool
    {
        return Schema::hasTable('booking_payments');
    }

    /**
     * @param  array{
     *   amount: float|int|string,
     *   method?: string|null,
     *   reference_no?: string|null,
     *   notes?: string|null,
     *   source?: string,
     *   meta?: array<string, mixed>|null,
     *   paid_at?: Carbon|string|null,
     *   received_by?: int|null,
     *   bill_total?: float|null
     * }  $attrs
     */
    public static function recordPayment(Booking $booking, array $attrs): BookingPayment
    {
        return self::record($booking, BookingPayment::TYPE_PAYMENT, $attrs);
    }

    /**
     * @param  array{
     *   amount: float|int|string,
     *   method?: string|null,
     *   reference_no?: string|null,
     *   notes?: string|null,
     *   source?: string,
     *   meta?: array<string, mixed>|null,
     *   paid_at?: Carbon|string|null,
     *   received_by?: int|null,
     *   bill_total?: float|null
     * }  $attrs
     */
    public static function recordRefund(Booking $booking, array $attrs): BookingPayment
    {
        return self::record($booking, BookingPayment::TYPE_REFUND, $attrs);
    }

    /**
     * @param  array{
     *   amount: float|int|string,
     *   signed_amount?: float|null,
     *   method?: string|null,
     *   reference_no?: string|null,
     *   notes?: string|null,
     *   source?: string,
     *   meta?: array<string, mixed>|null,
     *   paid_at?: Carbon|string|null,
     *   received_by?: int|null,
     *   bill_total?: float|null
     * }  $attrs
     */
    public static function recordAdjustment(Booking $booking, array $attrs): BookingPayment
    {
        $signed = array_key_exists('signed_amount', $attrs)
            ? (float) $attrs['signed_amount']
            : (float) $attrs['amount'];
        $attrs['amount'] = abs($signed);
        $meta = is_array($attrs['meta'] ?? null) ? $attrs['meta'] : [];
        $meta['signed_amount'] = round($signed, 2);
        $attrs['meta'] = $meta;

        return self::record($booking, BookingPayment::TYPE_ADJUSTMENT, $attrs);
    }

    /**
     * Record multiple tender lines in one transaction (split payment).
     *
     * @param  list<array{amount: float|int|string, method: string, reference_no?: string|null, notes?: string|null}>  $tenders
     * @return list<BookingPayment>
     */
    public static function recordSplitPayments(
        Booking $booking,
        array $tenders,
        string $source = 'deposit',
        ?float $billTotal = null,
    ): array {
        if ($tenders === []) {
            throw ValidationException::withMessages(['tenders' => 'Add at least one payment line.']);
        }

        return DB::transaction(function () use ($booking, $tenders, $source, $billTotal) {
            $rows = [];
            foreach ($tenders as $i => $t) {
                $amt = round((float) ($t['amount'] ?? 0), 2);
                if ($amt <= 0.004) {
                    throw ValidationException::withMessages([
                        "tenders.$i.amount" => 'Each tender must be greater than zero.',
                    ]);
                }
                $method = strtolower(trim((string) ($t['method'] ?? '')));
                if (! in_array($method, self::METHODS, true)) {
                    throw ValidationException::withMessages([
                        "tenders.$i.method" => 'Select a valid payment method.',
                    ]);
                }
                $rows[] = self::recordPayment($booking, [
                    'amount' => $amt,
                    'method' => $method,
                    'reference_no' => $t['reference_no'] ?? null,
                    'notes' => $t['notes'] ?? null,
                    'source' => $source,
                    'bill_total' => $billTotal,
                    'meta' => ['split' => true, 'split_index' => $i],
                ]);
            }

            return $rows;
        });
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public static function record(Booking $booking, string $type, array $attrs): BookingPayment
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['payment' => 'Payment ledger is not available.']);
        }

        if (! in_array($type, [
            BookingPayment::TYPE_PAYMENT,
            BookingPayment::TYPE_REFUND,
            BookingPayment::TYPE_ADJUSTMENT,
        ], true)) {
            throw ValidationException::withMessages(['type' => 'Invalid payment type.']);
        }

        $amount = round(abs((float) ($attrs['amount'] ?? 0)), 2);
        if ($amount <= 0.004) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        $method = isset($attrs['method']) && $attrs['method'] !== null && $attrs['method'] !== ''
            ? strtolower(trim((string) $attrs['method']))
            : null;
        if ($type !== BookingPayment::TYPE_ADJUSTMENT) {
            if ($method === null || ! in_array($method, self::METHODS, true)) {
                throw ValidationException::withMessages(['method' => 'Select cash, card, UPI, or bank transfer.']);
            }
        } elseif ($method !== null && ! in_array($method, self::METHODS, true)) {
            throw ValidationException::withMessages(['method' => 'Invalid payment method.']);
        }

        if (in_array($booking->status, ['cancelled', 'checked_out'], true)
            && ($attrs['source'] ?? '') !== 'cancellation'
            && ($attrs['allow_closed'] ?? false) !== true
        ) {
            // Allow refunds at checkout finalize (status still checked_in when recording),
            // and cancellation source while status may already be flipping.
            if ($booking->status === 'cancelled' && ($attrs['source'] ?? '') !== 'cancellation') {
                throw ValidationException::withMessages(['booking' => 'Cannot post payments on a cancelled reservation.']);
            }
            if ($booking->status === 'checked_out' && $type === BookingPayment::TYPE_PAYMENT) {
                throw ValidationException::withMessages(['booking' => 'Cannot collect payments after check-out.']);
            }
        }

        $paidAt = $attrs['paid_at'] ?? now();
        if (! $paidAt instanceof Carbon) {
            $paidAt = Carbon::parse((string) $paidAt);
        }

        $billTotal = array_key_exists('bill_total', $attrs) && $attrs['bill_total'] !== null
            ? (float) $attrs['bill_total']
            : null;

        return DB::transaction(function () use ($booking, $type, $attrs, $amount, $method, $paidAt, $billTotal) {
            $row = BookingPayment::create([
                'booking_id' => $booking->id,
                'type' => $type,
                'amount' => $amount,
                'method' => $method,
                'reference_no' => isset($attrs['reference_no']) && trim((string) $attrs['reference_no']) !== ''
                    ? trim((string) $attrs['reference_no'])
                    : null,
                'notes' => isset($attrs['notes']) && trim((string) $attrs['notes']) !== ''
                    ? trim((string) $attrs['notes'])
                    : null,
                'source' => (string) ($attrs['source'] ?? 'manual'),
                'meta' => is_array($attrs['meta'] ?? null) ? $attrs['meta'] : null,
                'paid_at' => $paidAt,
                'received_by' => $attrs['received_by'] ?? Auth::id(),
            ]);

            self::syncScalars($booking->fresh(), $billTotal);

            return $row->fresh(['receiver:id,name']);
        });
    }

    public static function voidPayment(
        BookingPayment $payment,
        ?string $reason = null,
        ?float $billTotal = null,
    ): BookingPayment {
        if ($payment->voided_at) {
            throw ValidationException::withMessages(['payment' => 'This payment is already voided.']);
        }

        return DB::transaction(function () use ($payment, $reason, $billTotal) {
            $payment->update([
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason ? trim($reason) : null,
            ]);
            self::syncScalars($payment->booking()->firstOrFail(), $billTotal);

            return $payment->fresh(['receiver:id,name']);
        });
    }

    public static function syncScalars(Booking $booking, ?float $billTotal = null): void
    {
        if (! self::enabled()) {
            return;
        }

        $rows = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->active()
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();

        $paid = 0.0;
        $refunded = 0.0;
        $adjustSigned = 0.0;
        $lastPaymentMethod = null;

        foreach ($rows as $row) {
            if ($row->type === BookingPayment::TYPE_PAYMENT) {
                $paid += (float) $row->amount;
                if ($row->method) {
                    $lastPaymentMethod = $row->method;
                }
            } elseif ($row->type === BookingPayment::TYPE_REFUND) {
                $refunded += (float) $row->amount;
            } elseif ($row->type === BookingPayment::TYPE_ADJUSTMENT) {
                $signed = (float) ($row->meta['signed_amount'] ?? $row->amount);
                $adjustSigned += $signed;
                if ($signed > 0.004) {
                    $paid += $signed;
                    if ($row->method) {
                        $lastPaymentMethod = $row->method;
                    }
                } elseif ($signed < -0.004) {
                    // Negative adjustment reduces held cash (correction), not a guest refund.
                    $paid = max(0.0, $paid + $signed);
                }
            }
        }

        $paid = round(max(0.0, $paid), 2);
        $refunded = round(max(0.0, $refunded), 2);
        $net = round(max(0.0, $paid - $refunded), 2);

        $paymentStatus = (string) ($booking->payment_status ?? 'pending');
        if ($billTotal !== null) {
            $bill = max(0.0, round($billTotal, 2));
            if ($refunded > 0.004 && $net <= 0.004) {
                $paymentStatus = 'refunded';
            } elseif ($net >= $bill - 0.004 && $bill > 0.004) {
                $paymentStatus = 'paid';
            } elseif ($net > 0.004) {
                $paymentStatus = 'partial';
            } else {
                $paymentStatus = 'pending';
            }
        } elseif ($refunded > 0.004 && $net <= 0.004 && $paid > 0.004) {
            $paymentStatus = 'refunded';
        } elseif ($paid > 0.004 && in_array($paymentStatus, ['pending', ''], true)) {
            $paymentStatus = 'partial';
        }

        $lastRefund = $rows->reverse()->first(fn (BookingPayment $r) => $r->type === BookingPayment::TYPE_REFUND);

        $booking->forceFill([
            'deposit_amount' => $paid,
            'refund_amount' => $refunded,
            'payment_method' => $lastPaymentMethod ?? $booking->payment_method,
            'refund_method' => $lastRefund?->method ?? $booking->refund_method,
            'payment_status' => $paymentStatus,
        ])->save();
    }

    /**
     * Totals for API / UI.
     *
     * @return array{paid: float, refunded: float, net: float, count: int}
     */
    public static function totals(Booking $booking): array
    {
        if (! self::enabled()) {
            $paid = round((float) ($booking->deposit_amount ?? 0), 2);
            $refunded = round((float) ($booking->refund_amount ?? 0), 2);

            return [
                'paid' => $paid,
                'refunded' => $refunded,
                'net' => round(max(0.0, $paid - $refunded), 2),
                'count' => 0,
            ];
        }

        $rows = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->active()
            ->get();

        $paid = 0.0;
        $refunded = 0.0;
        foreach ($rows as $row) {
            if ($row->type === BookingPayment::TYPE_PAYMENT) {
                $paid += (float) $row->amount;
            } elseif ($row->type === BookingPayment::TYPE_REFUND) {
                $refunded += (float) $row->amount;
            } elseif ($row->type === BookingPayment::TYPE_ADJUSTMENT) {
                $signed = (float) ($row->meta['signed_amount'] ?? $row->amount);
                if ($signed > 0) {
                    $paid += $signed;
                } else {
                    $paid = max(0.0, $paid + $signed);
                }
            }
        }

        $paid = round($paid, 2);
        $refunded = round($refunded, 2);

        return [
            'paid' => $paid,
            'refunded' => $refunded,
            'net' => round(max(0.0, $paid - $refunded), 2),
            'count' => $rows->count(),
        ];
    }

    /**
     * Cash applied by tender method (for checkout journals).
     *
     * @return array<string, float> method => net amount
     */
    public static function netByMethod(Booking $booking): array
    {
        if (! self::enabled()) {
            $net = max(0.0, round((float) ($booking->deposit_amount ?? 0) - (float) ($booking->refund_amount ?? 0), 2));
            if ($net <= 0.004) {
                return [];
            }
            $method = (string) ($booking->payment_method ?: 'cash');

            return [$method => $net];
        }

        $by = [];
        $rows = BookingPayment::query()
            ->where('booking_id', $booking->id)
            ->active()
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $method = (string) ($row->method ?: 'cash');
            if ($row->type === BookingPayment::TYPE_PAYMENT) {
                $by[$method] = ($by[$method] ?? 0) + (float) $row->amount;
            } elseif ($row->type === BookingPayment::TYPE_REFUND) {
                $by[$method] = ($by[$method] ?? 0) - (float) $row->amount;
            } elseif ($row->type === BookingPayment::TYPE_ADJUSTMENT) {
                $signed = (float) ($row->meta['signed_amount'] ?? $row->amount);
                $by[$method] = ($by[$method] ?? 0) + $signed;
            }
        }

        $out = [];
        foreach ($by as $m => $amt) {
            $rounded = round((float) $amt, 2);
            if ($rounded > 0.004) {
                $out[$m] = $rounded;
            }
        }

        return $out;
    }
}
