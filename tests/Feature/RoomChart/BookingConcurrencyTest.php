<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Support\BookingPaymentLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Another desk changes the row after this request loaded it (route binding) but before it writes.
 */
class BookingConcurrencyTest extends RoomChartTestCase
{
    /**
     * @param  class-string  $model
     * @param  array<string, mixed>  $changes
     */
    private function changeRowAfterFirstLoad(string $model, int $id, array $changes): void
    {
        $done = false;
        Event::listen("eloquent.retrieved: {$model}", function ($loaded) use (&$done, $model, $id, $changes) {
            if ($done || (int) $loaded->getKey() !== $id) {
                return;
            }
            $done = true;
            DB::table((new $model)->getTable())->where('id', $id)->update($changes);
        });
    }

    private function pay(Booking $booking, float $amount): BookingPayment
    {
        return BookingPaymentLedger::recordPayment($booking, [
            'amount' => $amount,
            'method' => 'cash',
            'source' => 'deposit',
            'bill_total' => (float) $booking->total_price,
        ]);
    }

    public function test_cancel_refuses_when_money_changed_after_load(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(5), $this->day(7));
        $this->changeRowAfterFirstLoad(Booking::class, $booking->id, ['deposit_amount' => 1000]);

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'guest_request'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This reservation was just changed by another action. Reload it and try again.');

        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_checkout_refuses_when_status_changed_after_load(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 4480);
        $this->completeCheckoutInspection($booking);
        $booking->refresh();
        $this->changeRowAfterFirstLoad(Booking::class, $booking->id, ['status' => 'checked_out']);

        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertStatus(422);

        $this->assertNull($booking->fresh()->invoice_seq);
        $this->assertDatabaseMissing('room_status_blocks', ['room_id' => $booking->room_id, 'status' => 'dirty']);
    }

    public function test_late_checkout_fee_is_not_lost_when_charges_changed_after_load(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->changeRowAfterFirstLoad(Booking::class, $booking->id, ['extra_charges' => 750]);

        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '15:00'])->assertStatus(422);

        $this->assertSame(750.0, (float) $booking->fresh()->extra_charges);
        $this->assertNull($booking->fresh()->late_checkout_time);
    }

    public function test_extend_refuses_when_dates_changed_after_load(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->changeRowAfterFirstLoad(Booking::class, $booking->id, ['check_out' => $this->day(3)]);

        $this->postJson("/api/bookings/{$booking->id}/extend", ['new_check_out' => $this->day(4)])->assertStatus(422);

        $this->assertSame(4480.0, (float) $booking->fresh()->total_price);
    }

    public function test_payment_refused_when_booking_cancelled_after_load(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $this->changeRowAfterFirstLoad(Booking::class, $booking->id, ['status' => 'cancelled']);

        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 500, 'method' => 'cash'])->assertStatus(422);

        $this->assertDatabaseMissing('booking_payments', ['booking_id' => $booking->id]);
    }

    public function test_second_void_of_the_same_payment_is_refused(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $payment = $this->pay($booking, 500);
        $this->changeRowAfterFirstLoad(BookingPayment::class, $payment->id, ['voided_at' => now()]);

        $this->postJson("/api/bookings/{$booking->id}/payments/{$payment->id}/void", ['reason' => 'Duplicate'])
            ->assertStatus(422);
    }

    public function test_transfer_refuses_when_booking_moved_after_load(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(3));
        $other = $this->makeRoom('103');
        $this->changeRowAfterFirstLoad(Booking::class, $booking->id, ['room_id' => $other->id]);

        $this->postJson("/api/bookings/{$booking->id}/room-transfer", [
            'new_room_id' => $this->makeRoom('102')->id,
            'transfer_reason' => 'guest_request',
            'rate_mode' => 'keep_existing',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'This reservation was just changed by another action. Reload it and try again.');

        $this->assertDatabaseMissing('booking_room_transfers', ['booking_id' => $booking->id]);
    }
}
