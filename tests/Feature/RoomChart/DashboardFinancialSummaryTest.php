<?php

namespace Tests\Feature\RoomChart;

use App\Models\BookingPayment;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

class DashboardFinancialSummaryTest extends RoomChartTestCase
{
    private const FINANCE_PERMISSIONS = ['view-dashboard', 'view-dashboard-financials', 'reservation-view', 'reservation-edit'];

    private function summary(string $from, string $to)
    {
        return $this->getJson("/api/dashboard/financial-summary?from={$from}&to={$to}");
    }

    public function test_financial_summary_requires_financials_permission(): void
    {
        $this->actingWith(['view-dashboard', 'reservation-view']);

        $this->summary($this->day(0), $this->day(0))->assertForbidden();
    }

    public function test_admin_role_can_read_financial_summary_without_explicit_permission(): void
    {
        $user = $this->userWith([], 'Owner');
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        $this->summary($this->day(0), $this->day(0))->assertOk();
    }

    public function test_room_revenue_is_net_ledger_cash_by_payment_date(): void
    {
        $this->actingWith(self::FINANCE_PERMISSIONS);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2), [
            'status' => 'checked_in',
            'deposit_amount' => 5000,
        ]);

        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 1000, 'method' => 'cash'])->assertCreated();
        $this->postJson("/api/bookings/{$booking->id}/payments", ['amount' => 700, 'method' => 'card'])->assertCreated();
        $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'amount' => 200, 'method' => 'cash'])->assertCreated();
        $card = BookingPayment::query()->where('booking_id', $booking->id)->where('method', 'card')->firstOrFail();
        $this->postJson("/api/bookings/{$booking->id}/payments/{$card->id}/void", ['reason' => 'Duplicate'])->assertOk();

        BookingPayment::query()->create([
            'booking_id' => $booking->id,
            'type' => BookingPayment::TYPE_PAYMENT,
            'amount' => 300,
            'method' => 'cash',
            'paid_at' => now()->subDay(),
        ]);

        $this->summary($this->day(0), $this->day(0))
            ->assertOk()
            ->assertJsonPath('kpis.room_revenue.value', 800)
            ->assertJsonPath('breakdown.rooms.payments_count', 1);
    }

    public function test_adr_uses_checked_out_room_revenue_not_collections(): void
    {
        $this->actingWith(self::FINANCE_PERMISSIONS);
        $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), [
            'status' => 'checked_out',
            'check_out_at' => now()->subHour(),
        ]);

        $response = $this->summary($this->day(0), $this->day(0))->assertOk();

        $this->assertSame(0.0, (float) $response->json('kpis.room_revenue.value'));
        $this->assertEqualsWithDelta(2000, (float) $response->json('kpis.adr.value'), 0.01);
    }

    public function test_past_period_occupancy_counts_only_stays_that_checked_in(): void
    {
        $this->actingWith(self::FINANCE_PERMISSIONS);
        $this->makeBooking($this->makeRoom('101'), $this->day(-3), $this->day(-1), ['status' => 'checked_out']);
        $this->makeBooking($this->makeRoom('102'), $this->day(-3), $this->day(-1), ['status' => 'confirmed']);
        DB::table('bookings')->where('status', 'confirmed')->update(['status' => 'cancelled', 'cancellation_reason' => 'no_show']);
        DB::table('booking_segments')->where('status', 'confirmed')->update(['status' => 'cancelled']);

        $response = $this->summary($this->day(-3), $this->day(-1))->assertOk();

        $this->assertEqualsWithDelta(33.3, (float) $response->json('kpis.occupancy.pct'), 0.05);
        $this->assertSame(1, $response->json('kpis.occupancy.occupied'));
        $this->assertIsNumeric($response->json('kpis.occupancy.change_pts'));
    }
}
