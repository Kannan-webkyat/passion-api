<?php

namespace Tests\Feature\RoomChart;

use App\Models\BookingPayment;
use Illuminate\Support\Facades\DB;

class RoomChartPaymentsTest extends RoomChartTestCase
{
    public function test_record_payment_updates_booking_scalars(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 1000, 'method' => 'cash', 'reference_no' => 'R-1'])
            ->assertCreated()
            ->assertJsonPath('message', 'Payment recorded.')
            ->assertJsonPath('totals.paid', 1000)
            ->assertJsonPath('payment.receiver.name', 'Front Desk');

        $booking->refresh();
        $this->assertSame(1000.0, (float) $booking->deposit_amount);
        $this->assertSame('partial', $booking->payment_status);
        $this->assertSame('cash', $booking->payment_method);
    }

    public function test_full_payment_marks_booking_paid(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 4480, 'method' => 'card'])->assertCreated();

        $this->assertSame('paid', $booking->fresh()->payment_status);
    }

    public function test_split_tender_payment_records_each_line(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/payments", [
            'tenders' => [
                ['amount' => 3000, 'method' => 'card'],
                ['amount' => 1480, 'method' => 'upi'],
            ],
        ])->assertCreated()->assertJsonCount(2, 'payments')->assertJsonPath('totals.paid', 4480);

        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'tenders' => [['amount' => 10, 'method' => 'cash']]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Split tenders are only supported for payments.');
    }

    public function test_refund_and_void_adjust_totals(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 2000, 'method' => 'cash'])->assertCreated();

        $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'amount' => 500, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('totals.net', 1500);

        $payment = BookingPayment::query()->where('booking_id', $booking->id)->where('type', 'payment')->firstOrFail();
        $this->postJson("/api/bookings/{$booking->id}/payments/{$payment->id}/void", ['reason' => 'Wrong amount'])
            ->assertOk()
            ->assertJsonPath('totals.paid', 0)
            ->assertJsonPath('payment.void_reason', 'Wrong amount');

        $this->postJson("/api/bookings/{$booking->id}/payments/{$payment->id}/void")
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment');
    }

    public function test_void_rejects_payment_from_another_booking(): void
    {
        $this->actingWith(['reservation-edit']);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $b = $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$b->id}/payments", ['amount' => 100, 'method' => 'cash'])->assertCreated();
        $payment = BookingPayment::query()->where('booking_id', $b->id)->firstOrFail();

        $this->postJson("/api/bookings/{$a->id}/payments/{$payment->id}/void")
            ->assertNotFound()
            ->assertJsonPath('message', 'Payment does not belong to this booking.');
    }

    public function test_payment_input_and_status_guards(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 100])
            ->assertStatus(422)->assertJsonPath('message', 'Payment method is required.');
        $this->postJson("/api/bookings/{$booking->id}/payments", ['method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', 'Amount is required.');
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 100, 'method' => 'cheque'])
            ->assertStatus(422)->assertJsonValidationErrors('method');

        $cancelled = $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(2), ['status' => 'cancelled']);
        $this->postJson("/api/bookings/{$cancelled->id}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', 'Cannot post payments on a cancelled reservation.');

        $departed = $this->makeBooking($this->makeRoom('103'), $this->day(-2), $this->day(0), ['status' => 'checked_out', 'deposit_amount' => 5000]);
        $this->postJson("/api/bookings/{$departed->id}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('message', 'Cannot collect payments after check-out.');
    }

    public function test_refund_allowed_after_checkout(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 5000, 'method' => 'cash'])->assertCreated();
        $booking->update(['status' => 'checked_out']);

        $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'amount' => 520, 'method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('totals.net', 4480);

        $this->postJson("/api/bookings/{$booking->id}/payments/1/void")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot void payments after check-out or cancellation.');
    }

    /**
     * Nothing stops a refund larger than the net amount collected; totals clamp net to 0 so the
     * extra cash out disappears from the folio.
     */
    public function test_refund_cannot_exceed_net_amount_paid(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();

        $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'amount' => 5000, 'method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_list_payments_returns_rows_totals_and_by_method(): void
    {
        $this->actingWith(['reservation-view', 'reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 500, 'method' => 'upi'])->assertCreated();

        $this->getJson("/api/bookings/{$booking->id}/payments")
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('totals.paid', 1500)
            ->assertJsonPath('by_method.cash', 1000)
            ->assertJsonPath('by_method.upi', 500);
    }

    public function test_folio_postings_include_pos_room_charges_and_ledger_lines(): void
    {
        $this->actingWith(['reservation-view']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(1), ['status' => 'checked_in', 'extra_charges' => 250]);
        $outletId = DB::table('restaurant_masters')->insertGetId(['name' => 'Rooftop', 'created_at' => now(), 'updated_at' => now()]);
        $orderId = DB::table('pos_orders')->insertGetId([
            'order_type' => 'room_service', 'room_id' => $booking->room_id, 'booking_id' => $booking->id,
            'restaurant_id' => $outletId, 'status' => 'paid', 'total_amount' => 500, 'cgst_amount' => 12.5, 'sgst_amount' => 12.5,
            'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_payments')->insert(['order_id' => $orderId, 'method' => 'room_charge', 'amount' => 500, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('booking_extra_charges')->insert([
            'booking_id' => $booking->id, 'source' => 'front_desk', 'kind' => 'service', 'label' => 'Laundry',
            'qty' => 1, 'unit_amount' => 250, 'total_amount' => 250, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/bookings/{$booking->id}/folio-postings")
            ->assertOk()
            ->assertJsonPath('extra_charges_total', 250)
            ->assertJsonPath('items.0.outlet', 'Rooftop')
            ->assertJsonPath('items.0.amount', 500)
            ->assertJsonPath('folio_tax.cgst', 12.5)
            ->assertJsonPath('ledger_items.0.label', 'Laundry')
            ->assertJsonPath('ledger_items.0.amount', 250);
    }

    public function test_inspection_charges_lists_inspection_lines(): void
    {
        $this->actingWith(['reservation-view']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        DB::table('booking_extra_charges')->insert([
            'booking_id' => $booking->id, 'source' => 'inspection', 'kind' => 'minibar', 'label' => 'Soda',
            'qty' => 2, 'unit_amount' => 60, 'total_amount' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/bookings/{$booking->id}/inspection-charges")
            ->assertOk()
            ->assertJsonPath('booking_id', $booking->id)
            ->assertJsonCount(1, 'lines')
            ->assertJsonPath('lines.0.label', 'Soda');
    }

    public function test_payment_endpoints_require_permissions(): void
    {
        $this->actingWith([]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));

        $this->getJson("/api/bookings/{$booking->id}/payments")->assertForbidden();
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 100, 'method' => 'cash'])->assertForbidden();
        $this->getJson("/api/bookings/{$booking->id}/folio-postings")->assertForbidden();
        $this->getJson("/api/bookings/{$booking->id}/inspection-charges")->assertForbidden();
    }
}
