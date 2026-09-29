---
name: housekeeping
description: Changes housekeeping workflows in passion-api — turnover cleaning on room_status_blocks (dirty, cleaning, inspected, maintenance), checkout inspection (pending_inspection, charges to folio), cleaning release windows and daily stay-over cleaning (RoomCleaningRelease, RoomCleaningAvailabilityService, DailyRoomCleaning), staff assignment, laundry and room stock. Use when working on housekeeping, dirty rooms, cleaning tasks, cleaning releases, daily room cleaning, checkout inspection, laundry or room par in passion-api.
---

# Housekeeping (passion-api)

## 1. When to use the skill
- Any change to `HousekeepingController`, `RoomCleaningReleaseController`, `RoomCleaningAvailabilityService`,
  `LaundryRequestController`, `HousekeepingRoomStockController`, `RoomParController`, `HousekeepingChecklistController`.
- Changing room/HK state transitions or HK permissions.

## 2. Required investigation before coding
1. `PASSION_LARAVEL_ARCHITECTURE.md` §36.
2. Identify which of the three sub-workflows you touch:
   - **A. Turnover** (`room_status_blocks` + `HousekeepingJob`): `assignCleaningStaff()`, `startCleaning()`,
     `upsertJob()`, `finish()`, `markInspected()`, `markCleaned()`.
   - **B. Checkout inspection**: `checkoutInspectionValidate()/Apply()/Clear()` + `BookingController::requestInspection()`.
   - **C. Release windows + daily cleaning**: `RoomCleaningReleaseController` → `RoomCleaningAvailabilityService`;
     `HousekeepingController::dailyCleaningIndex()/dailyCleaningUpdateStatus()/dailyCleaningRecordConsumption()`.
3. Read `app/Http/Controllers/Concerns/AuthorizesHousekeepingPermissions.php`.
4. Find all writers of the state you change: `rg -n "'status' => '(dirty|cleaning|inspected)'" app`.

## 3. Existing project patterns to follow
- HK controllers `use AuthorizesHousekeepingPermissions; use AuthorizesSpatiePermissions;` and inject
  services with constructor promotion (`private readonly RoomCleaningAvailabilityService ...`).
  Exceptions: `RoomParController` mostly uses its own private `checkPermission()` (Admin bypass);
  `HousekeepingRoomStockController` adds `requireHkDepartmentForUser()` (department `HKP`, Admin exempt).
- Turnover state: block `dirty` → (`assigned_to` required) `cleaning` → `finish()` → `inspected` (inactive) +
  room `available`, or `maintenance` block (asset `needs_repair|missing`). Room `status` updated alongside the block.
- `finish()` runs in manual `DB::beginTransaction()`: checks consumed qty ≤ room-location on-hand
  (`inventory_item_locations`, `lockForUpdate`), decrements, writes `inventory_transactions`
  (`reference_type='housekeeping'`), posts minibar as POS `room_charge`; returns 500 with message on exception.
- Release state lives in `RoomCleaningRelease::STATUS_*` (`available → in_progress → completed →
  inspection_pending → ready`, plus `expired`, `cancelled`) with `RoomCleaningReleaseAudit` rows; service
  methods `releaseForCleaning()`, `markCleaningStarted()`, `markCleaningCompleted()`, `markInspectionCompleted()`,
  `markRoomReady()`, `extendWindow()`, `rescheduleWindow()`, `cancelRelease()`.
- Service throws `InvalidArgumentException`; controller catches → 422 `{message}`.
- Overdue windows expire lazily: `expireOverdueWindows()` on board reads (no scheduler).
- Daily cleaning status `pending_cleaning → in_progress → cleaned`; requires an active release, and
  `in_progress` needs an open window (`assertCanStartCleaning()`); rooms without an occupied segment are
  rejected with "uses the Dirty Rooms workflow".
- Checkout inspection charges → `booking_extra_charges` `source='inspection'` + `BookingChargesUpdated`.
- Every state change ends with `HousekeepingStateUpdated::dispatchIfEnabled([$roomIds], 'snake_reason')`
  (`start_cleaning`, `finish_cleaning`, `mark_inspected`, `mark_cleaned`, `cleaning_released`, ...).

