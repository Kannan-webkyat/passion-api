<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Support\BookingPaymentLedger;
use App\Support\ReservationInvoiceViewData;

/**
 * GET /bookings/{id}/billing (hotel invoice PDF) and the view data behind it: room line + GST, early /
 * late fees, laundry, checkout inspection lines, tax details per rate, payments and refunds, and that
 * the invoice payable matches the amount the checkout guard collects.
 * Standard times default to 14:00 check-in and 11:00 check-out.
 */
class ReservationInvoiceTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingWith(['reservation-view', 'reservation-edit']);
    }

    private function departingToday(array $overrides = []): Booking
    {
        return $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), array_merge(['status' => 'checked_in'], $overrides));
    }

    private function postLaundry(Booking $booking, float $total, int $requestId = 1): void
    {
        BookingExtraCharge::query()->create([
            'booking_id' => $booking->id,
            'source' => 'laundry',
            'kind' => 'laundry',
            'label' => 'Guest laundry #' . $requestId,
            'qty' => 1,
            'unit_amount' => $total,
            'total_amount' => $total,
        ]);
        $booking->update(['extra_charges' => (float) $booking->fresh()->extra_charges + $total]);
    }

    /** @return array<string, string> label => value */
    private function summary(array $invoice): array
    {
        return collect($invoice['summaryLines'])->mapWithKeys(fn($l) => [$l['label'] => $l['value']])->all();
    }

    private function invoice(Booking $booking): array
    {
        return ReservationInvoiceViewData::build($booking->fresh());
    }

    // ── Endpoint ────────────────────────────────────────────────────────────

    private function checkOut(Booking $booking): void
    {
        $bill = (float) $this->postJson("/api/bookings/{$booking->id}/preview-checkout")->assertOk()->json('bill');
        $booking->update(['deposit_amount' => $bill]);
        $this->completeCheckoutInspection($booking);
        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();
    }

    public function test_in_house_guest_gets_a_proforma_pdf(): void
    {
        $booking = $this->departingToday();

        $response = $this->get("/api/bookings/{$booking->id}/billing");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('Proforma_RES-' . str_pad((string) $booking->id, 6, '0', STR_PAD_LEFT) . '.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $invoice = $this->invoice($booking);
        $this->assertSame('Proforma Invoice', $invoice['documentTitle']);
        $this->assertSame('Proforma', $invoice['invoiceNo']);
        $this->assertNull($booking->fresh()->invoice_number, 'Downloading does not consume a number.');
    }

    public function test_checkout_issues_sequential_invoice_numbers_with_a_frozen_date(): void
    {
        $first = $this->departingToday();
        $second = $this->makeBooking($this->makeRoom('102'), $this->day(-1), $this->day(0), ['status' => 'checked_in']);

        $this->checkOut($first);
        $this->setting('invoice_prefix', 'HTL');
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse(self::TODAY . ' 11:30:00', 'Asia/Kolkata'));
        $this->checkOut($second);

        $this->assertSame([1, 'INV-000001'], [$first->fresh()->invoice_seq, $first->fresh()->invoice_number]);
        $this->assertSame([2, 'HTL-000002'], [$second->fresh()->invoice_seq, $second->fresh()->invoice_number]);

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($this->day(3) . ' 16:00:00', 'Asia/Kolkata'));
        $invoice = $this->invoice($first);
        $this->assertSame('INV-000001', $invoice['invoiceNo']);
        $this->assertSame('10/10/2026 10:00:00 AM', $invoice['invoiceDate'], 'Date stays at check-out, not print time.');
        $this->assertSame('Invoice', $invoice['documentTitle']);
        $this->assertStringContainsString(
            'Invoice_HTL-000002.pdf',
            (string) $this->get("/api/bookings/{$second->id}/billing")->assertOk()->headers->get('content-disposition')
        );
    }

    public function test_billing_endpoint_requires_reservation_view_or_edit(): void
    {
        $booking = $this->departingToday();
        $this->actingWith(['reservation-hold-room']);

        $this->getJson("/api/bookings/{$booking->id}/billing")->assertForbidden();
    }

    // ── Room line and GST ───────────────────────────────────────────────────

    public function test_basic_stay_shows_room_line_with_split_gst(): void
    {
        $invoice = $this->invoice($this->departingToday());

        $this->assertCount(1, $invoice['lines']);
        $room = $invoice['lines'][0];
        $this->assertSame('Room Charges', $room['particular']);
        $this->assertSame('2', $room['qty']);
        $this->assertSame('2000.00', $room['rate'], 'Rate is the pre-tax nightly price.');
        $this->assertSame('4480.00', $room['total']);
        $this->assertSame('4000.00', $room['taxable']);
        $this->assertSame('240.00', $room['cgst_amt']);
        $this->assertSame('240.00', $room['sgst_amt']);
        $this->assertSame([
            ['label' => 'CGST @ 6%', 'taxable' => '4000.00', 'tax' => '240.00'],
            ['label' => 'SGST @ 6%', 'taxable' => '4000.00', 'tax' => '240.00'],
        ], $invoice['taxDetailRows']);
        $this->assertSame('4480.00', $this->summary($invoice)['Total Payable(Rs)']);
        $this->assertSame('4480.00', $this->summary($invoice)['Balance(Rs)']);
    }

    public function test_title_is_tax_invoice_when_hotel_gstin_is_set(): void
    {
        $booking = $this->departingToday();
        $booking->forceFill(['invoice_seq' => 1, 'invoice_number' => 'INV-000001', 'invoice_issued_at' => now()])->save();
        $this->assertSame('Invoice', $this->invoice($booking)['documentTitle']);

        $this->setting('invoice_gstin', '29ABCDE1234F1Z5');

        $this->assertSame('Tax Invoice', $this->invoice($booking)['documentTitle']);
    }

    // ── Payments ────────────────────────────────────────────────────────────

    public function test_payments_are_itemised_from_the_ledger_and_refunds_reduce_the_amount_paid(): void
    {
        $booking = $this->departingToday();
        BookingPaymentLedger::recordPayment($booking, ['amount' => 3000, 'method' => 'upi', 'reference_no' => 'UTR1', 'paid_at' => $this->day(-2) . ' 15:00:00']);
        BookingPaymentLedger::recordPayment($booking, ['amount' => 2000, 'method' => 'cash', 'paid_at' => $this->day(0) . ' 09:00:00']);
        BookingPaymentLedger::recordRefund($booking, ['amount' => 520, 'method' => 'cash', 'paid_at' => $this->day(0) . ' 09:30:00']);

        $invoice = $this->invoice($booking);

        $this->assertSame([
            ['date' => '08/10/2026', 'description' => 'UPI — Payment · Ref UTR1', 'amount' => '3000.00'],
            ['date' => '10/10/2026', 'description' => 'CASH — Payment', 'amount' => '2000.00'],
            ['date' => '10/10/2026', 'description' => 'CASH — Refund', 'amount' => '-520.00'],
        ], $invoice['paymentRows']);
        $this->assertSame('4480.00', $invoice['paymentTotalFmt']);
        $this->assertSame('4480.00', $this->summary($invoice)['Total Payment(Rs)']);
        $this->assertSame('0.00', $this->summary($invoice)['Balance(Rs)']);
    }

    public function test_refund_without_ledger_rows_still_nets_off(): void
    {
        $booking = $this->departingToday(['deposit_amount' => 5000, 'refund_amount' => 520, 'payment_method' => 'cash', 'refund_method' => 'cash']);

        $invoice = $this->invoice($booking);

        $this->assertSame(['5000.00', '-520.00'], array_column($invoice['paymentRows'], 'amount'));
        $this->assertSame('0.00', $this->summary($invoice)['Balance(Rs)']);
    }

    public function test_checkout_guard_counts_refunds_against_the_amount_held(): void
    {
        $booking = $this->departingToday(['deposit_amount' => 4480, 'refund_amount' => 480, 'payment_status' => 'paid']);
        $this->completeCheckoutInspection($booking);

        $preview = $this->postJson("/api/bookings/{$booking->id}/preview-checkout")->assertOk();

        $preview->assertJson(['bill' => 4480, 'received' => 4000, 'balance_due' => 480, 'can_checkout' => false]);
        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertStatus(422);
    }

    // ── Early / late fees and posted extras ─────────────────────────────────

    public function test_late_checkout_fee_is_billed_once_alongside_other_extras(): void
    {
        $this->roomType->update(['late_check_out_fee' => 500, 'late_check_out_type' => 'flat_fee']);
        $booking = $this->departingToday();
        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '14:00'])->assertOk();
        $this->postLaundry($booking, 300);
        $this->assertSame(800.0, (float) $booking->fresh()->extra_charges, 'Fee and laundry both sit on extra_charges.');

        $invoice = $this->invoice($booking);

        $this->assertSame('5040.00', $invoice['lines'][0]['total'], 'Room (2 × 2000 + 500 late fee) + 12% GST.');
        $this->assertSame(['Room Charges', 'Guest laundry #1'], array_column($invoice['lines'], 'particular'));
        $this->assertSame('300.00', $invoice['lines'][1]['total']);
        $this->assertSame('5340.00', $this->summary($invoice)['Total Payable(Rs)']);

        $booking->update(['deposit_amount' => 5340]);
        $this->completeCheckoutInspection($booking);
        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['bill' => 5340, 'balance_due' => 0, 'can_checkout' => true]);
    }

    public function test_early_check_in_fee_posted_later_is_billed_once(): void
    {
        $this->roomType->update(['early_check_in_fee' => 800, 'early_check_in_type' => 'flat_fee']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '09:00'])->assertOk();
        $this->postLaundry($booking, 300);

        $invoice = $this->invoice($booking);

        $this->assertSame('5376.00', $invoice['lines'][0]['total'], 'Room (4000 + 800 early fee) + 12% GST.');
        $this->assertSame('5676.00', $this->summary($invoice)['Total Payable(Rs)']);
    }

    public function test_early_check_in_priced_at_booking_keeps_extras_whole(): void
    {
        $this->roomType->update(['early_check_in_fee' => 800, 'early_check_in_type' => 'flat_fee']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2), [
            'early_checkin_time' => '09:00',
            'total_price' => 5376,
        ]);
        $this->postLaundry($booking, 300);

        $this->assertSame('5676.00', $this->summary($this->invoice($booking))['Total Payable(Rs)']);
    }

    public function test_arrival_between_standard_check_out_and_check_in_is_billed_as_early_check_in(): void
    {
        $this->roomType->update(['early_check_in_fee' => 800, 'early_check_in_type' => 'flat_fee']);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '12:00'])->assertOk();
        $this->assertSame(800.0, (float) $booking->fresh()->extra_charges, '12:00 is before the 14:00 check-in.');

        $invoice = $this->invoice($booking);

        $this->assertSame(['Room Charges'], array_column($invoice['lines'], 'particular'));
        $this->assertSame('5376.00', $invoice['lines'][0]['total'], 'Room (4000 + 800 early fee) + 12% GST.');
        $this->assertSame('5376.00', $this->summary($invoice)['Total Payable(Rs)']);
    }

    public function test_flat_late_checkout_fee_is_not_charged_inside_the_grace_period(): void
    {
        $this->roomType->update(['late_check_out_fee' => 500, 'late_check_out_type' => 'flat_fee', 'late_check_out_buffer_minutes' => 30]);
        $booking = $this->departingToday();
        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '11:20'])->assertOk();
        $this->assertSame(0.0, (float) $booking->fresh()->extra_charges);

        $this->assertSame('4480.00', $this->summary($this->invoice($booking))['Total Payable(Rs)']);

        $booking->update(['deposit_amount' => 4480]);
        $this->completeCheckoutInspection($booking);
        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['bill' => 4480, 'balance_due' => 0, 'can_checkout' => true]);
    }

    public function test_flat_late_checkout_fee_is_charged_in_full_after_the_grace_period(): void
    {
        $this->roomType->update(['late_check_out_fee' => 500, 'late_check_out_type' => 'flat_fee', 'late_check_out_buffer_minutes' => 30]);
        $booking = $this->departingToday();
        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '11:45'])->assertOk();
        $this->assertSame(500.0, (float) $booking->fresh()->extra_charges);

        $this->assertSame('5040.00', $this->summary($this->invoice($booking))['Total Payable(Rs)']);
    }

    public function test_flat_early_check_in_fee_is_not_charged_inside_the_grace_period(): void
    {
        $this->roomType->update(['early_check_in_fee' => 800, 'early_check_in_type' => 'flat_fee', 'early_check_in_buffer_minutes' => 30]);
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(0), $this->day(2));
        $this->postJson("/api/bookings/{$booking->id}/early-checkin", ['time' => '13:45'])->assertOk();
        $this->assertSame(0.0, (float) $booking->fresh()->extra_charges);

        $this->assertSame('4480.00', $this->summary($this->invoice($booking))['Total Payable(Rs)']);
    }

    // ── Checkout inspection lines ───────────────────────────────────────────

    public function test_inspection_charges_are_itemised_and_tax_details_are_grouped_by_rate(): void
    {
        $this->setting('invoice_default_food_gst_rate', '5');
        $booking = $this->departingToday(['extra_charges' => 1605]);
        BookingExtraCharge::query()->create([
            'booking_id' => $booking->id, 'source' => 'inspection', 'kind' => 'minibar',
            'label' => 'Cola', 'qty' => 2, 'unit_amount' => 52.5, 'total_amount' => 105,
        ]);
        BookingExtraCharge::query()->create([
            'booking_id' => $booking->id, 'source' => 'inspection', 'kind' => 'asset_penalty',
            'label' => 'TV remote', 'qty' => 1, 'unit_amount' => 1500, 'total_amount' => 1500,
        ]);

        $invoice = $this->invoice($booking);

        $this->assertSame(
            ['Room Charges', 'Minibar — Cola', 'Damaged / missing — TV remote'],
            array_column($invoice['lines'], 'particular')
        );
        $this->assertSame(['2', '52.50', '105.00', '100.00'], [
            $invoice['lines'][1]['qty'], $invoice['lines'][1]['rate'], $invoice['lines'][1]['total'], $invoice['lines'][1]['taxable'],
        ]);
        $this->assertSame('—', $invoice['lines'][2]['cgst_cell'], 'Damage recovery carries no GST.');
        $this->assertSame([
            ['label' => 'CGST @ 2.5%', 'taxable' => '100.00', 'tax' => '2.50'],
            ['label' => 'CGST @ 6%', 'taxable' => '4000.00', 'tax' => '240.00'],
            ['label' => 'SGST @ 2.5%', 'taxable' => '100.00', 'tax' => '2.50'],
            ['label' => 'SGST @ 6%', 'taxable' => '4000.00', 'tax' => '240.00'],
        ], $invoice['taxDetailRows']);
        $this->assertSame('6085.00', $this->summary($invoice)['Total Payable(Rs)']);
        $this->assertSame('6085.00', $invoice['colTotals']['total'], 'Line totals add up to the payable.');
    }

    public function test_another_guests_inspection_snapshot_is_not_billed(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->makeBlock($room, 'inspected', $this->day(-2), $this->day(0), [
            'inspection_snapshot' => [
                'booking_id' => $booking->id + 100,
                'assets' => [['key' => 'tv', 'label' => 'TV', 'status' => 'damaged', 'qty' => 1, 'unit_damage_charge' => 900]],
            ],
        ]);

        $invoice = $this->invoice($booking);

        $this->assertSame(['Room Charges'], array_column($invoice['lines'], 'particular'));
    }

    public function test_own_inspection_snapshot_lines_show_only_once_posted_to_the_folio(): void
    {
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(-2), $this->day(0), ['status' => 'checked_in']);
        $this->makeBlock($room, 'inspected', $this->day(-2), $this->day(0), [
            'inspection_snapshot' => [
                'booking_id' => $booking->id,
                'assets' => [['key' => 'tv', 'label' => 'TV', 'status' => 'damaged', 'qty' => 1, 'unit_damage_charge' => 900]],
            ],
        ]);

        $this->assertSame(['Room Charges'], array_column($this->invoice($booking)['lines'], 'particular'), 'Not on the folio yet.');

        $booking->update(['extra_charges' => 900]);
        $invoice = $this->invoice($booking);

        $this->assertSame(['Room Charges', 'Damaged / missing — TV'], array_column($invoice['lines'], 'particular'));
        $this->assertSame('5380.00', $invoice['colTotals']['total']);
        $this->assertSame('5380.00', $this->summary($invoice)['Total Payable(Rs)']);
    }

    // ── Discount ────────────────────────────────────────────────────────────

    public function test_checkout_discount_is_a_negative_line_and_reduces_the_payable(): void
    {
        $booking = $this->departingToday(['checkout_discount_amount' => 480, 'checkout_discount_reason' => 'Loyalty']);

        $invoice = $this->invoice($booking);

        $last = end($invoice['lines']);
        $this->assertSame('Checkout discount — Loyalty', $last['particular']);
        $this->assertSame('-480.00', $last['total']);
        $this->assertSame('4000.00', $this->summary($invoice)['Total Payable(Rs)']);
    }

    public function test_checkout_discount_lowers_the_amount_checkout_collects(): void
    {
        $booking = $this->departingToday(['checkout_discount_amount' => 480, 'checkout_discount_reason' => 'Loyalty', 'deposit_amount' => 4000]);
        $this->completeCheckoutInspection($booking);

        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['bill' => 4000, 'balance_due' => 0, 'can_checkout' => true, 'room_total' => 4480, 'folio_extras' => 0]);
        $this->patchJson("/api/bookings/{$booking->id}", ['status' => 'checked_out'])->assertOk();
    }

    // ── Stored vs re-priced room total ──────────────────────────────────────

    public function test_stored_room_total_wins_when_higher_than_the_re_price(): void
    {
        // Checked out after a split stay / extension: header dates collapsed to one day, stored total covers 3 nights.
        $booking = $this->makeBooking($this->makeRoom('101'), $this->day(-1), $this->day(0), [
            'status' => 'checked_out',
            'total_price' => 6720,
            'deposit_amount' => 6720,
        ]);

        $invoice = $this->invoice($booking);

        $this->assertSame('6720.00', $invoice['lines'][0]['total']);
        $this->assertSame('0.00', $this->summary($invoice)['Balance(Rs)']);
    }

    public function test_re_price_wins_when_higher_than_the_stored_total(): void
    {
        $booking = $this->departingToday(['total_price' => 2240]);

        $this->assertSame('4480.00', $this->invoice($booking)['lines'][0]['total']);
    }

    public function test_preview_breakdown_adds_up_to_the_bill(): void
    {
        $this->roomType->update(['late_check_out_fee' => 500, 'late_check_out_type' => 'flat_fee']);
        $booking = $this->departingToday();
        $this->postJson("/api/bookings/{$booking->id}/late-checkout", ['time' => '14:00'])->assertOk();
        $this->postLaundry($booking, 300);

        $this->postJson("/api/bookings/{$booking->id}/preview-checkout")
            ->assertOk()
            ->assertJson(['room_total' => 5040, 'folio_extras' => 300, 'bill' => 5340]);
    }

    // ── Remark and cashier ──────────────────────────────────────────────────

    public function test_staff_notes_never_print_in_the_remark_box(): void
    {
        $booking = $this->departingToday(['notes' => "Guest rude at desk, watch minibar\n[Room Transfer: AC problem by Admin User]"]);

        $this->assertSame('—', $this->invoice($booking)['remark']);
    }

    public function test_cashier_is_whoever_took_the_payment_not_whoever_prints(): void
    {
        $booking = $this->departingToday();
        $this->actingAs($this->userWith(['reservation-view', 'reservation-edit'], 'Ravi Cashier'));
        BookingPaymentLedger::recordPayment($booking, ['amount' => 4480, 'method' => 'cash', 'paid_at' => $this->day(0) . ' 09:00:00']);

        $this->actingAs($this->userWith(['reservation-view', 'reservation-edit'], 'Meena Manager'));

        $this->assertSame('Ravi Cashier', $this->invoice($booking)['cashierName']);
    }

    public function test_issued_invoice_without_payments_has_no_cashier(): void
    {
        $booking = $this->departingToday();
        $booking->forceFill(['invoice_seq' => 1, 'invoice_number' => 'INV-000001', 'invoice_issued_at' => now()])->save();

        $this->assertSame('—', $this->invoice($booking)['cashierName']);
    }

    // ── Split stays ─────────────────────────────────────────────────────────

    public function test_split_stay_names_every_room_and_counts_nights_from_the_segments(): void
    {
        $first = $this->makeRoom('207');
        $booking = $this->makeBooking($this->makeRoom('211'), $this->day(-1), $this->day(0), ['status' => 'checked_in']);
        $booking->segments()->create([
            'room_id' => $first->id,
            'check_in' => $this->day(-2),
            'check_out' => $this->day(-1),
            'check_in_at' => $this->day(-2) . ' 00:00:00',
            'check_out_at' => $this->day(-1) . ' 00:00:00',
            'rate_plan_id' => $booking->rate_plan_id,
            'adults_count' => 2,
            'children_count' => 0,
            'extra_beds_count' => 0,
            'total_price' => 2240,
            'status' => 'checked_out',
        ]);
        // Header dates only cover the last room, as after a transfer.
        $booking->update(['total_price' => 4480]);

        $invoice = $this->invoice($booking);

        $this->assertSame($this->roomType->name . ' / 207 → 211', $invoice['roomLabel']);
        $this->assertSame('2', $invoice['nights']);
        $this->assertStringStartsWith('08/10/2026', $invoice['arrivalStr']);
        $this->assertStringStartsWith('10/10/2026', $invoice['departureStr']);
        $this->assertSame('Room Charges (207: 08/10–09/10, 211: 09/10–10/10)', $invoice['lines'][0]['particular']);
    }

    public function test_nights_come_from_the_billed_segments_when_check_out_shortened_the_header(): void
    {
        $booking = $this->departingToday();
        $booking->update(['check_out' => $this->day(-1), 'check_out_at' => $this->day(-1) . ' 00:00:00']);

        $invoice = $this->invoice($booking);

        $this->assertSame('2', $invoice['nights']);
        $this->assertSame('Room Charges', $invoice['lines'][0]['particular']);
    }

    // ── Groups ──────────────────────────────────────────────────────────────

    /** @return array{0: Booking, 1: Booking} two ₹4,480 rooms in "Sharma Wedding" */
    private function groupOfTwo(): array
    {
        $groupId = \Illuminate\Support\Facades\DB::table('booking_groups')->insertGetId([
            'name' => 'Sharma Wedding',
            'contact_person' => 'Vikram Sharma',
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $a = $this->makeBooking($this->makeRoom('101'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $groupId]);
        $b = $this->makeBooking($this->makeRoom('102'), $this->day(-2), $this->day(0), ['status' => 'checked_in', 'booking_group_id' => $groupId, 'first_name' => 'Neha']);

        return [$a, $b];
    }

    public function test_pooled_group_payment_shows_each_rooms_share(): void
    {
        [$a, $b] = $this->groupOfTwo();
        BookingPaymentLedger::recordPayment($a, ['amount' => 8960, 'method' => 'upi', 'paid_at' => $this->day(0) . ' 09:00:00']);

        $payer = $this->invoice($a);
        $other = $this->invoice($b);

        $this->assertSame(
            ['description' => 'Group payment — paid via ' . \App\Support\BookingNumber::for($a->fresh()) . ' (Room 101)', 'amount' => '4480.00'],
            array_intersect_key($other['paymentRows'][0], ['description' => 1, 'amount' => 1])
        );
        $this->assertSame('0.00', $this->summary($other)['Balance(Rs)']);
        $this->assertSame(['8960.00', '-4480.00'], array_column($payer['paymentRows'], 'amount'));
        $this->assertStringContainsString('applied to ' . \App\Support\BookingNumber::for($b->fresh()) . ' (Room 102)', $payer['paymentRows'][1]['description']);
        $this->assertSame('0.00', $this->summary($payer)['Balance(Rs)']);
        $this->assertSame('Group booking: Sharma Wedding (2 rooms)', $other['remark']);
    }

    public function test_rooms_paying_their_own_bills_get_no_group_share(): void
    {
        [$a, $b] = $this->groupOfTwo();
        BookingPaymentLedger::recordPayment($a, ['amount' => 4480, 'method' => 'cash']);
        BookingPaymentLedger::recordPayment($b, ['amount' => 2000, 'method' => 'cash']);

        $this->assertCount(1, $this->invoice($a)['paymentRows']);
        $this->assertCount(1, $this->invoice($b)['paymentRows']);
        $this->assertSame('2480.00', $this->summary($this->invoice($b))['Balance(Rs)']);
    }

    public function test_group_invoice_consolidates_every_room(): void
    {
        [$a, $b] = $this->groupOfTwo();
        BookingPaymentLedger::recordPayment($a, ['amount' => 8960, 'method' => 'upi']);

        $invoice = ReservationInvoiceViewData::buildGroup($b->fresh());

        $this->assertSame('Group Proforma Invoice', $invoice['documentTitle']);
        $this->assertSame('SHARMA WEDDING', $invoice['billToName']);
        $this->assertSame('VIKRAM SHARMA', $invoice['guestName']);
        $this->assertSame('Rooms 101, 102', $invoice['roomLabel']);
        $this->assertSame('4 (A) / 0 (C)', $invoice['personsLabel']);
        $this->assertSame(['Room 101 — Room Charges', 'Room 102 — Room Charges'], array_column($invoice['lines'], 'particular'));
        $this->assertSame([1, 2], array_column($invoice['lines'], 'sr'));
        $this->assertSame([['label' => 'CGST @ 6%', 'taxable' => '8000.00', 'tax' => '480.00'], ['label' => 'SGST @ 6%', 'taxable' => '8000.00', 'tax' => '480.00']], $invoice['taxDetailRows']);
        $this->assertSame(['Room 101 — UPI — Payment'], array_column($invoice['paymentRows'], 'description'));
        $this->assertSame('8960.00', $this->summary($invoice)['Total Payable(Rs)']);
        $this->assertSame('0.00', $this->summary($invoice)['Balance(Rs)']);
    }

    public function test_group_invoice_endpoint(): void
    {
        [$a] = $this->groupOfTwo();
        $solo = $this->makeBooking($this->makeRoom('103'), $this->day(-1), $this->day(0), ['status' => 'checked_in']);

        $response = $this->get("/api/bookings/{$a->id}/billing?scope=group")->assertOk();

        $this->assertStringContainsString('Group_Proforma_Sharma_Wedding.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->getJson("/api/bookings/{$solo->id}/billing?scope=group")->assertStatus(422);
    }
}
