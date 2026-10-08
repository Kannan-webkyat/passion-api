---
name: laravel-api-development
description: Workflow for any backend change in the passion-api Laravel project (Passion Hotel PMS) — locating the owning module, matching its existing pattern, schema/permission migrations, and verifying with the project's PHPUnit harness. Use when implementing a feature, bug fix or change in passion-api that is not covered by a more specific skill (api-endpoint, reservation, room-availability, check-in-check-out, billing, housekeeping, pos).
---

# Laravel API development in passion-api

## 1. When to use the skill
- Any code change in `passion-api` (controllers, Support/Service classes, models, migrations, seeders, tests).
- Start here, then switch to the specific skill if the task is about an endpoint, reservations,
  availability, check-in/out, billing, housekeeping or POS / day closing.

## 2. Required investigation before coding
0. **Doc check first** (`.cursor/rules/65-functional-docs.mdc`): find the module's doc in `docs/`. If the
   module has none, create it from the current code before changing anything.
1. Read the matching section of `PASSION_LARAVEL_ARCHITECTURE.md` (topic sections §4–§36).
2. Find the route in `routes/api.php` and open the controller method it points to.
3. Read the method's private helpers and any Support/Service class it calls.
4. Classify the module (from `05-existing-architecture`): controller-inline, static Support,
   instance Service, or `LedgerBackedTransaction`. Your change uses the same pattern.
5. Find every writer of the columns/statuses you touch:
   `rg -n "'status' => 'dirty'|extra_charges" app` (adapt the pattern).
6. Check frontend usage of any endpoint/key/permission you touch: `rg -n "<path or key>" ../passion/src`.
7. Run the test baseline: `php vendor/bin/phpunit` and note current errors/skips.

## 3. Existing project patterns to follow
- Inline `$request->validate()`; `$request->merge()` for normalization; 422 `{message}` guards.
- Authorization helper as the first statement (style depends on controller — see `40-security`).
- Dependencies: follow the controller's existing style (constructor promotion, method injection, or `app(X::class)`).
- Transactions: same style as the surrounding code. Broadcasts: same timing as the module — deferred
  via `dispatchIfEnabled()` / `App::terminating()` for HK and booking-folio events, immediate for POS
  outlet updates (`broadcastPosOutletUpdate()`, `PosOutletBroadcast`).
- Migrations: anonymous classes, `Schema::has*` guards where the DB state may be unknown, raw
  `ALTER TABLE ... MODIFY COLUMN` for ENUMs.
- New permissions: always a `RolePermissionSeeder::permissionNames()` entry (created at runtime by
  `RoleController::ensureCanonicalPermissionsExist()`); plus a permission migration when existing roles
  must be granted it on deploy, as the POS permissions do (see `40-security`).

## 4. Step-by-step implementation workflow
```
- [ ] 1. Investigation (section 2) done; module pattern identified
- [ ] 2. Schema change? add a new anonymous migration (see 20-database); ask before editing an existing one
- [ ] 3. New status value? widen the MySQL ENUM + update every `in:` rule / constant / match
- [ ] 4. New permission? add to RolePermissionSeeder::permissionNames(); add a permission migration
         if roles must receive it on deploy (follow sibling permissions of the module; ask if unclear)
- [ ] 5. Implement in the owning controller / Support / Service, copying the neighbour method's style
- [ ] 6. Side effects: rooms.status, room_status_blocks, bookings.notes audit line, broadcast
- [ ] 7. Tests at the level where the logic lives (see section 7)
- [ ] 8. Verification checklist (section 8)
```
Permission migration template, when one is needed (shape of `2026_09_22_085400_add_pos_mini_dash_permission.php`):
```php
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'module-action', 'guard_name' => 'web']);
        foreach (['Admin', 'Super Admin'] as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'module-action')->where('guard_name', 'web')->delete();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
```
The role list varies per migration (the POS ones grant `Admin`, `Super Admin`, `Outlet Manager`;
`add_pos_mini_dash_permission` also grants roles that already have `report-sales`). Pick the roles the
task names; ask if unclear.

## 5. Validation requirements
- Inline `$request->validate([...])` in the action (or the controller's existing `validatePayload()` helper).
- Allowed values from existing constants where they exist (`BookingCancellationPolicy::REASONS`,
  `BookingPaymentLedger::METHODS`, `CleaningReleasePriority::values()`), otherwise inline `in:` lists
  matching the DB ENUM.
- Business rules after validation → `return response()->json(['message' => '...'], 422)`, or the
  exception style already used in that class.

## 6. Authorization requirements
- Use the controller's existing helper (`authorizePermissions`/`allow*()`, `checkPermission`, or `InventoryAuthorization::assert*()`).
- Don't change Admin-bypass behavior. Don't remove legacy permission names from any-of lists.

## 7. Testing requirements
- Support/Service logic → unit test in `tests/Unit/Support` or `tests/Unit/Services` with tables created in `setUp()`.
- Endpoint behavior (auth, status, shape) → Feature test with `Sanctum::actingAs()`; for HK use `CreatesHousekeepingFixtures`.
- Reference permissions by string in tests. Run `php vendor/bin/phpunit` and compare with the baseline.

## 8. Verification checklist
- [ ] Change lives in the module's existing layer; no new architectural layer added
- [ ] Authorization helper is the first statement; style matches the controller
- [ ] Response keys/status codes of existing endpoints unchanged (or change called out)
- [ ] Migrations are new anonymous files (existing ones edited only with approval), guarded where needed, with `down()`
- [ ] New permission is in the seeder list; migration added if roles must be granted it on deploy
- [ ] Broadcasts use the module's existing timing (deferred HK/folio, immediate POS outlet)
- [ ] Test suite: no new failures vs baseline
- [ ] Open questions about possibly-intentional behavior listed for the user
- [ ] Doc check done before coding: the module's doc was found, or created from the current code first (`.cursor/rules/65-functional-docs.mdc`)
- [ ] That doc now matches this change and is named in the final message