## 4. Step-by-step implementation workflow
```
- [ ] 1. Pick sub-workflow A, B or C and read its methods end-to-end
- [ ] 2. Authorization with the controller's existing helper (section 6) at the top
- [ ] 3. Guards: block is_active, expected current status, assignee present → 422 {message}
- [ ] 4. Workflow C logic goes in RoomCleaningAvailabilityService (transaction + audit row inside the service)
- [ ] 5. Workflow A logic stays in HousekeepingController, same transaction style as neighbours
- [ ] 6. Update block status AND rooms.status together; sync the linked active RoomCleaningRelease
- [ ] 7. Stock moves: inventory_item_locations with lockForUpdate + inventory_transactions (reference_type
        `housekeeping`, `housekeeping_room_stock`, `daily_room_cleaning`, `checkout_inspection`, `room_par`)
        + syncStoredCurrentStockFromLocations()
- [ ] 8. Broadcast HousekeepingStateUpdated (and RoomParStockUpdated for stock) via dispatchIfEnabled()
- [ ] 9. New status value: widen rooms / room_status_blocks / daily_room_cleanings ENUM with a migration
- [ ] 10. Tests
```

## 5. Validation requirements
- Inline `$request->validate()`; release priority/service type use
  `'in:'.implode(',', CleaningReleasePriority::values())` and `CleaningServiceClassification::types()`.
- Assignee must be an active user in an active department with `is_housekeeping = true` (`housekeepingAssignableStaff()`).
- Consumption quantities validated against room-location on-hand inside the transaction.

## 6. Authorization requirements
- Constants in `AuthorizesHousekeepingPermissions`: `HK_DIRTY`, `HK_CHECKOUT`, `HK_CLEANING`, `HK_DAILY`,
  `HK_CLEAN`, `HK_SUPERVISOR_INSPECTION`, `HK_LAUNDRY`, `HK_ROOM_STOCK`, `HK_CLEANING_AVAILABILITY`, `HK_ASSIGNABLE`.
- Operations: `allowHousekeepingOperate([self::HK_DIRTY, self::HK_CLEANING])` (start/finish),
  `[self::HK_CLEAN]` (mark inspected), `[self::HK_CHECKOUT]` (inspection), `[self::HK_DAILY]` (daily status).
- Assignment: `assertCanAssignHousekeepingStaff()` (`housekeeping-assignable`).
- Releases: `authorizePermissions([self::HK_CLEANING_AVAILABILITY])`; supervisor inspect `HK_SUPERVISOR_INSPECTION`.
- Trait-style controllers (`HousekeepingController`, `HousekeepingChecklistController`,
  `HousekeepingRoomStockController`, `LaundryRequestController`, `RoomCleaningReleaseController`): no Admin bypass.
- `RoomParController`: its own `checkPermission()` with Admin bypass (passes when no user) for 12 of 13
  checks — copy whichever style the neighbouring method uses.
- Room stock: keep `requireHkDepartmentForUser()` → 403 `'Only Housekeeping department users can manage room stock.'`

## 7. Testing requirements
- Use `Tests\Concerns\CreatesHousekeepingFixtures` (schema via `MigratesHousekeepingTestSchema`):
  `createUserWithPermission('housekeeping-cleaning-availability')`, `createRoom()`, `createActiveRelease()`,
  `releaseWindowPayload()`; `Sanctum::actingAs()` + `postJson('/api/housekeeping/...')`.
- Pass permission names as strings — `AuthorizesHousekeepingPermissions::HK_*` cannot be read from outside a
  class (this is why `RoomCleaningReleasePriorityTest` and `RoomCleaningAvailabilityServicePriorityTest`
  currently error; `CleaningReleasePriorityTest` passes).
- Service logic → `tests/Unit/Services/*` (e.g. `DailyRoomCleaningClassificationServiceTest`).

## 8. Verification checklist
- [ ] Authorization matches the controller (trait helpers, or `RoomParController::checkPermission()`);
      room-stock department check kept; assignee in an `is_housekeeping` department enforced
- [ ] Block status, `rooms.status` and linked `RoomCleaningRelease` stay consistent
- [ ] Release changes write `RoomCleaningReleaseAudit` rows
- [ ] Stock moves locked, logged in `inventory_transactions`, item total re-synced
- [ ] Broadcast after commit with snake_case reason
- [ ] Response shape matches siblings (e.g. block with `room.roomType`, `assignedUser:id,name`)
- [ ] HK tests pass vs baseline
