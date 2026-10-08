<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingGroup;
use App\Models\BookingSegment;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountCodes;
use App\Services\Accounting\JournalPostingService;
use App\Support\BookingPaymentLedger;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change check-in syncs the hotel APIs, hourly vs nightly departure-morning overlap, the refund journal
 * after check-out and the pooled group checkout journal.
 */
class BookingIntegrationsAccountingTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingWith();
        $this->createJournalTables();
    }

    private function createJournalTables(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            Schema::create('chart_of_accounts', function (Blueprint $table) {
                $table->id();
                $table->string('code', 16)->unique();
                $table->string('name');
                $table->string('type');
                $table->string('parent_code', 16)->nullable();
                $table->boolean('is_posting')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            Schema::create('journal_entries', function (Blueprint $table) {
                $table->id();
                $table->string('entry_number', 32)->unique();
                $table->date('entry_date');
                $table->date('business_date')->nullable();
                $table->string('source_type', 64);
                $table->unsignedBigInteger('source_id');
                $table->string('source_ref', 128)->nullable();
                $table->text('memo')->nullable();
                $table->string('status')->default('posted');
                $table->unsignedBigInteger('reverses_entry_id')->nullable();
                $table->timestamp('posted_at');
                $table->unsignedBigInteger('posted_by')->nullable();
                $table->timestamps();
            });
            Schema::create('journal_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('journal_entry_id');
                $table->unsignedSmallInteger('line_no');
                $table->unsignedBigInteger('account_id');
                $table->decimal('debit', 15, 2)->default(0);
                $table->decimal('credit', 15, 2)->default(0);
                $table->string('tax_tag', 32)->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        foreach ([
            [AccountCodes::CASH, 'Cash', 'asset'],
            [AccountCodes::BANK_CARD, 'Card', 'asset'],
            [AccountCodes::BANK_UPI, 'UPI', 'asset'],
            [AccountCodes::FOLIO_AR, 'Folio AR', 'asset'],
            [AccountCodes::OUTPUT_CGST, 'Output CGST', 'liability'],
            [AccountCodes::OUTPUT_SGST, 'Output SGST', 'liability'],
            [AccountCodes::ROOM_REVENUE, 'Room Revenue', 'income'],
        ] as [$code, $name, $type]) {
            ChartOfAccount::query()->firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
        }
        JournalPostingService::flushAccountCache();
    }

    /** @return array<string, float> account code => debit − credit */
    private function journalByAccount(string $sourceType, int $sourceId): array
    {
        $entry = JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->with('lines.account')->first();
        if (! $entry) {
            return [];
        }
        $out = [];
        foreach ($entry->lines as $line) {
            $code = (string) $line->account->code;
            $out[$code] = round(($out[$code] ?? 0) + (float) $line->debit - (float) $line->credit, 2);
        }

        return $out;
    }

    private function checkedOutBooking(float $paid): Booking
    {
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        BookingPaymentLedger::recordPayment($booking, ['amount' => $paid, 'method' => 'cash', 'source' => 'deposit', 'bill_total' => 4480]);
        $this->completeCheckoutInspection($booking);
        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();

        return $booking->fresh();
    }

    public function test_change_check_in_pushes_to_hotel_apis(): void
    {
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(2), $this->day(4));

        $this->postJson("/api/bookings/{$booking->id}/change-check-in", ['new_check_in' => $this->day(1), 'keep_nights' => true])
            ->assertOk()
            ->assertJsonPath('doorloom.status', 'skipped');
    }

    public function test_hourly_stay_waits_for_the_departing_night_guest(): void
    {
        $room = $this->makeRoom('101');
        $night = $this->makeBooking($room, $this->day(-1), $this->day(1), ['status' => 'checked_in']);
        $plan = $this->makeHourlyPlan();
        $hourly = fn (string $time) => $this->postJson('/api/bookings', [
            'room_id' => $room->id, 'first_name' => 'Ravi', 'last_name' => 'K', 'phone' => '9876500000',
            'adults_count' => 2, 'booking_unit' => 'hour_package', 'rate_plan_id' => $plan->id,
            'check_in' => $this->day(1) . " {$time}:00",
        ]);

        $hourly('09:00')->assertStatus(422)->assertJsonPath('message', 'Room #101 is already reserved for the selected dates.');

        $night->update(['late_checkout_time' => '13:00']);
        $hourly('12:00')->assertStatus(422)->assertJsonPath('message', 'Room #101 is already reserved for the selected dates.');
        $hourly('13:00')->assertCreated();
    }

    public function test_night_stay_cannot_depart_into_a_morning_hourly_stay(): void
    {
        $room = $this->makeRoom('101');
        $plan = $this->makeHourlyPlan();
        $hourly = Booking::query()->create([
            'room_id' => $room->id, 'first_name' => 'Ravi', 'last_name' => 'K', 'status' => 'confirmed',
            'booking_unit' => 'hour_package', 'rate_plan_id' => $plan->id,
            'check_in' => $this->day(2), 'check_out' => $this->day(3),
            'check_in_at' => Carbon::parse($this->day(2) . ' 09:00:00'), 'check_out_at' => Carbon::parse($this->day(2) . ' 12:00:00'),
        ]);
        BookingSegment::query()->create([
            'booking_id' => $hourly->id, 'room_id' => $room->id, 'status' => 'confirmed',
            'check_in' => $this->day(2), 'check_out' => $this->day(3),
            'check_in_at' => $hourly->check_in_at, 'check_out_at' => $hourly->check_out_at,
        ]);
        $night = fn () => $this->postJson('/api/bookings', [
            'room_id' => $room->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => '9876543210',
            'adults_count' => 2, 'check_in' => $this->day(0), 'check_out' => $this->day(2), 'rate_plan_id' => $this->dayPlan->id,
        ]);

        $night()->assertStatus(422)->assertJsonPath('message', 'Room #101 is already reserved for the selected dates.');

        $hourly->segments()->update(['check_in_at' => $this->day(2) . ' 12:00:00', 'check_out_at' => $this->day(2) . ' 15:00:00']);
        $night()->assertCreated();
    }

    public function test_refund_after_checkout_journals_only_booked_revenue(): void
    {
        $booking = $this->checkedOutBooking(5000);
        $this->assertSame(4480.0, $this->journalByAccount('booking_checkout', $booking->id)[AccountCodes::CASH] ?? null);

        $overpayment = $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'amount' => 520, 'method' => 'cash'])
            ->assertCreated()->json('payment.id');
        $this->assertSame([], $this->journalByAccount('booking_refund', (int) $overpayment), 'The ₹520 overpayment was never booked.');

        $goodwill = $this->postJson("/api/bookings/{$booking->id}/payments", ['type' => 'refund', 'amount' => 1120, 'method' => 'upi'])
            ->assertCreated()->json('payment.id');
        $lines = $this->journalByAccount('booking_refund', (int) $goodwill);
        $this->assertSame(-1120.0, $lines[AccountCodes::BANK_UPI]);
        $this->assertSame(1000.0, $lines[AccountCodes::ROOM_REVENUE]);
        $this->assertEqualsWithDelta(120.0, $lines[AccountCodes::OUTPUT_CGST] + $lines[AccountCodes::OUTPUT_SGST], 0.01);
    }

    public function test_group_checkout_journal_uses_the_pooled_payment(): void
    {
        $group = BookingGroup::query()->create(['name' => 'Team', 'status' => 'confirmed']);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        $b = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $group->id]);
        BookingPaymentLedger::recordPayment($b, ['amount' => 8960, 'method' => 'card', 'source' => 'deposit', 'bill_total' => 8960]);
        $this->completeCheckoutInspection($a);
        $this->completeCheckoutInspection($b);

        $this->patchJson("/api/bookings/{$a->id}", ['status' => 'checked_out'])->assertOk();

        foreach ([$a, $b] as $member) {
            $lines = $this->journalByAccount('booking_checkout', $member->id);
            $this->assertSame(4480.0, $lines[AccountCodes::BANK_CARD] ?? null, "Room #{$member->id} is paid from the group card payment.");
            $this->assertArrayNotHasKey(AccountCodes::FOLIO_AR, $lines, 'No folio AR left open on a paid group.');
        }
    }
}
