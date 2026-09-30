<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Support\BookingNumber;
use App\Support\BookingPaymentLedger;
use Carbon\Carbon;

/**
 * Bookings page: paginated list (filters, sort, stats), list actions (preview-checkout, no-show)
 * and the unpaid-at-checkout report.
 */
class BookingsListActionsTest extends RoomChartTestCase
{
    /** @var array<string, Booking> */
    private array $b = [];

    /**
     * Nine bookings around TODAY covering every list bucket. Day stays are ₹2,240/night incl. GST.
     */
    private function seedBoard(): void
    {
        $suite = $this->makeRoomType(['name' => 'Suite']);
        $suitePlan = $this->makeRatePlan($suite, ['name' => 'Suite CP']);

        $this->b['in_house'] = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(1), [
            'status' => 'checked_in', 'first_name' => 'Hari',
        ]);
        $this->b['departing'] = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), [
            'status' => 'checked_in', 'first_name' => 'Devi', 'deposit_amount' => 4480,
        ]);
        $this->b['arriving'] = $this->makeBooking($this->makeRoom('9'), $this->day(0), $this->day(2), [
            'first_name' => 'Arun', 'booking_source' => 'Booking.com',
        ]);
        $this->b['future'] = $this->makeBooking($this->makeRoom('10', $suite), $this->day(5), $this->day(6), [
            'first_name' => 'Zara', 'rate_plan_id' => $suitePlan->id,
        ]);
        $this->b['no_show'] = $this->makeBooking($this->makeRoom('201'), $this->day(-1), $this->day(1), [
            'first_name' => 'Ben',
        ]);
        $this->b['settled'] = $this->makeBooking($this->makeRoom('202'), $this->day(-5), $this->day(-3), [
            'status' => 'checked_out', 'first_name' => 'Carl', 'deposit_amount' => 4480,
        ]);
        $this->b['unpaid_out'] = $this->makeBooking($this->makeRoom('203'), $this->day(-4), $this->day(-2), [
            'status' => 'checked_out', 'first_name' => 'Maya', 'deposit_amount' => 4000,
        ]);
        $this->b['cancelled'] = $this->makeBooking($this->makeRoom('204'), $this->day(3), $this->day(4), [
            'status' => 'cancelled', 'first_name' => 'Ivan',
        ]);
        $this->b['early_in'] = $this->makeBooking($this->makeRoom('205'), $this->day(2), $this->day(3), [
            'status' => 'checked_in', 'first_name' => 'Lena',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<int>
     */
    private function listIds(array $params): array
    {
        $res = $this->getJson('/api/bookings?' . http_build_query(['page' => 1, 'per_page' => 50] + $params))->assertOk();

        return array_map('intval', array_column($res->json('data'), 'id'));
    }

    /** @param  list<string>  $keys */
    private function ids(array $keys): array
    {
        return array_map(fn ($k) => (int) $this->b[$k]->id, $keys);
    }

    private function pay(Booking $booking, float $amount): void
    {
        BookingPaymentLedger::recordPayment($booking, [
            'amount' => $amount,
            'method' => 'cash',
            'source' => 'deposit',
            'bill_total' => (float) $booking->total_price,
        ]);
    }

    // ── GET /bookings ──────────────────────────────────────────────────────

    public function test_without_page_param_the_legacy_plain_array_is_returned(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();
        $group = BookingGroup::query()->create(['name' => 'Team', 'status' => 'confirmed']);
        $this->b['arriving']->update(['booking_group_id' => $group->id]);

        $this->getJson('/api/bookings')->assertOk()->assertJsonCount(9)->assertJsonMissingPath('data');
        $this->getJson("/api/bookings?booking_group_id={$group->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->b['arriving']->id);
    }

    public function test_paginated_response_shape_stats_and_filter_options(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();

        $res = $this->getJson('/api/bookings?page=1&view=all&per_page=5')->assertOk();

        $res->assertJsonPath('total', 9)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 5)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('stats', [
                'in_house' => 3,
                'arrivals_expected' => 1,
                'arrivals_done' => 0,
                'departures_pending' => 1,
                'departures_done' => 0,
                'balance_due_count' => 3,
                'balance_due_amount' => 7200,
                'attention' => 3,
                'current' => 6,
                'past' => 3,
                'all' => 9,
            ])
            ->assertJsonPath('filter_options.sources', ['Booking.com', 'walk-in'])
            ->assertJsonPath('filter_options.room_types.0.name', 'Deluxe')
            ->assertJsonPath('filter_options.rate_plans.1.name', 'Suite CP');

        $this->assertCount(4, $this->getJson('/api/bookings?page=2&view=all&per_page=5')->json('data'));
        $this->assertArrayHasKey('room', $res->json('data.0'), 'List rows keep the room relation for the UI.');
    }

    public function test_views_partition_current_and_past(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();

        $this->assertEqualsCanonicalizing(
            $this->ids(['in_house', 'departing', 'arriving', 'future', 'no_show', 'early_in']),
            $this->listIds(['view' => 'current']),
        );
        $this->assertEqualsCanonicalizing($this->ids(['settled', 'unpaid_out', 'cancelled']), $this->listIds(['view' => 'past']));
        $this->assertCount(9, $this->listIds(['view' => 'all']));
    }

    public function test_quick_filters_match_the_stat_cards(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();

        $this->assertEqualsCanonicalizing($this->ids(['in_house', 'departing', 'early_in']), $this->listIds(['quick' => 'in_house']));
        $this->assertEqualsCanonicalizing($this->ids(['arriving']), $this->listIds(['quick' => 'arrivals']));
        $this->assertEqualsCanonicalizing($this->ids(['departing']), $this->listIds(['quick' => 'departures']));
        $this->assertEqualsCanonicalizing($this->ids(['in_house', 'unpaid_out', 'early_in']), $this->listIds(['quick' => 'balance_due']));
        $this->assertEqualsCanonicalizing($this->ids(['no_show', 'unpaid_out', 'early_in']), $this->listIds(['quick' => 'attention']));
    }

    public function test_quick_filter_overrides_the_view(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();

        $this->assertEqualsCanonicalizing(
            $this->ids(['in_house', 'unpaid_out', 'early_in']),
            $this->listIds(['quick' => 'balance_due', 'view' => 'current']),
        );
    }

    public function test_field_filters(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();

        $this->assertSame($this->ids(['settled', 'unpaid_out']), $this->listIds(['view' => 'all', 'status' => 'checked_out', 'sort' => 'check_in']));
        $this->assertSame($this->ids(['arriving']), $this->listIds(['view' => 'all', 'source' => 'booking.com']));
        $this->assertSame($this->ids(['future']), $this->listIds(['view' => 'all', 'room_type_id' => $this->b['future']->room->room_type_id]));
        $this->assertSame($this->ids(['future']), $this->listIds(['view' => 'all', 'rate_plan_id' => $this->b['future']->rate_plan_id]));
        $this->assertEqualsCanonicalizing(
            $this->ids(['cancelled', 'early_in']),
            $this->listIds(['view' => 'all', 'date_from' => $this->day(3), 'date_to' => $this->day(4)]),
            'Stay-overlap: departure on the range start still counts.',
        );
    }

    public function test_search_by_name_room_id_and_multiple_terms(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();

        $this->assertSame($this->ids(['unpaid_out']), $this->listIds(['view' => 'all', 'search' => 'maya']));
        $this->assertSame($this->ids(['unpaid_out']), $this->listIds(['view' => 'all', 'search' => '203']));
        $this->assertSame($this->ids(['future']), $this->listIds(['view' => 'all', 'search' => 'Suite']));
        $this->assertSame($this->ids(['settled']), $this->listIds(['view' => 'all', 'search' => '#' . $this->b['settled']->id, 'status' => 'checked_out']));
        $this->assertSame($this->ids(['in_house']), $this->listIds(['view' => 'all', 'search' => 'hari rao']));
        $this->assertSame([], $this->listIds(['view' => 'all', 'search' => 'hari zzz']));
        $this->assertSame([], $this->listIds(['view' => 'all', 'search' => '100%']), 'LIKE wildcards are escaped.');
    }

    public function test_search_by_booking_number_without_matching_room_numbers(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();
        foreach ($this->b as $booking) {
            BookingNumber::assign($booking);
        }
        $maya = $this->b['unpaid_out']->fresh();

        $this->assertSame($this->ids(['unpaid_out']), $this->listIds(['view' => 'all', 'search' => $maya->booking_number]));
        $this->assertSame($this->ids(['unpaid_out']), $this->listIds(['view' => 'all', 'search' => strtolower($maya->booking_number)]));

        $this->b['settled']->forceFill(['booking_number' => 'RES-000203'])->save();
        $this->assertSame(
            $this->ids(['unpaid_out']),
            $this->listIds(['view' => 'all', 'search' => '203']),
            'Digit-only terms match room number or id, not booking numbers that contain the digits.',
        );
    }

    public function test_sorting(): void
    {
        $this->actingWith(['reservation-view']);
        $this->seedBoard();
        $all = ['view' => 'all'];

        $this->assertSame(
            $this->ids(['arriving', 'future', 'in_house', 'departing', 'no_show', 'settled', 'unpaid_out', 'cancelled', 'early_in']),
            $this->listIds($all + ['sort' => 'room']),
            'Room numbers sort naturally: 9, 10, 101 …',
        );
        $this->assertSame(
            $this->ids(['arriving', 'no_show', 'settled', 'departing', 'in_house', 'cancelled', 'early_in', 'unpaid_out', 'future']),
            $this->listIds($all + ['sort' => 'guest']),
        );
        $this->assertSame(
            $this->ids(['no_show', 'arriving', 'in_house', 'early_in', 'cancelled', 'future']),
            array_slice($this->listIds($all + ['sort' => 'balance', 'dir' => 'desc']), 0, 6),
            'Ties break on id in the same direction.',
        );
        $this->assertSame($this->ids(['in_house', 'departing', 'early_in']), array_slice($this->listIds($all + ['sort' => 'status']), 0, 3));
        $this->assertSame((int) $this->b['cancelled']->id, $this->listIds($all + ['sort' => 'status'])[8]);
        $this->assertSame((int) $this->b['future']->id, $this->listIds($all + ['sort' => 'check_out', 'dir' => 'desc'])[0]);
        $this->assertSame(
            $this->ids(['cancelled', 'unpaid_out', 'settled']),
            $this->listIds(['view' => 'past']),
            'Past tab defaults to latest check-in first.',
        );
    }

    public function test_list_validation_and_permission(): void
    {
        $this->actingWith(['reservation-view']);
        $this->getJson('/api/bookings?page=1&sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
        $this->getJson('/api/bookings?page=1&per_page=500')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->getJson('/api/bookings?page=1&dir=sideways')->assertStatus(422)->assertJsonValidationErrors('dir');

        $this->actingWith(['reservation-edit']);
        $this->getJson('/api/bookings?page=1')->assertForbidden();
    }

    // ── GET /bookings/{id} (detail view) ──────────────────────────────────

    public function test_show_includes_segments_and_checkout_billing(): void
    {
        $this->actingWith(['reservation-view']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(1), [
            'status' => 'checked_in',
            'extra_charges' => 300,
            'checkout_discount_amount' => 80,
        ]);
        $this->pay($booking, 3000);
        BookingPaymentLedger::recordRefund($booking->fresh(), ['amount' => 200, 'method' => 'cash', 'source' => 'manual', 'bill_total' => 4700]);

        $this->getJson("/api/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('id', $booking->id)
            ->assertJsonPath('room.room_type.name', 'Deluxe')
            ->assertJsonPath('segments.0.room.room_number', '101')
            ->assertJsonPath('billing.room', 4480)
            ->assertJsonPath('billing.extras', 300)
            ->assertJsonPath('billing.discount', 80)
            ->assertJsonPath('billing.bill', 4700)
            ->assertJsonPath('billing.paid', 3000)
            ->assertJsonPath('billing.refunded', 200)
            ->assertJsonPath('billing.received', 2800)
            ->assertJsonPath('billing.balance_due', 1900)
            ->assertJsonPath('billing.credit', 0)
            ->assertJsonPath('billing.group', null);
    }

    public function test_show_billing_for_group_and_cancelled_bookings(): void
    {
        $this->actingWith(['reservation-view']);
        $group = BookingGroup::query()->create(['name' => 'Team', 'status' => 'confirmed']);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        $b = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        $this->pay($b, 6000);
        $cancelled = $this->makeBooking($this->makeRoom('103'), $this->day(2), $this->day(4), [
            'status' => 'cancelled',
            'deposit_amount' => 2240,
            'cancellation_fee_amount' => 2240,
            'cancellation_reason' => 'guest_request',
        ]);

        $this->getJson("/api/bookings/{$a->id}")
            ->assertOk()
            ->assertJsonPath('billing.balance_due', 4480)
            ->assertJsonPath('billing.group', ['bookings' => 2, 'bill' => 8960, 'paid' => 6000, 'balance_due' => 2960]);
        $this->getJson("/api/bookings/{$cancelled->id}")
            ->assertOk()
            ->assertJsonPath('billing.bill', 2240)
            ->assertJsonPath('billing.cancellation_fee', 2240)
            ->assertJsonPath('billing.balance_due', 0)
            ->assertJsonPath('billing.credit', 0);
    }

    // ── POST /bookings/{id}/preview-checkout ──────────────────────────────

    public function test_preview_checkout_for_settled_departure(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 4480);
        $this->completeCheckoutInspection($booking);

        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson([
                'checkout_scope' => 'room',
                'pooled' => false,
                'bill' => 4480,
                'received' => 4480,
                'balance_due' => 0,
                'refund_due' => 0,
                'can_checkout' => true,
                'inspection_status' => 'done',
                'inspection_rooms' => [],
                'checkout_day' => $this->day(0),
                'is_early_checkout' => false,
            ]);
    }

    public function test_preview_checkout_reports_missing_or_pending_inspection(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 4480);

        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['balance_due' => 0, 'can_checkout' => false, 'inspection_status' => 'none', 'inspection_rooms' => ['101']]);

        $this->postJson("/api/bookings/{$booking->id}/request-inspection")->assertOk();
        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['can_checkout' => false, 'inspection_status' => 'pending', 'inspection_rooms' => ['101']]);
    }

    public function test_preview_checkout_blocks_unpaid_and_flags_early_departure(): void
    {
        $this->actingWith(['reservation-edit']);
        $unpaid = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($unpaid, 4000);
        $early = $this->makeBooking($this->makeRoom('102'), $this->day(-1), $this->day(2), ['status' => 'checked_in']);

        $this->postJson("/api/bookings/{$unpaid->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['can_checkout' => false, 'balance_due' => 480, 'received' => 4000]);
        $this->postJson("/api/bookings/{$early->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['is_early_checkout' => true, 'checkout_day' => $this->day(2)]);
    }

    public function test_preview_refund_is_cumulative_and_checkout_records_only_the_new_refund(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->pay($booking, 5200);
        BookingPaymentLedger::recordRefund($booking->fresh(), [
            'amount' => 100,
            'method' => 'cash',
            'source' => 'manual',
            'bill_total' => 4480,
        ]);
        $this->completeCheckoutInspection($booking);

        $preview = $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['received' => 5100, 'refund_due' => 620, 'refund_amount_after' => 720, 'can_checkout' => true]);

        $this->patchJson("/api/bookings/{$booking->id}", [
            'status' => 'checked_out',
            'checkout_scope' => $preview->json('checkout_scope'),
            'refund_amount' => $preview->json('refund_amount_after'),
            'refund_method' => 'upi',
        ])->assertOk()->assertJsonPath('status', 'checked_out');

        $this->assertSame(720.0, (float) $booking->fresh()->refund_amount);
        $this->assertDatabaseHas('booking_payments', ['booking_id' => $booking->id, 'type' => 'refund', 'method' => 'upi', 'source' => 'checkout', 'amount' => 620]);
    }

    public function test_preview_checkout_for_groups_pools_payments_unless_room_scope(): void
    {
        $this->actingWith(['reservation-edit']);
        $group = BookingGroup::query()->create(['name' => 'Team', 'status' => 'confirmed']);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        $b = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        BookingPaymentLedger::recordPayment($b, ['amount' => 8960, 'method' => 'card', 'source' => 'deposit', 'bill_total' => 8960]);
        $this->completeCheckoutInspection($a);

        $this->postJson("/api/bookings/{$a->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['checkout_scope' => 'group', 'pooled' => true, 'bill' => 8960, 'can_checkout' => true, 'refund_due' => 0]);
        $this->postJson("/api/bookings/{$a->id}/preview-checkout", ['checkout_scope' => 'room'])
            ->assertOk()
            ->assertJson(['pooled' => false, 'can_checkout' => false, 'balance_due' => 4480]);
    }

    public function test_preview_checkout_guards(): void
    {
        $this->actingWith(['reservation-edit']);
        $confirmed = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(1));
        $this->postJson("/api/bookings/{$confirmed->id}/preview-checkout")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only checked-in guests can be checked out.');
        $this->postJson("/api/bookings/{$confirmed->id}/preview-checkout", ['checkout_scope' => 'all'])
            ->assertStatus(422);

        $this->actingWith(['reservation-view']);
        $this->postJson("/api/bookings/{$confirmed->id}/preview-checkout")->assertForbidden();
    }

    // ── No-show (POST /bookings/{id}/cancel with reason=no_show) ──────────

    public function test_no_show_is_rejected_before_the_arrival_date(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(1), $this->day(2));

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'no_show'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A reservation can only be marked as a no-show on or after its arrival date.');
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_no_show_on_arrival_day_settles_policy_fee_against_deposit(): void
    {
        $this->actingWith(['reservation-delete']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->pay($booking, 1000);

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'no_show'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cancellation fee exceeds deposit. Collect the balance due, or confirm waiving the remaining balance.')
            ->assertJsonPath('preview.effective_fee', 2240);

        $this->postJson("/api/bookings/{$booking->id}/cancel", [
            'reason' => 'no_show',
            'reason_notes' => 'Phone switched off',
            'additional_collected' => 1240,
            'additional_payment_method' => 'card',
        ])
            ->assertOk()
            ->assertJsonPath('booking.status', 'cancelled')
            ->assertJsonPath('booking.cancellation_reason', 'no_show')
            ->assertJsonPath('settlement.cancellation_fee', 2240)
            ->assertJsonPath('settlement.forfeited_from_deposit', 2240);

        $this->assertStringContainsString('[Cancellation: No-show — Phone switched off | Fee', (string) $booking->fresh()->notes);
        $this->assertSame([(int) $booking->id], $this->asViewer(fn () => $this->listIds(['view' => 'all', 'status' => 'no_show'])));
        $this->assertSame([], $this->asViewer(fn () => $this->listIds(['view' => 'all', 'status' => 'cancelled', 'search' => 'nobody'])));
    }

    public function test_no_show_requires_delete_permission(): void
    {
        $this->actingWith(['reservation-edit']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(1));

        $this->postJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'no_show', 'confirm_balance_waived' => true])
            ->assertForbidden();
    }

    public function test_no_show_is_counted_as_no_show_not_cancellation_in_reports(): void
    {
        $this->actingWith(['reservation-delete']);
        $noShow = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(1));
        $cancelled = $this->makeBooking($this->makeRoom('102'), $this->day(0), $this->day(1));
        $this->postJson("/api/bookings/{$noShow->id}/cancel", ['reason' => 'no_show', 'confirm_balance_waived' => true])->assertOk();
        $this->postJson("/api/bookings/{$cancelled->id}/cancel", ['reason' => 'guest_request', 'confirm_balance_waived' => true])->assertOk();

        $flash = app(\App\Services\HospitalityReportService::class)->frontOfficeDailyFlash($this->day(0));

        $this->assertSame(1, $flash['summary']['no_shows']);
        $this->assertSame(1, $flash['summary']['cancellations']);
    }

    // ── GET /reports/unpaid-checkouts ─────────────────────────────────────

    private function checkedOut(string $room, int $in, int $out, float $paid, array $overrides = []): Booking
    {
        $booking = $this->makeBooking($this->makeRoom($room), $this->day($in), $this->day($out), ['status' => 'checked_in'] + $overrides);
        if ($paid > 0) {
            $this->pay($booking, $paid);
        }
        $booking->refresh()->update(['status' => 'checked_out']);

        return $booking->fresh();
    }

    public function test_unpaid_checkouts_report_rows_aging_and_summary(): void
    {
        $this->actingWith(['report-unpaid-checkouts']);
        $recent = $this->checkedOut('101', -4, -2, 4000);
        $this->checkedOut('102', -4, -2, 4480);
        $old = $this->checkedOut('103', -42, -40, 0);
        $this->makeBooking($this->makeRoom('104'), $this->day(-1), $this->day(1), ['status' => 'checked_in']);

        $res = $this->getJson('/api/reports/unpaid-checkouts?from=' . $this->day(-60) . '&to=' . $this->day(0))->assertOk();

        $res->assertJsonPath('summary.bookings', 2)
            ->assertJsonPath('summary.outstanding', 4960)
            ->assertJsonPath('summary.checkouts_in_period', 3)
            ->assertJsonPath('summary.oldest_days', 40)
            ->assertJsonPath('rows.0.booking_id', $old->id)
            ->assertJsonPath('rows.0.booking_number', 'RES-' . str_pad((string) $old->id, 6, '0', STR_PAD_LEFT))
            ->assertJsonPath('rows.0.balance', 4480)
            ->assertJsonPath('rows.1.booking_id', $recent->id)
            ->assertJsonPath('rows.1.bill', 4480)
            ->assertJsonPath('rows.1.received', 4000)
            ->assertJsonPath('rows.1.balance', 480)
            ->assertJsonPath('rows.1.days_outstanding', 2)
            ->assertJsonPath('rows.1.room_number', '101')
            ->assertJsonPath('aging.0.count', 1)
            ->assertJsonPath('aging.0.amount', 480)
            ->assertJsonPath('aging.2.count', 1)
            ->assertJsonPath('aging.2.amount', 4480);
        $this->assertNotNull($res->json('rows.1.last_payment_at'));
        $this->assertNull($res->json('rows.0.last_payment_at'));

        $this->getJson('/api/reports/unpaid-checkouts?from=' . $this->day(-30) . '&to=' . $this->day(0))
            ->assertOk()
            ->assertJsonPath('summary.bookings', 1)
            ->assertJsonPath('rows.0.booking_id', $recent->id);
    }

    public function test_unpaid_checkouts_skips_groups_covered_by_pooled_payments(): void
    {
        $this->actingWith(['reservation-view']);
        $paidGroup = BookingGroup::query()->create(['name' => 'Paid team', 'status' => 'confirmed']);
        $shortGroup = BookingGroup::query()->create(['name' => 'Short team', 'status' => 'confirmed']);
        $this->checkedOut('101', -3, -1, 0, ['booking_group_id' => $paidGroup->id]);
        $this->checkedOut('102', -3, -1, 8960, ['booking_group_id' => $paidGroup->id]);
        $short = $this->checkedOut('201', -3, -1, 0, ['booking_group_id' => $shortGroup->id]);
        $this->checkedOut('202', -3, -1, 5000, ['booking_group_id' => $shortGroup->id]);

        $this->getJson('/api/reports/unpaid-checkouts?from=' . $this->day(-7) . '&to=' . $this->day(0))
            ->assertOk()
            ->assertJsonPath('summary.bookings', 1)
            ->assertJsonPath('rows.0.booking_id', $short->id)
            ->assertJsonPath('rows.0.group_name', 'Short team');
    }

    public function test_unpaid_checkouts_permission_and_validation(): void
    {
        $this->actingWith(['reservation-edit']);
        $this->getJson('/api/reports/unpaid-checkouts?from=' . $this->day(-7) . '&to=' . $this->day(0))->assertForbidden();

        $this->actingWith(['report-unpaid-checkouts']);
        $this->getJson('/api/reports/unpaid-checkouts?to=' . $this->day(0))->assertStatus(422)->assertJsonValidationErrors('from');
        $this->getJson('/api/reports/unpaid-checkouts?from=' . $this->day(0) . '&to=' . $this->day(-1))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    /**
     * @template T
     * @param  callable(): T  $fn
     * @return T
     */
    private function asViewer(callable $fn): mixed
    {
        $this->actingWith(['reservation-view']);

        return $fn();
    }
}
