<?php

namespace Tests\Unit\Support;

use App\Models\Booking;
use App\Models\Setting;
use App\Support\BookingCancellationPolicy;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingCancellationPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Cache::flush();
    }

    private function booking(array $attrs = []): Booking
    {
        $b = new Booking();
        $b->forceFill(array_merge([
            'id' => 1,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'check_in' => '2026-08-20',
            'check_out' => '2026-08-23',
            'check_in_at' => null,
            'booking_unit' => 'day',
            'total_price' => 9000,
            'deposit_amount' => 3000,
            'status' => 'confirmed',
            'payment_status' => 'partial',
            'payment_method' => 'cash',
        ], $attrs));

        return $b;
    }

    public function test_within_free_window_has_zero_fee(): void
    {
        Setting::set('cancellation_free_hours_before', '48');
        Setting::set('cancellation_fee_type', 'first_night');
        Setting::set('standard_check_in_time', '14:00');

        $booking = $this->booking();
        $asOf = Carbon::parse('2026-08-18 10:00:00'); // >48h before Aug 20 14:00
        $eval = BookingCancellationPolicy::evaluate($booking, $asOf);

        $this->assertTrue($eval['within_free_window']);
        $this->assertSame(0.0, $eval['policy_fee']);
    }

    public function test_outside_window_first_night_fee(): void
    {
        Setting::set('cancellation_free_hours_before', '24');
        Setting::set('cancellation_fee_type', 'first_night');
        Setting::set('standard_check_in_time', '14:00');

        $booking = $this->booking();
        $asOf = Carbon::parse('2026-08-20 10:00:00'); // within 24h of arrival
        $eval = BookingCancellationPolicy::evaluate($booking, $asOf);

        $this->assertFalse($eval['within_free_window']);
        $this->assertSame(3000.0, $eval['policy_fee']); // 9000 / 3 nights
    }

    public function test_settle_partial_refund_and_forfeit(): void
    {
        $s = BookingCancellationPolicy::settle(5000, 3000);

        $this->assertSame(3000.0, $s['forfeited_from_deposit']);
        $this->assertSame(2000.0, $s['refund_due']);
        $this->assertSame(0.0, $s['balance_due']);
        $this->assertSame('refunded', $s['payment_status_after']);
    }

    public function test_settle_balance_due_when_deposit_short(): void
    {
        $s = BookingCancellationPolicy::settle(1000, 3000);

        $this->assertSame(1000.0, $s['forfeited_from_deposit']);
        $this->assertSame(0.0, $s['refund_due']);
        $this->assertSame(2000.0, $s['balance_due']);
        $this->assertSame('paid', $s['payment_status_after']);
    }

    public function test_preview_waive_fee_refunds_full_deposit(): void
    {
        Setting::set('cancellation_free_hours_before', '24');
        Setting::set('cancellation_fee_type', 'first_night');
        Setting::set('standard_check_in_time', '14:00');

        $booking = $this->booking(['deposit_amount' => 2500]);
        $preview = BookingCancellationPolicy::preview($booking, null, true, 0);

        $this->assertSame(0.0, $preview['effective_fee']);
        $this->assertSame(2500.0, $preview['refund_due']);
        $this->assertSame('refunded', $preview['payment_status_after']);
    }

    public function test_percent_fee_type(): void
    {
        Setting::set('cancellation_free_hours_before', '0');
        Setting::set('cancellation_fee_type', 'percent');
        Setting::set('cancellation_fee_value', '50');
        Setting::set('standard_check_in_time', '14:00');

        $booking = $this->booking();
        $eval = BookingCancellationPolicy::evaluate($booking, Carbon::parse('2026-08-19 12:00:00'));

        $this->assertSame(4500.0, $eval['policy_fee']);
    }
}
