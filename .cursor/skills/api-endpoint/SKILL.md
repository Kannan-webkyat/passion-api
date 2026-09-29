---
name: api-endpoint
description: Adds or changes a JSON endpoint in passion-api following the project's existing route, controller, inline-validation, authorization-helper and response-shape conventions (no Form Requests, no API Resources, no policies). Use when adding a route, a controller action, a preview/action endpoint, or changing an endpoint's request or response in passion-api.
---

# API endpoint (passion-api)

## 1. When to use the skill
- Adding a route to `routes/api.php` or a method to an existing controller.
- Changing request fields, validation, authorization, status codes or response keys of an endpoint.

## 2. Required investigation before coding
1. Find sibling endpoints in the same controller; the new endpoint copies the closest one.
2. Note the controller's authorization style (trait `authorizePermissions` / `allow*()`,
   private `checkPermission`, or `InventoryAuthorization`), its error style, and DI style.
3. Check route ordering in `routes/api.php`: custom collection paths must be declared before the
   resource's `apiResource()` (e.g. `bookings/guest-search` before `Route::apiResource('bookings', ...)`).
4. Search the frontend for the path/keys: `rg -n "/bookings/.*/payments" ../passion/src`.

## 3. Existing project patterns to follow
- All protected routes go in the single `Route::middleware(['auth:sanctum', 'active'])` group, under the module's comment.
- State changes: `POST /{resource}/{id}/{kebab-verb}`; previews: `POST /{resource}/{id}/preview-{verb}`.
- Implicit model binding; parameter name = camelCase model (`{roomStatusBlock}`) — follow the module's existing names.
- Method body order: authorize → normalize (`$request->merge`) → `validate` → guards (422) → work → response.
- Responses: bare model/array, 201 on create, `{message, ...named keys}` for actions. Deletes mostly
  return `null` + 204, but some return 200 `{message}` (`UserController::destroy()` deactivates,
  `HousekeepingChecklistController::destroy()`) — copy the controller's own style.

Trait-style controller (`BookingController::voidPayment()`):
```php
public function voidPayment(Request $request, Booking $booking, BookingPayment $payment)
{
    $this->allowReservationEdit();

    if ((int) $payment->booking_id !== (int) $booking->id) {
        return response()->json(['message' => 'Payment does not belong to this booking.'], 404);
    }
    // ...status guards → 422...
    $validated = $request->validate([
        'reason' => 'nullable|string|max:500',
        'bill_total' => 'nullable|numeric|min:0',
    ]);
    // ...work...
    return response()->json(['message' => 'Payment voided.', 'payment' => $row, /* ... */]);
}
```
`checkPermission`-style controller (`PaymentMethodController::store()`):
```php
$this->checkPermission('manage-settings');
$validated = $request->validate([...]);
$method = PaymentMethod::create($validated);
return response()->json($method, 201);
```

## 4. Step-by-step implementation workflow
```
- [ ] 1. Pick the sibling endpoint to mirror
- [ ] 2. Add the route in the module's block (respect ordering before apiResource)
- [ ] 3. Add the controller method with the controller's auth helper as line 1
- [ ] 4. Inline validation; normalize with $request->merge() first if siblings do
- [ ] 5. Business guards → 422 {message}; ownership mismatch → 404 like voidPayment
- [ ] 6. Do the work in the module's existing layer (inline / Support / Service)
- [ ] 7. Return the sibling's response shape and status code
- [ ] 8. Broadcast if siblings do, with the same helper/timing (deferred HK/folio, immediate POS outlet)
- [ ] 9. Feature test for auth + status + key paths
```

## 5. Validation requirements
- Inline `$request->validate()`; pipe strings; `Rule::in()` / `'in:'.implode(',', X::values())` for constant lists.
- Hotel IDs/relations validated with `exists:table,id`.
- Don't use `after:check_in` on stays that can be hourly (see comment in `BookingController::getAvailableRooms()`).
- Manual `errors` object only where siblings already return one (breakfast counts in `BookingController`).

## 6. Authorization requirements
- First statement of the method, using the controller's own helper.
- Booking endpoints: reuse/add an `allowReservation*()` wrapper. HK: `allowHousekeepingOperate([self::HK_...])`.
- Permission for a new capability → add it to `RolePermissionSeeder::permissionNames()`; add a permission
  migration too when existing roles must be granted it on deploy (see `40-security`).
- POS/day-closing endpoints with an order/outlet → `authorizeOrderAccess()` / `authorizeRestaurantId()`
  (and `assertBusinessDateOpenForPos()` for mutations; see the `pos` skill).
- HK room-stock endpoints → keep `requireHkDepartmentForUser()`.
- Read endpoints that are currently unchecked stay unchecked unless the task says otherwise.

## 7. Testing requirements
- Feature test: `Sanctum::actingAs($user)`, call `$this->postJson('/api/...')`, assert status,
  `assertJsonPath()` on keys the frontend reads, `assertDatabaseHas()`.
- Build required tables in the test (or via `CreatesHousekeepingFixtures` for HK); no `RefreshDatabase`.
- Cover: forbidden without permission (403), happy path, main 422 guard.

## 8. Verification checklist
- [ ] Route in the auth group, correct position relative to `apiResource`
- [ ] Auth helper first; same style as controller
- [ ] Validation inline; values match DB ENUM / constants
- [ ] Error body `{message}`; status codes match siblings
- [ ] Response shape matches sibling; existing endpoints' keys unchanged
- [ ] No FormRequest / JsonResource / Policy / middleware added
- [ ] Frontend impact checked in `../passion`
- [ ] Tests pass vs baseline
