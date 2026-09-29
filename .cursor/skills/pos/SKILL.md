---
name: pos
description: Changes POS / F&B behavior in passion-api — orders, items, KOT/BOT and kitchen actions, settle, refund, void, amend payment, room charge, tables, business date and day closing — inside PosController, DayClosingController, BusinessDateService, DayClosingService and the POS accounting posters, keeping outlet access, the closed-day lock and journal atomicity. Use when working on POS, restaurant outlets, kitchen display, table status, POS payments or day closing in passion-api.
---

# POS and day closing (passion-api)

## 1. When to use the skill
- Adding or changing a `PosController` action (orders, items, kitchen, settle/refund/void/amend, reports).
- Changing business-date resolution, the closed-day lock, day close/unlock, or table status side effects.
- For the room-folio side of a POS room charge also read the `billing` skill.

## 2. Required investigation before coding
1. Rule `55-pos.mdc` and `PASSION_LARAVEL_ARCHITECTURE.md` (POS / day-closing sections).
2. Find the route in the `Route::prefix('pos')` blocks of `routes/api.php` and read the controller method.
3. Read the sibling action closest to your change end-to-end (e.g. `settle()`, `refund()`, `void()`,
   `voidItems()`, a kitchen batch method) including the helpers at the top of `PosController`:
   `checkPermission()`, `userCanAccessRestaurant()`, `authorizeOrderAccess()`, `authorizeRestaurantId()`,
   `businessDateStringForOrder()`, `assertBusinessDateOpenForRestaurant()`, `assertBusinessDateOpenForPos()`,
   `broadcastPosOutletUpdate()`, `broadcastBookingFolioAfterPosRoomCharge()`.
4. For journals read `app/Services/Accounting/PosSettlePoster.php` / `PosRefundPoster.php` and
   `LedgerBackedTransaction`.
5. Check the POS / kitchen UI in `../passion/src` for the keys and messages it reads.

## 3. Existing project patterns to follow
- Action order: `checkPermission('pos-…')` → `authorizeOrderAccess($order)` / `authorizeRestaurantId($id)`
  → status guard (422) → `assertBusinessDateOpenForPos($order, 'Cannot …')` for mutations →
  `$request->validate()` → work → `broadcastPosOutletUpdate()` → response.
- Business date: `BusinessDateService::resolve($restaurant, $at)` (outlet `business_day_cutoff_time`,
  default 04:00); stored on orders/payments/refunds as `business_date`.
- Locks: `PosOrder::where('id', …)->lockForUpdate()` inside the transaction and re-check status;
  `RestaurantMaster` row lock in `openOrder()` and day close.
- Journals: settle → `LedgerBackedTransaction::run()` + `PosSettlePoster::postStrict()`; amend →
  `repost()`; paid void → `reverse()`; refund → `DB::transaction` + `PosRefundPoster::post()`.
- Errors: 422 `{message}` returns before the transaction; `HttpResponseException` thrown inside it;
  kitchen methods return early responses inside `DB::transaction` before writes.
- Broadcasts: `broadcastPosOutletUpdate()` immediate; room charge → `broadcastBookingFolioAfterPosRoomCharge()` (deferred).

## 4. Step-by-step implementation workflow
```
- [ ] 1. Pick the sibling action and read it end-to-end
- [ ] 2. Permission helper (checkPermission / checkAnyPermission / checkKitchenActionPermission)
- [ ] 3. Outlet access: authorizeOrderAccess() or authorizeRestaurantId()
- [ ] 4. Mutation? assertBusinessDateOpenForPos() with a specific 'Cannot …' message
- [ ] 5. Validate inline; business guards → 422 {message}
- [ ] 6. Transaction: lock the order, re-check status; journal via the sibling's poster/transaction style
- [ ] 7. Side effects: table status, inventory deduction, booking extra_charges for room_charge
- [ ] 8. broadcastPosOutletUpdate(); folio broadcast for room charge
- [ ] 9. Return the sibling's response shape (e.g. formatOrder())
- [ ] 10. Tests
```

## 5. Validation requirements
- Inline `$request->validate()`. Settle payments: `payments.*.method in:cash,card,upi,room_charge`,
  `payments.*.amount numeric|min:0.01`; discount/service-charge percent ≤ 100.
- `room_charge` only for `room_service` orders with a linked booking, and the booking must still be
  `checked_in` inside the transaction.
- Outlet ids validated with `exists:restaurant_masters,id`; tables must belong to the outlet.

## 6. Authorization requirements
- `PosController` / `DayClosingController` private `checkPermission()` (401 when no user; Admin + Super Admin bypass).
- Elevated actions have their own permission: `pos-discount`, `pos-void-item`, `pos-refund`,
  `pos-reopen-order`, `pos-amend-payment`, `pos-kds-force-clear`, `pos-business-date-override`,
  `pos-day-closing`, `pos-day-closing-unlock`, `pos-day-closing-override`.
- Outlet access is mandatory for anything taking an order or outlet id.
- New POS permission: seeder list + permission migration granting `Admin`, `Super Admin`, `Outlet Manager`
  (the existing POS permission migrations do this).

## 7. Testing requirements
- There are no `PosController` feature tests. Business-date / sequential-close logic has unit tests:
  `tests/Unit/DayClosingServiceTest.php` (plain `PHPUnit\Framework\TestCase`, anonymous subclass
  overriding `lastClosedDate()`).
- Journal posters: `tests/Unit/AccountingPostersPhaseATest.php` (self-skips without accounting tables —
  say so if you could not exercise it).
- Run `php vendor/bin/phpunit` before/after and compare with the baseline.

## 8. Verification checklist
- [ ] Permission helper + outlet access at the top, same as siblings
- [ ] Mutations blocked on closed business dates (`assertBusinessDateOpenForPos()`)
- [ ] Business date from `BusinessDateService` / `businessDateStringForOrder()`, not `today()`
- [ ] Order row locked and status re-checked inside the transaction
- [ ] Journal posted in the same transaction using the sibling's poster method
- [ ] `room_charge` guard kept; booking `extra_charges` updated; folio broadcast deferred
- [ ] `broadcastPosOutletUpdate()` fired; response shape unchanged
