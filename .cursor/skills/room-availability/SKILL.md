---
name: room-availability
description: Uses or changes room availability / sellability rules in passion-api — BookingRoomAvailability (segment overlap, hard and check-in-only status blocks, capacity, row locks), the available-rooms lookup, room chart and summary counts, and the per-path availability variants. Use when working on room availability, overbooking, room blocks (maintenance, on_hold, dirty, cleaning), capacity/extra beds, available-rooms search or the room chart in passion-api.
---

# Room availability (passion-api)

## 1. When to use the skill
- Deciding whether a room can be sold/checked-in for a window.
- Changing `BookingRoomAvailability`, `getAvailableRooms()`, `chart()`, `summary()`, or block statuses.
- Adding a new path that places a guest in a room.

## 2. Required investigation before coding
1. Read `app/Support/BookingRoomAvailability.php` fully (≈260 lines).
2. Read every availability variant you may affect:
   - `BookingController::store()` (pre-check + `withRoomLocks` re-check), `update()` (date change, no lock)
   - `BookingController::getAvailableRooms()` (Eloquent re-implementation with the class constants)
   - `BookingController::extendReservation()` / `extendHourlyReservation()` (own segment overlap → 409, `on_hold` → 422)
   - `BookingRoomTransferService::isRoomAvailable()` (segments + **any** active block)
   - `BookingController::splitStay()` (no check)
   - `update()` check-in guard (active `dirty`/`cleaning` block today → 422)
3. Read `tests/Unit/Support/BookingRoomAvailabilityTest.php`.
4. Check `rooms.status` / `room_status_blocks.status` ENUMs in migrations if statuses change.

## 3. Existing project patterns to follow
- Occupancy comes from `booking_segments` (datetime overlap `check_in_at < end AND check_out_at > start`),
  excluding `INACTIVE_SEGMENT_STATUSES` = `cancelled, checked_out, completed`, optionally excluding one booking.
- Blocks from active `room_status_blocks` with date overlap `start_date < endExclusive AND end_date > startDate`
  (`end_date` exclusive; `dateEndExclusiveFromDateTime()`: midnight checkout = same date, else next day).
- `HARD_BLOCK_STATUSES` = `maintenance, on_hold` → never sellable.
  `CHECKIN_ONLY_BLOCK_STATUSES` = `dirty, cleaning` → sellable for future stays, block `checked_in`.
- `assertSellable()` throws `ValidationException(['room_id' => ...])`; controllers flatten to `{message}` 422.
- Concurrency: `withRoomLocks($roomIds, fn)` / `lockAndAssertSellable()` sort ids and `lockForUpdate()` rooms inside `DB::transaction`.
- Capacity: `capacityErrors()` / `assertCapacity()` (adults+children ≤ `capacity`; extra beds from
  `base_occupancy`, `child_sharing_limit`, `extra_bed_capacity`).
- `rooms.status` is **not** used for availability.

## 4. Step-by-step implementation workflow
```
- [ ] 1. Decide scope: canonical rule change (BookingRoomAvailability) vs one path's variant
- [ ] 2. Canonical change: edit the class + constants; then update getAvailableRooms() which mirrors them
- [ ] 3. List the other variants (section 2) and ask whether they should follow; do not change them silently
- [ ] 4. New placement path: assertSellable() (+ assertCapacity()), and withRoomLocks() for creation
- [ ] 5. Keep ValidationException → {message} 422 flattening at the controller boundary
- [ ] 6. New block status: widen ENUM, add to the right constant, update RoomStatusBlockController rules + authorize*(), chart/summary
- [ ] 7. Unit tests in BookingRoomAvailabilityTest
```

## 5. Validation requirements
- `getAvailableRooms()` validates `check_in`/`check_out` as `date` without `after:` (hourly same-day stays);
  equal date-only values become end-of-day. Keep this.
- Manual blocks via `RoomStatusBlockController` accept only `in:maintenance,dirty,cleaning,on_hold`;
  `pending_inspection` / `inspected` are created by booking/HK workflows, not this endpoint.

## 6. Authorization requirements
- Lookup: `allowAvailableRoomsLookup()` (`reservation-create`, `reservation-create-group`, `reservation-edit`, `reservation`, `view-rooms`).
- Chart/summary: `allowReservationChartRead()`.
- Blocks: `RoomStatusBlockController::authorizeStatusBlockStore()` / `authorizeStatusBlockMutation()` —
  `reservation-hold-room` for `on_hold`, `reservation-maintenance-room` for `maintenance`, otherwise any of
  `manage-rooms`, `housekeeping-dirty-rooms`, `housekeeping-cleaning-tasks`.

## 7. Testing requirements
- Extend `tests/Unit/Support/BookingRoomAvailabilityTest.php` (it builds its tables in `setUp()` and adds
  capacity columns via `ensureRoomTypeCapacityColumns()`).
- Existing cases to keep green: overlap blocks second booking, cancelled segment doesn't block, dirty allows
  confirmed but blocks check-in, maintenance blocks, capacity checks, exclude-booking on update.

## 8. Verification checklist
- [ ] Rule change made in `BookingRoomAvailability` and mirrored in `getAvailableRooms()`
- [ ] Other variants reviewed and either unchanged or changed with explicit approval
- [ ] Block `end_date` treated as exclusive; segment overlap uses datetimes
- [ ] Creation path uses `withRoomLocks()`
- [ ] Availability not derived from `rooms.status`
- [ ] `BookingRoomAvailabilityTest` passes
