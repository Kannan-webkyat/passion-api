---
name: check-in-check-out
description: Changes guest check-in, pre-checkout inspection, check-out, early check-in/late checkout and early checkout in passion-api, where check-in/out are PATCH /bookings/{id} status transitions in BookingController::update() with arrival-day, dirty-room and paid-before-checkout guards, an atomic checkout journal, and post-commit segment/room/housekeeping side effects. Use when working on check-in, check-out, departure, request-inspection, early check-in, late checkout or early checkout in passion-api.
---

# Check-in / check-out (passion-api)

## 1. When to use the skill
- Changing what happens when a booking moves to `checked_in` or `checked_out`.
- Working on `POST /bookings/{id}/request-inspection`, `early-checkin`, `late-checkout`,
  `preview-early-checkout`, `early-checkout`.

## 2. Required investigation before coding
1. `PASSION_LARAVEL_ARCHITECTURE.md` §34 (and §35 for money, §36 for housekeeping handoff).
2. Read `BookingController::update()` end-to-end (≈560 lines) — guards, ledger postings, transaction,
   then post-commit segment/room/HK sync.
3. Read `requestInspection()`, `earlyCheckin()`, `lateCheckout()`, `previewEarlyCheckout()`, `applyEarlyCheckout()`.
4. Read `app/Services/Accounting/BookingCheckoutPoster.php` and `BookingInvoiceRoomStay::summarizeForInvoice()`.
5. Check the reception UI in `../passion/src/app/(portal)/reception` for the payload it sends (`status`, `checkout_scope`, `refund_amount`, `refund_method`).

## 3. Existing project patterns to follow
- **No dedicated endpoints**: check-in = `PATCH /bookings/{id}` `{status: 'checked_in'}` (or create with
  `status=checked_in`); check-out = `PATCH` `{status: 'checked_out'}`.
- **Check-in guards** (in `update()`): arrival calendar day (`bookingArrivalCalendarDay()`) must be today;
  reject if an active `dirty`/`cleaning` block covers today on `booking.room_id`.
- **Check-in effects** (post-commit): all segments → `checked_in` (continuous-stay semantics), all segment
  rooms → `occupied`. No daily-cleaning row is created at check-in (it's created on cleaning release) and
  no broadcast is fired; `booking_checkin` only appears in the unused `syncDailyCleaningOnCheckIn()`.
- **Pre-checkout inspection**: `requestInspection()` requires `checked_in` and today = segment checkout day;
  deactivates prior `pending_inspection` (by `inspection_snapshot->booking_id`) and dirty/cleaning/inspected
  blocks, creates `pending_inspection` blocks with `inspection_snapshot {booking_id, room_id, segment_id}`,
  sets rooms `pending_inspection`, broadcasts `request_inspection`.
- **Check-out guards**: refund needs `refund_method`; paid check — `payment_status=paid`, else
  `deposit_amount + 0.009 >= max(effectiveBookingGrand, total_price)`; group bookings use pooled group
  balance (`checkout_scope=group`, default) or per-room (`checkout_scope=room`). Failure →
  `422 'Checkout not allowed until payment is fully paid'`.
- **Early departure via PATCH**: future `check_out` is truncated to today, `check_out_at` to tomorrow 00:00,
  `[Early CO: on {date} by {Name}]` appended to notes.
- **Money before the transaction**: refund delta → `BookingPaymentLedger::recordRefund(source: 'checkout', allow_closed: true)`.
- **Atomic checkout**:
  ```php
  DB::transaction(function () use ($booking, $validated, $isNewCheckout) {
      $booking->update($validated);
      if ($isNewCheckout) {
          app(BookingCheckoutPoster::class)->post($booking->fresh(['room.roomType.tax']), auth()->id());
      }
  });
  ```
- **Check-out effects** (post-commit): segments → `checked_out`; rooms → `dirty`; per segment deactivate
  `inspected`/`pending_inspection` and overlapping `dirty`/`cleaning` blocks, then create a one-day `dirty`
  block on the checkout date (`note => 'Auto: checkout'`) if none remains; `HousekeepingStateUpdated::dispatchIfEnabled($ids, 'booking_checkout')`.
- Early check-in / late checkout endpoints only record time + fee (`extra_charges`) + audit note; they don't change status.

## 4. Step-by-step implementation workflow
```
- [ ] 1. Locate the branch in update() (check-in guard ~"Check-in only on the guest's scheduled arrival date",
         checkout guard ~"Checkout validation: must be paid", post-commit "Sync room status")
- [ ] 2. Add guards before the transaction as early-return 422 {message}
- [ ] 3. Money changes via BookingPaymentLedger (before the transaction, like the refund delta)
- [ ] 4. Anything that must commit with the status flip goes inside the existing DB::transaction
- [ ] 5. Room/segment/HK side effects go in the existing post-commit section, for ALL segment rooms
- [ ] 6. Audit line to notes for new behaviors
- [ ] 7. Broadcast with dispatchIfEnabled() and a snake_case reason
- [ ] 8. Keep the response: booking with room.roomType.tax, creator, bookingGroup (+ guest identity meta)
```

## 5. Validation requirements
- Keep the `update()` rule set: `status in:pending,confirmed,checked_in,checked_out,cancelled`,
  `refund_method in:cash,card,upi,bank_transfer`, `checkout_scope in:room,group`,
  `checkout_discount_amount` only while `checked_in`.
- Status transitions to `cancelled` via PATCH are rejected (use `/cancel`).

## 6. Authorization requirements
- `update()`, `requestInspection()`, early check-in / late checkout / early checkout: `allowReservationEdit()` (`reservation-edit`).
- Housekeeping side of inspection is authorized in `HousekeepingController` (`HK_CHECKOUT`).

## 7. Testing requirements
- There are no controller tests for check-in/out. Money/availability pieces are covered in
  `tests/Unit/Support/BookingPaymentLedgerTest.php` and `BookingRoomAvailabilityTest.php`; checkout journal
  math in `tests/Unit/AccountingPostersPhaseATest.php` (skips under SQLite without accounting tables).
- If adding a Feature test for `PATCH /api/bookings/{id}`, build bookings/segments/rooms/blocks/users/Spatie tables
  in the test and assert `rooms.status`, segment status and the `dirty` block row.

## 8. Verification checklist
- [ ] Check-in still limited to arrival day and blocked by active dirty/cleaning block
- [ ] Checkout still requires full payment per group/room scope
- [ ] Status flip + `BookingCheckoutPoster::post()` in one transaction
- [ ] All segments and all segment rooms updated
- [ ] One-day `dirty` block created on each segment's checkout date
- [ ] Refunds recorded through the ledger with `allow_closed`
- [ ] Broadcast after commit; response shape unchanged
- [ ] The functional doc for this change is created or updated (`.cursor/rules/65-functional-docs.mdc`)
