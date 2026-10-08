---
name: reservation
description: Changes reservation behavior in passion-api — booking create (single/group), edit, extend, early/late fees, split stay, room transfer, cancellation — inside BookingController and the app/Support/Booking* helpers, keeping booking segments, audit notes, payment ledger and room state consistent. Use when working on bookings, booking segments, booking groups, reservation create/update/cancel/extend/transfer, or the room chart data in passion-api.
---

# Reservation workflow (passion-api)

## 1. When to use the skill
- Creating, editing, extending, splitting, transferring or cancelling bookings.
- Changing `bookings` / `booking_segments` / `booking_groups` fields or the room chart/summary endpoints.
- For availability logic itself use `room-availability`; for check-in/out use `check-in-check-out`;
  for money use `billing`.

## 2. Required investigation before coding
1. `PASSION_LARAVEL_ARCHITECTURE.md` §32 (reservations) and §33 (availability).
2. Read the relevant `BookingController` method: `store()`, `update()`, `storeGroup()`,
   `extendReservation()`, `extendHourlyReservation()`, `applyEarlyCheckout()`, `earlyCheckin()`,
   `lateCheckout()`, `splitStay()`, `cancelReservation()`, `roomTransfer()`, `chart()`, `summary()`.
3. Read the helpers it calls: `BookingRoomAvailability`, `BookingCancellationPolicy`,
   `BookingRoomTransferService`, `SeasonalRoomPricing`, `BookingPaymentLedger`, `GuestIdentityImageService`.
4. Check which segments/rooms the change affects (split stays have several segments and rooms).
5. `rg` the frontend (`../passion/src/app/(portal)/reception`) for fields you touch.

## 3. Existing project patterns to follow
- All reservation logic is in `BookingController` + static `app/Support/Booking*`; there is no booking service.
- Authorization via `allowReservation*()` wrappers over `authorizePermissions()`; single vs group create is
  decided from raw `room_ids` / `group_name` **before** validation in `store()`.
- Dates: `parseHotelDateTime()` → app timezone; day stays at midnight; write both `check_in`/`check_out`
  and `check_in_at`/`check_out_at`.
- Create: pre-check `assertSellable()` → per room `assertCapacity()` + price → `withRoomLocks([$roomId], fn)`
  → re-`assertSellable()` → `Booking::create` + `BookingSegment::create` → when the ledger is enabled,
  zero `deposit_amount` with `forceFill()` and re-record it via `BookingPaymentLedger::recordPayment(source: 'booking_create')`.
- Cancel writes `payment_status` / `cancellation_fee_amount` directly, and the deposit/refund scalars only
  when the ledger is disabled; keep that.
- Update: guards → ledger postings for deposit/refund → `appendAuditNotesForBookingUpdate()` → date/occupancy
  re-checks → `DB::transaction { update (+ checkout poster) }` → segment sync → room status → HK blocks → broadcast.
- Audit lines appended to `notes`: `[Extension: ...]`, `[Early CI: ...]`, `[Late CO: ...]`, `[Split Stay: ...]`,
  `[Early Checkout: ...]`, `[Reservation created: ...]` — format `[Tag: details by {Name} on Y-m-d H:i:s]`.
- Cancel only via `POST /bookings/{id}/cancel` (`pending|confirmed` only) using `BookingCancellationPolicy::preview()`
  and ledger `source => 'cancellation'` inside one `DB::transaction`.
- Room moves via static `App\Support\BookingRoomTransferService::preview()/execute()` (returns
  `['ok' => bool, 'message' => ...]`, one `DB::transaction`). Only `confirmed` / `checked_in` bookings.
  - Pre-arrival swap (`confirmed`, before arrival): the existing segment's `room_id`/price are updated in place.
  - Otherwise: the active segment is closed (`check_out_at` = transfer time, status `checked_out`) and a
    new segment is created on the target room.
  - `applyHousekeeping()` runs only for `checked_in` bookings: source room → `dirty` plus a dirty block.
  - `isRoomAvailable()` rejects any active block on the target (stricter than `BookingRoomAvailability`).

## 4. Step-by-step implementation workflow
```
- [ ] 1. Identify the path (create / update / extend / transfer / cancel / split) and read it end-to-end
- [ ] 2. Authorization: reuse the matching allowReservation*() wrapper (add a new one only for a new capability)
- [ ] 3. Normalize + validate inline (GSTIN upper/trim, bill_to_name trim as in store/update)
- [ ] 4. Guards → 422 {message}; availability ValidationException → flattened {message}
- [ ] 5. Availability: new stay/room/date changes use BookingRoomAvailability; existing paths keep their own check
- [ ] 6. Write booking + segments together; keep DATE and DATETIME columns aligned
- [ ] 7. Money only through BookingPaymentLedger (see billing skill)
- [ ] 8. Append a bracketed audit line to notes
- [ ] 9. Sync rooms.status for all segment rooms; HK blocks; HousekeepingStateUpdated::dispatchIfEnabled()
- [ ] 10. Return the same shape as today (booking array with room.roomType.tax, creator, bookingGroup)
- [ ] 11. Tests
```

## 5. Validation requirements
- Inline rules like `store()`/`update()`: `status in:pending,confirmed,checked_in,checked_out,cancelled`,
  `booking_unit in:day,hour_package`, GSTIN regex, `exists:rooms,id`, `exists:rate_plans,id`.
- Existing business rules to keep: no past-date creation; `checked_in` only on arrival day; day bookings
  need `check_out`; breakfast counts ≤ guest counts; room change on confirmed/checked-in needs room transfer;
  `status=cancelled` via PATCH rejected; checkout discount only while checked in (≤ gross, reason ≥ 3 chars).
- Cancellation reasons from `BookingCancellationPolicy::REASONS`; refund/extra methods `in:cash,card,upi,bank_transfer`.

## 6. Authorization requirements
- `reservation-create` (single) / `reservation-create-group` (multi-room or `group_name`, and `POST /booking-groups`).
- `reservation-edit` for update/extend/fees/split/transfer/payments; `reservation-delete` for cancel and destroy.
- Read: `reservation-view` or legacy `reservation`; chart also accepts room view permissions.
- No Admin bypass in this controller.

## 7. Testing requirements
- Rules in Support classes → extend `tests/Unit/Support/BookingRoomAvailabilityTest.php`,
  `BookingCancellationPolicyTest.php`, `BookingPaymentLedgerTest.php`.
- There are no `BookingController` feature tests today. If adding one, build `users`, Spatie tables, `room_types`,
  `rooms`, `bookings`, `booking_segments`, `room_status_blocks` in the test (see `MigratesHousekeepingTestSchema`).
- Run `php vendor/bin/phpunit` before/after.

## 8. Verification checklist
- [ ] Correct `allowReservation*()` wrapper is the first statement
- [ ] Booking and all its segments agree on room/dates/status/occupancy/price
- [ ] DATE and DATETIME columns aligned; hotel timezone used
- [ ] Availability checked for new stay windows; locks on create
- [ ] No new direct deposit/refund scalar writes when the ledger is enabled (existing ones in `store()` / `cancelReservation()` / `update()` kept)
- [ ] Room transfer: pre-arrival vs mid-stay branch and checked-in-only housekeeping preserved
- [ ] Audit line appended to `notes`
- [ ] `rooms.status` updated for every room across segments; broadcast fired after commit
- [ ] Response shape unchanged; frontend fields checked
- [ ] Known gaps (post-transaction side effects in `update()`) not silently changed
- [ ] The functional doc for this change is created or updated (`.cursor/rules/65-functional-docs.mdc`)
