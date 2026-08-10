<?php

namespace Tests\Unit\Support;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Support\BookingPaymentLedger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingPaymentLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('booking_payments');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('refund_method')->nullable();
            $table->string('payment_status')->default('pending');
            $table->string('status')->default('confirmed');
            $table->decimal('total_price', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('type', 32);
            $table->decimal('amount', 12, 2);
            $table->string('method', 32)->nullable();
            $table->string('reference_no', 128)->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 64)->default('manual');
            $table->json('meta')->nullable();
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->timestamps();
        });
    }

    private function booking(): Booking
    {
        return Booking::query()->create([
            'deposit_amount' => 0,
            'refund_amount' => 0,
            'payment_status' => 'pending',
            'status' => 'confirmed',
            'total_price' => 10000,
        ]);
    }

    public function test_split_payments_sync_deposit_and_status(): void
    {
        $booking = $this->booking();

        BookingPaymentLedger::recordSplitPayments($booking, [
            ['amount' => 3000, 'method' => 'cash'],
            ['amount' => 2000, 'method' => 'upi'],
        ], 'deposit', 10000);

        $booking->refresh();
        $this->assertSame(5000.0, (float) $booking->deposit_amount);
        $this->assertSame('partial', $booking->payment_status);
        $this->assertSame('upi', $booking->payment_method);
        $this->assertCount(2, BookingPayment::query()->where('booking_id', $booking->id)->get());
    }

    public function test_refund_and_net_by_method(): void
    {
        $booking = $this->booking();
        BookingPaymentLedger::recordPayment($booking, [
            'amount' => 4000,
            'method' => 'card',
            'source' => 'deposit',
            'bill_total' => 4000,
        ]);
        BookingPaymentLedger::recordRefund($booking, [
            'amount' => 1000,
            'method' => 'card',
            'source' => 'checkout',
            'bill_total' => 3000,
        ]);

        $booking->refresh();
        $this->assertSame(4000.0, (float) $booking->deposit_amount);
        $this->assertSame(1000.0, (float) $booking->refund_amount);
        $this->assertSame(['card' => 3000.0], BookingPaymentLedger::netByMethod($booking));
    }

    public function test_void_reverses_deposit(): void
    {
        $booking = $this->booking();
        $row = BookingPaymentLedger::recordPayment($booking, [
            'amount' => 1500,
            'method' => 'cash',
            'source' => 'deposit',
            'bill_total' => 5000,
        ]);
        BookingPaymentLedger::voidPayment($row, 'Entered twice', 5000);
        $booking->refresh();
        $this->assertSame(0.0, (float) $booking->deposit_amount);
        $this->assertSame('pending', $booking->payment_status);
    }
}
