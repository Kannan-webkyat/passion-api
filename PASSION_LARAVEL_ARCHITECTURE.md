# Passion Hotel PMS — Laravel API: Architecture As It Exists

> Scope: factual description of `passion-api` as found on 2026-09-29 (branch with HEAD `6c3db47`, 251 commits).
> No recommendations. Where a pattern differs between modules, the difference is documented rather than normalized.
> File/line references are to the current working tree.

---

## 0. Executive snapshot

| Aspect | What exists |
|---|---|
| Framework | Laravel **12.53.0** (`laravel/framework ^12.0`), Laravel 11+ slim skeleton (`bootstrap/app.php`, no `Kernel.php`) |
| PHP | `^8.2` required; local CLI is PHP 8.4.22 |
| App type | Pure JSON API for a separate Next.js frontend (`passion/`), plus PDF/CSV/XLSX downloads and Reverb broadcasts |
| Size | 48 controllers (≈43k LOC), 79 models, 56 service classes (`app/Services` + `app/Services/Accounting`), 14 `app/Support` classes, 266 migrations, 44 seeders, 30 test files |
| Dominant pattern | **Fat controllers** with inline `$request->validate()`, inline authorization helpers, direct Eloquent/`DB::table` queries, and hand-built JSON arrays. Services/Support classes are used for *specific* domain rules (availability, payment ledger, accounting postings, GRN, cleaning releases), not as a uniform layer. |
| Absent layers | No Form Requests, no API Resources, no Policies/Gates, no Repositories, no Actions, no Enums (PHP `enum`), no Jobs, no Listeners, no Notifications, no Observers, no custom Traits dir, no route-level permission middleware, no API versioning |
| Tenancy | **Single property.** No `hotel_id` / `property_id` / `tenant_id` anywhere. Only F&B has an *outlet* dimension (`restaurant_masters`). |

Largest files (these are where most business logic lives):

| File | Lines |
|---|---|
| `app/Http/Controllers/PosController.php` | 11,189 |
| `app/Http/Controllers/HousekeepingController.php` | 3,756 |
| `app/Http/Controllers/BookingController.php` | 3,750 |
| `app/Http/Controllers/InventoryReportController.php` | 1,897 |
| `app/Services/PropertyFinancialSummaryService.php` | 1,073 |
| `app/Http/Controllers/DayClosingController.php` | 905 |
| `app/Http/Controllers/RoomParController.php` | 898 |
| `app/Http/Controllers/HousekeepingRoomStockController.php` | 827 |
| `app/Services/GrnService.php` | 813 |

---

## 1. Laravel / framework version

- `laravel/framework` constraint `^12.0`; installed **12.53.0** (`php artisan --version`).
- Skeleton is the Laravel 11+ "slim" layout:
  - `bootstrap/app.php` configures routing, broadcasting, middleware aliases and exception rendering.
  - `bootstrap/providers.php` registers only `AppServiceProvider` (empty `register()`/`boot()`).
  - No `app/Http/Kernel.php`, no `app/Console/Kernel.php`, no `app/Exceptions/Handler.php`.
- `composer.json` `name` is still `laravel/laravel` (skeleton default).
- `allow-plugins` lists `pestphp/pest-plugin`, but Pest is **not** installed; tests use PHPUnit.

## 2. PHP version and important dependencies

**Runtime (`require`)**

| Package | Version | Used for |
|---|---|---|
| `php` | `^8.2` | Code uses constructor property promotion, `readonly`, `match`, named args, first-class enums are *not* used |
| `laravel/sanctum` | `^4.0` | API token auth (personal access tokens) |
| `spatie/laravel-permission` | `^6.24` | Roles + permissions (guard `web`) |
| `laravel/reverb` | `^1.9` | WebSocket broadcasting (`BROADCAST_CONNECTION=reverb`) |
| `barryvdh/laravel-dompdf` | `^3.1` | PDF invoices, vouchers, report PDFs (`Pdf::loadView`) |
| `phpoffice/phpspreadsheet` | `^5.5` | XLSX exports (inventory excise report, POS reports) |
| `laravel/tinker` | `^2.10.1` | — |

**Dev (`require-dev`)**: `barryvdh/laravel-ide-helper` (generated `_ide_helper.php`, `_ide_helper_models.php` are committed at repo root), `fakerphp/faker`, `laravel/pail`, `laravel/pint`, `laravel/sail`, `mockery/mockery`, `nunomaduro/collision`, `phpunit/phpunit ^11.5.3`.

**Composer scripts**: `composer dev` runs `php artisan serve`, `queue:listen --tries=1`, `pail`, `reverb:start`, and `npm run dev` concurrently. `composer test` → `config:clear` + `artisan test`.

**Environment (`.env`, keys only)**: `DB_CONNECTION=mysql`, `APP_TIMEZONE=Asia/Kolkata`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, `BROADCAST_CONNECTION=reverb`, `FILESYSTEM_DISK=local`, `MAIL_MAILER=log`, `LOG_CHANNEL=stack` with `LOG_STACK=single`.

## 3. Overall folder structure

```
passion-api/
├── app/
│   ├── Console/Commands/        14 one-off data-repair / backfill commands (inventory, KGST, POS)
│   ├── Events/                  5 broadcast-only events (ShouldBroadcastNow)
│   ├── Exceptions/              JournalPostingException, LiquorTaxValidationException
│   ├── Http/
│   │   ├── Controllers/         48 controllers (flat, no sub-namespaces except Concerns/)
│   │   │   └── Concerns/        AuthorizesSpatiePermissions, AuthorizesHousekeepingPermissions (traits)
│   │   └── Middleware/          EnsureUserIsActive (only custom middleware)
│   ├── Models/                  79 Eloquent models (flat)
│   ├── Providers/               AppServiceProvider (empty)
│   ├── Services/                41 classes (domain services, calculators, policies, configs, presenters)
│   │   └── Accounting/          15 classes (journal posting, posters, account codes, trial balance)
│   └── Support/                 14 classes (static helpers for bookings, cleaning, pricing, invoices)
├── bootstrap/app.php            routing / middleware / exception config
├── config/                      16 files, incl. custom `guest_identity.php`, `services.qz`
├── database/
│   ├── migrations/              266 anonymous-class migrations
│   ├── seeders/                 44 seeders + `seeders/data/*.php` catalog arrays
│   ├── factories/               UserFactory only
│   └── sql/                     2 ad-hoc maintenance SQL scripts
├── docs/                        GRN_MODULE.md, PASSION_ERP_WORKFLOW_SOP.md
├── resources/views/             Blade templates for PDFs only (bookings/*, reports/*) + welcome
├── routes/                      api.php, channels.php, console.php, web.php
├── scripts/                     standalone PHP scripts (repair_inflated_btl_stock.php, e2e_hotel_flow_test.php)
├── tests/                       Unit (24), Feature (3), Concerns (2 fixture/schema traits)
└── .cursor/rules/grn-frozen-cost.mdc   repo rule: GRN cost read-only sources
```

Also at root: `debug_coffee.php`, `debug_consumption.php`, `fix_plan.md`, `passion_db` (artifacts, not referenced by the app).

**`app/Services` vs `app/Support`** — there is no enforced rule, but in practice:
- `app/Support/*` are almost all `final class` with **only static methods** (booking availability, payment ledger, cancellation policy, invoice math, room transfer, cleaning priority/classification).
- `app/Services/*` mixes instance services (resolved via container) and static policy/config classes (`InventoryAuthorization`, `BomDeductionConfig`, `KgstBarTotPolicy`, `LiquorTaxValidator`, …).
- `BookingRoomTransferService` lives in `Support/` despite the `Service` suffix.

## 4. Routes and API route organization

- `routes/api.php` (377 lines) — **one file, one group**:
  ```php
  Route::post('/login', [AuthController::class, 'login']);
  Route::middleware(['auth:sanctum', 'active'])->group(function () { ... everything else ... });
  ```
- Default `api` prefix → all endpoints are `/api/...`. Health check at `/up`.
- `routes/web.php` only returns the `welcome` view.
- `routes/console.php` defines one closure command `pos:recalculate-tax-splits` (which resolves `PosController` from the container and calls its public `maintenanceRecalculateOrderTotals()`), plus the default `inspire`.
- `routes/channels.php` defines 5 private broadcast channels (see §16).

**Route styles in use (mixed within the same file):**

| Style | Example |
|---|---|
| `Route::apiResource()` | `room-types`, `rooms`, `room-status-blocks`, `users`, `roles`, `departments`, `bookings`, `restaurant-masters`, `tables`, `table-categories`, `table-reservations`, `menu-*`, `payment-methods`, `inventory/items|categories|uoms|taxes|vendors|locations|store-requests|purchase-orders|grns|procurement-requisitions|cess-slabs` |
| `apiResource()->parameters()` | `housekeeping/checklist-master` → `{housekeepingChecklistItem}` |
| `apiResource()->except()` | `grns` (no destroy), `cess-slabs` (no show) |
| Explicit verb routes to named methods | `POST bookings/{booking}/cancel`, `POST housekeeping/blocks/{roomStatusBlock}/finish` |
| `Route::prefix()->group()` | `pos`, `qz`, `inventory` (the `inventory` and `pos` prefixes are opened **twice** each) |
| `Route::match(['put','post'])` | `settings/receipt` |
| `->whereNumber()` | `bookings/{booking}/folio-orders/{order}` |

**Action-endpoint convention**: state transitions are `POST /{resource}/{id}/{verb}` (`/cancel`, `/extend`, `/early-checkin`, `/settle`, `/void`, `/refund`, `/approve`, `/issue`, `/start-cleaning`, `/mark-inspected`…). Previews are separate `POST /{resource}/{id}/preview-{verb}` endpoints (`preview-cancellation`, `preview-early-checkout`, `preview-room-transfer`, `preview-extend-hours`).

**Ordering sensitivity**: custom collection routes are declared *before* `apiResource` so they are not captured by `{id}` (e.g. `bookings/guest-search`, `bookings/chart`, `bookings/summary` before `Route::apiResource('bookings', …)`). `bookings/available-rooms` is declared after several `bookings/{booking}/…` routes but before the resource.

**Route model binding**: implicit binding by type-hint everywhere (`Booking $booking`, `RoomStatusBlock $roomStatusBlock`, `PosOrder $order`). Parameter names follow the route segment (`{roomCleaningRelease}`, `{laundryRequest}`, `{procurement_requisition}` snake-case in one module). A few methods take raw ids instead (`RecipeController::upsert($menuItemId)`, `showInventoryRecipe(int $inventoryItemId)`, `produce($recipeId)`).

**Grouping by comment only**: `// Room Types`, `// Bookings & Room Chart`, `// POS Module`, etc. Housekeeping routes are under the "Bookings & Room Chart" comment.

## 5. Authentication mechanism

- **Sanctum personal access tokens** (Bearer). `User` uses `HasApiTokens`.
- `POST /api/login` (`AuthController::login`):
  1. `$request->validate(['email','password','device_name'])`.
  2. Looks up user, `Hash::check`.
  3. **Always** writes a `LoginAttempt` row (`email`, `successful`, `ip_address`, `user_agent`, `created_at`).
  4. Failure → `ValidationException::withMessages(['email' => …])` (422).
  5. Inactive user (`is_active=false`) → 422 with deactivation message.
  6. Success → `{ token, user (with departments, restaurants), permissions: [names], roles: [names] }`.
- `GET /api/me` flushes the Spatie permission cache (`PermissionRegistrar::forgetCachedPermissions()`), unsets loaded role/permission relations, returns the same shape minus `token`.
- `POST /api/logout` deletes the current token only.
- `config/sanctum.php`: `expiration => null` (tokens never expire), `guard => ['web']`, stateful domains from env. CORS (`config/cors.php`) whitelists localhost:3000 and `*.passions.in`, `supports_credentials: true`.
- `EnsureUserIsActive` middleware (alias `active`) runs on every authenticated request: if `is_active` is false it **deletes the current token** and returns `403 {"message":"Your account has been deactivated."}`.
- `bootstrap/app.php`: `redirectGuestsTo(fn () => null)` so unauthenticated API calls return 401 JSON instead of redirecting.
- User deactivation (`UserController::deactivateUser`) sets `is_active=false` and deletes **all** tokens.

## 6. Authorization / policies / permissions / roles

### 6.1 What exists
- **Spatie `laravel-permission`**, guard **`web`** (explicitly; comments warn that legacy `sanctum`-guard rows must be ignored). `teams => false`. Cache 24h via default store.
- Config uses Spatie's own models (`Spatie\Permission\Models\Role|Permission`). `app/Models/Role.php` and `app/Models/Permission.php` exist but reference non-Spatie pivot tables (`role_permission`, `role_user`, `role_permissions`) and are **not referenced anywhere** in `app/`, `routes/` or `database/`.
- **No Policies, no Gates, no `$this->authorize()`**, no `permission:`/`role:` route middleware (aliases are registered in `bootstrap/app.php` but unused).
- Authorization is **imperative, at the top of each controller method**.

### 6.2 Permission catalog
- Canonical list: `Database\Seeders\RolePermissionSeeder::permissionNames()` (static). `RoleController::permissions()` calls `ensureCanonicalPermissionsExist()` which `firstOrCreate`s every name from that list at request time.
- Naming: kebab-case, `{module}-{action}` or `{module}-{area}`: `reservation-view`, `reservation-create-group`, `rooms-edit`, `housekeeping-dirty-rooms`, `grn-approve`, `pos-settle`, `pos-discount`, `pos-day-closing-unlock`, `inventory-report-ledger`, `report-rooms-performance`, `accounting-vendor-pay`. Legacy coarse names still honoured alongside granular ones: `manage-rooms`, `view-rooms`, `reservation`, `manage-inventory`, `manage-grn`, `manage-settings`, `manage-users`, `manage-menu`.
- **New permissions after go-live are added by migrations** that `Permission::firstOrCreate([... 'guard_name' => 'web'])`, grant to named roles (`Admin`, `Super Admin`, `Outlet Manager`, or roles that already have a related permission) and call `forgetCachedPermissions()` (e.g. `2026_09_22_085400_add_pos_mini_dash_permission.php`).
- Role templates: `DefaultHotelRolesSeeder::rolePermissionMap()` (`Admin` ⇒ all permissions; `Waiter`, `Cashier`, `Kitchen Staff`, `Store Keeper`, `Store Manager`, `Outlet Manager`, `Accounts`, …). `Housekeeping` role is created in `RbacTestUsersSeeder`. `Super Admin` is referenced in code/migrations but not created by any seeder found.

### 6.3 Three coexisting authorization helper styles

| Style | Where | Admin bypass? | Failure |
|---|---|---|---|
| **A. `AuthorizesSpatiePermissions` trait** → `$this->authorizePermissions([...anyOf])` | `BookingController`, `RoomController`, `RoomTypeController`, `RoomStatusBlockController`, `RoomCleaningReleaseController`, `HospitalityReportController`, `HousekeepingChecklistController`, `HousekeepingController`, `LaundryRequestController`, `HousekeepingRoomStockController`, `RoomParController` (partly) | **No bypass** (docblock: "Admin users follow the Admin role's assigned permissions") | `abort(403, 'You do not have permission to perform this action.')` |
| **B. Per-controller `private function checkPermission(string)`** (copy-pasted) | Most F&B, inventory, settings, users/roles controllers | **`Admin` role bypass**; some copies also bypass **`Super Admin`** (`PosController`, `DayClosingController`, `AccountingController`, `TableController`, `DietaryTypeController`, `InventoryTaxController`, `MenuAvailabilityController`, `RestaurantMasterController`, `RecipeController`, `InventoryReportController`) | `abort(403, 'Unauthorized action.')`; some variants `abort(401)` when no user, others silently pass when `$user` is null (`if ($user && ! hasRole && ! can)`) |
| **C. `App\Services\InventoryAuthorization`** (static, `final`) → `assertViewCatalog()`, `assertInspectGrn()`, `assertApproveGrn()`, `assertPayVendor()`, `canAny()` … | Inventory, GRN, procurement, vendors, locations, stock movements, accounting | **`Admin` + `Super Admin`** bypass (`isPrivileged`) | `abort(403, $message)` |

Variations on B:
- `MenuCategoryController` etc. treat `manage-menu` as satisfying `menu-configuration`.
- `AccountingController::checkPermission` lets `manage-inventory`/`accounting-vendor-pay` satisfy trial-balance.
- `PosController` adds `checkAnyPermission(array)` and `checkKitchenActionPermission()` (`kitchen-production` OR `pos-order`).

**Domain-specific helpers built on top of A** (named `allow*`):
- `BookingController`: `allowReservationChartRead()`, `allowReservationRead()`, `allowReservationCreateSingle()`, `allowReservationCreateGroup()`, `allowReservationEdit()`, `allowReservationDelete()`, `allowReservationBillingExport()`, `allowAvailableRoomsLookup()`. Single vs group create is decided by inspecting `room_ids`/`group_name` *before* validation.
- `AuthorizesHousekeepingPermissions` trait: permission constants (`HK_DIRTY`, `HK_CHECKOUT`, `HK_CLEANING`, `HK_DAILY`, `HK_CLEAN`, `HK_SUPERVISOR_INSPECTION`, `HK_LAUNDRY`, `HK_ROOM_STOCK`, `HK_CLEANING_AVAILABILITY`, `HK_ASSIGNABLE`, `HK_STAFF_ROLE='Housekeeping'`) and helpers `allowHousekeepingNav()`, `allowHousekeepingViewSection()`, `allowHousekeepingOperate()`, `assertCanAssignHousekeepingStaff()`, `housekeepingAssignableStaff()` (users with role `Housekeeping`). This trait **depends on** `AuthorizesSpatiePermissions` being used in the same class.

**Row-level / scope authorization**:
- F&B outlet access: `PosController::userCanAccessRestaurant()` / `authorizeOrderAccess()` / `authorizeRestaurantId()` — Admin/Super Admin all outlets; else explicit `restaurant_user` assignments; else outlets whose `department_id` is in the user's departments (or null). Duplicated in `DayClosingController`, `routes/channels.php` (`pos.restaurant.{id}`) and `PropertyFinancialSummaryService`.
- Status-dependent permissions: `RoomStatusBlockController::authorizeStatusBlockStore()` requires `reservation-hold-room` for `on_hold`, `reservation-maintenance-room` for `maintenance`, HK/room perms for `dirty`/`cleaning`.
- Hard-coded role guards: only an `Admin` can assign/modify/deactivate `Admin` users or the `Admin` role; the last active admin cannot be deactivated.

**Endpoints without a permission check (authenticated only)**: e.g. `MenuCategoryController::index/show`, `DepartmentController::index/show`, `UserController::index/show`, `RoleController::index/show`, `TableCategoryController::index`, `QzSignController`, `AdminDashboardController::financialSummary` (scopes inside the service).

## 7. Controllers

- All extend the empty `abstract class Controller`. Flat namespace `App\Http\Controllers` (+ `Concerns/`).
- Naming: `{Singular}Controller` for resources (`BookingController`, `RoomController`), `{Area}Controller` for modules (`HousekeepingController`, `PosController`, `DayClosingController`, `InventoryReportController`).
- **Responsibilities inside controllers** (typical): authorization → input normalization (`$request->merge(...)`) → `$request->validate([...])` → business rules → Eloquent / `DB::table` writes → side effects (room status, HK blocks, events) → hand-shaped JSON.
- Dependency access:
  - Constructor promotion with `private readonly` in newer HK/day-closing controllers (`HousekeepingController`, `RoomCleaningReleaseController`, `HousekeepingChecklistController`, `DayClosingController`, `HospitalityReportController`, `MenuAvailabilityController`, `MenuItemController`, `MenuPricingController`).
  - Method injection (`AccountingController::trialBalance(Request, TrialBalanceService)`, `AdminDashboardController::financialSummary(Request, PropertyFinancialSummaryService)`).
  - Service locator `app(X::class)` inline (most common in `PosController`, `GrnController` — `app(GrnService::class)` ×17, `BookingController`).
  - Static calls to `Support`/policy classes (`BookingRoomAvailability::assertSellable()`, `BookingPaymentLedger::recordPayment()`, `InventoryAuthorization::assertManage()`).
- Private helper methods inside controllers are extensive (e.g. `PosController::recalculate()`, `formatOrder()`, `executeDeduction()`, `deductOrderItemInventory()`; `BookingController::appendAuditNotesForBookingUpdate()`, `computeHourlyPackageTotal()`, `effectiveBookingGrand()`).
- **Controllers calling controllers**: `routes/console.php` resolves `PosController` and calls `maintenanceRecalculateOrderTotals()`; `DayClosingController::maintenanceComputeSummary()` is public for commands.
- `BookingController::syncDailyCleaningOnCheckIn()` is defined but never called (comment in `update()` says daily cleaning is now created on cleaning release).

## 8. Form Requests and validation

- **No `FormRequest` classes. No `Validator::make`.** 188 inline `$request->validate([...])` calls.
- Rule syntax: pipe strings predominantly (`'required|exists:rooms,id'`); arrays used when `Rule::in()` or `'in:'.implode(',', X::values())` is needed (10 `Rule::` usages; e.g. `GrnController`, `RoomCleaningReleaseController`).
- Allowed-value lists come from: inline strings (`'status' => 'in:pending,confirmed,checked_in,checked_out,cancelled'`), model constants (`GRN::REJECTION_REASONS`, `BookingRoomTransfer::REASONS`), support constants (`BookingCancellationPolicy::REASONS`, `CleaningReleasePriority::values()`, `CleaningServiceClassification::types()`).
- **Pre-validation normalization** via `$request->merge()` (e.g. `guest_gstin` upper-cased/trimmed, `bill_to_name` trimmed, SKU / item code normalization helpers `mergeNormalizedSku()`, `mergeNormalizedItemCode()`).
- Some controllers centralize rules in private helpers: `GrnController::validatePayload()`, `HousekeepingChecklistController::validatePayload()`, `RecipeController::validateRecipePayload()`, `RestaurantMasterController::validateRestaurant()`, `PurchaseOrderController::poHeaderChargeRules()/poLineRules()`, `HospitalityReportController::validatedRange()`.
- **Post-validation business validation** is returned in one of three ways (mixed, sometimes in the same method):
  1. `return response()->json(['message' => '...'], 422);` (most common)
  2. `throw ValidationException::withMessages([...])` (23 places — in `Support/*`, `AuthController`, `RoomStatusBlockController`, `BookingController::store`)
  3. `throw new HttpResponseException(response()->json([...], 422))` from inside transactions (35 places, mostly `PosController`)
- `BookingController` catches `ValidationException` thrown by `BookingRoomAvailability` and re-emits it as `{"message": firstError}` 422 (flattening the `errors` bag).
- One place returns a Laravel-like `errors` object manually (breakfast counts in `BookingController::store/update`).

## 9. Models and Eloquent relationships

- 78 models `extends Model`, `User extends Authenticatable`. Flat namespace.
- `$fillable` everywhere; **no `$guarded`**. No `SoftDeletes`. `HasFactory` only on `User`, `InventoryLocation`, `InventoryTax`, `StoreRequest`, `StoreRequestItem`.
- Casts via `protected $casts = [...]` (property) in most models; `User` uses `casts()` method. Money columns cast `'decimal:2'` in some models (`BookingPayment`, `RoomType`, `BookingRoomTransfer`) but not others (`Booking.total_price`, `deposit_amount` are uncast). JSON columns cast `'array'` (`guest_identities`, `inspection_snapshot`, `meta`, `checklist_done`, `amenities`).
- Custom `$table`: `BookingExtraCharge` (`booking_extra_charges`), `GRN` (`grns`), `InventoryCostAuditLog` (`inventory_cost_audit_log`), `PosVoidWaste` (`pos_void_waste`).
- `$timestamps = false`: `GrnAuditLog`, `JournalLine`, `LoginAttempt`, `RoomCleaningReleaseAudit` (they set `created_at` manually).
- Relationship return types: 22 models use typed returns (`: BelongsTo`, `: HasMany`) — mainly newer ones (`BookingPayment`, `RoomCleaningRelease`, `DailyRoomCleaning`, `BookingRoomTransfer`, GRN, accounting). Older models have untyped relation methods.
- Relationship ordering baked into relation: `Booking::payments()` orders by `paid_at, id`; `Booking::roomTransfers()` orders by `transferred_at desc`.
- **Status constants** on models (newer modules only): `BookingPayment::TYPE_*`, `RoomCleaningRelease::STATUS_*` + `QUEUE_STATUSES`, `RoomCleaningReleaseAudit::ACTION_*`, `GRN::STATUS_*` + reason/quality maps, `JournalEntry::STATUS_*`, `LaundryRequest::STATUS_*`, `HousekeepingChecklistItem`, `ServiceChecklistItem`, `Recipe`, `GrnAttachment`. Older models (`Booking`, `Room`, `RoomStatusBlock`, `PosOrder`, `DailyRoomCleaning`) use **raw strings** with no constants.
- Scopes: only `BookingPayment::scopeActive()`.
- Accessors: `Booking::getGuestNameAttribute()` + `$appends = ['guest_name']`; `RoomType::getSeasonalPricesAttribute()` + `$appends = ['seasonal_prices']` with `$hidden = ['seasons']`.
- `toArray()` override: `RoomCleaningRelease` (date formatting).
- **Business logic on models** (limited): `Setting::get/set` (+ cache) and profile getters, `InventoryItem::sumQuantityAcrossLocations()/syncStoredCurrentStockFromLocations()`, `BookingPayment::signedAmount()`, `PurchaseOrder::payableAmount()`, `GrnItem::frozenCostSnapshot()`, `BookingRoomTransfer::reasonLabel()`.
- No model events, observers or `booted()` hooks.

**Core PMS relationships**

```
RoomType 1─* Room            RoomType 1─* RatePlan        RoomType 1─* RoomTypeSeason   RoomType *─1 InventoryTax (tax_id)
Room *─1 RoomParTemplate     Room *─1 Room (connected_room_id)
Room 1─* Booking (legacy room_id)   Room 1─* BookingSegment   Room 1─* RoomStatusBlock   Room 1─* RoomCleaningRelease
Booking *─1 BookingGroup     Booking *─1 RatePlan          Booking *─1 User (created_by)
Booking 1─* BookingSegment   Booking 1─* BookingPayment    Booking 1─* BookingRoomTransfer   Booking 1─* LaundryRequest
BookingExtraCharge *─1 Booking (no inverse relation on Booking)
PosOrder *─1 Booking / Room / RestaurantMaster / RestaurantTable
RoomStatusBlock 1─1 HousekeepingJob 1─* HousekeepingJobLine
RoomCleaningRelease *─1 Room / Booking / RoomStatusBlock / DailyRoomCleaning ; 1─* RoomCleaningReleaseAudit
DailyRoomCleaning *─1 Room / Booking ; 1─* DailyRoomCleaningConsumption
User *─* Department (department_user)   User *─* RestaurantMaster (restaurant_user)   User HasRoles (Spatie)
```

Models without a corresponding use: `App\Models\Role`, `App\Models\Permission` (see §6). Tables without a model: `outlets`, `stock_returns`, `combo_items` (pivot), `inventory_item_locations` (pivot, accessed via `DB::table`), `procurement_requisition_item_vendors` (pivot).

## 10. API Resources / transformers

- **No `JsonResource` classes.** Output shaping uses four patterns:
  1. **Return model/collection directly** (auto-serialized with loaded relations): `return Booking::with([...])->get();`, `return $room->load('roomType');`, `response()->json($model, 201)`.
  2. **Private `format*()` methods in controllers**: `PosController::formatOrder()`, `LaundryRequestController::formatRequest()`, `RecipeController::formatRecipePayload()/formatMenuItemRecipeRow()`, `HousekeepingController::dailyCleaningHistoryEntry()` etc.
  3. **Inline `->map(fn ($x) => [...])`** building arrays (e.g. `BookingController::listPayments`, `PosController::orderHistory`).
  4. **Presenter/attribute enrichment**: `GrnApiPresenter::withCostSnapshots()` calls `setAttribute('cost_snapshot', …)` on models; `BookingController::enrichCheckoutInspectionInspectorNamesOnRooms()`; `StockMovementController` adds `outlet_name`; `DayClosingController` adds `kgst_tot_liability`.
- `BookingController::bookingJsonWithGuestIdentityMeta()` = `$booking->toArray()` + optional `guest_identity_upload_meta`.
- Casting to float in arrays is manual (`(float) $o->total_amount`, `round(..., 2)`).

## 11. Services

**Used — but selectively, and in several forms.** Inventory of kinds:

| Kind | Examples | Shape |
|---|---|---|
| Stateful domain service (instance, container-resolved) | `GrnService`, `PurchaseOrderService`, `RoomCleaningAvailabilityService`, `DailyRoomCleaningClassificationService`, `HousekeepingChecklistService`, `DayClosingService`, `MenuItemSyncService`, `RoomParStoreRequestService`, `InventoryCostLayerService` | Mutations + transactions; some have `__construct(private readonly OtherService $x)` |
| Reporting / read services | `HospitalityReportService`, `PropertyFinancialSummaryService`, `KeralaComplianceService`, `TrialBalanceService`, `ConsumptionActualsService`, `MenuPerformanceSummarizer`, `PosFoodCostService` | Instance, return arrays |
| Calculators / expanders | `RecipeCostCalculator`, `RecipeBomExpander`, `RecipeBomValidator`, `LandedCostAllocator`, `PurchaseOrderLineAmounts`, `BatchProductionPoolService`, `InventoryDeductionStoreResolver` | Mixed static/instance |
| Static policy / config readers | `InventoryAuthorization`, `BomDeductionConfig`, `BomEnforcementConfig`, `InventoryCostingConfig`, `KgstBarTotPolicy`, `TaxCreditPolicy`, `GrnFrozenCostPolicy`, `LiquorTaxValidator`, `LiquorItemClassifier`, `CessSlabResolver`, `ProcurementCostTerminology`, `BusinessDateService` | `final class`, `public static` only; config values read from `Setting` |
| Presenter | `GrnApiPresenter`, `GrnItemCostSnapshot` | static |
| Broadcast helper | `PosOutletBroadcast` | static |
| Accounting (`Services/Accounting`) | `JournalPostingService`, `LedgerBackedTransaction`, `LedgerPostingGuard`, `AccountCodes`, posters: `BookingCheckoutPoster`, `PosSettlePoster`, `PosRefundPoster`, `GrnApprovePoster`, `InventoryCogsPoster`, `InventoryAdjustmentPoster`, `InventoryConsumptionPoster`, `ProductionPoster`, `VendorPaymentPoster`, `PosCogsBusinessDateResolver`, `TrialBalanceService` | `final class`, posters take `JournalPostingService` via constructor; `post()` / `postStrict()` / `isJournalRequired()` |

Notes:
- No service interfaces/contracts; nothing bound in `AppServiceProvider`. Autowiring only.
- `final class` is common (most of `Services/Accounting`, `Support/*`, many `Services/*`); several older services are non-final (`GrnService`, `DayClosingService`, `HousekeepingChecklistService`, `RoomCleaningAvailabilityService`).
- Services signal business errors with `\RuntimeException` (GRN/PO — caught in controllers → 422), `\InvalidArgumentException` (cleaning releases — caught → 422), `ValidationException` (`Support/*`), or `JournalPostingException` (accounting — global handler → 422).
- **Reservation / front-desk logic mostly does NOT use services**: it is in `BookingController`, with shared rules in `Support/Booking*` static classes.
- **POS logic does NOT use a service for order lifecycle** — `PosController` holds it; services are used for tax policy, BOM, cost, posting.

## 12. Actions

Not used. No `app/Actions`, no single-action invokable classes, no `__invoke` controllers.

## 13. Repositories

Not used. Queries are written directly in controllers/services/support classes using Eloquent builders or `DB::table()`.

## 14. Traits

- No `app/Traits` directory.
- Controller traits in `app/Http/Controllers/Concerns/`: `AuthorizesSpatiePermissions`, `AuthorizesHousekeepingPermissions` (see §6).
- Model traits only from frameworks/packages: `HasApiTokens`, `HasFactory`, `HasRoles`, `Notifiable`.
- Test traits in `tests/Concerns/`: `CreatesHousekeepingFixtures`, `MigratesHousekeepingTestSchema`.

## 15. Enums

- **No PHP `enum` types.**
- Status/type vocabularies are expressed as:
  - MySQL `ENUM` columns (bookings, booking_segments, rooms, room_status_blocks, daily_room_cleanings, pos_orders) widened over time by raw `ALTER TABLE … MODIFY COLUMN … ENUM(...)` migrations.
  - `public const` on models/support classes (see §9).
  - Inline string literals in controllers and validation rules.

Current `rooms.status` ENUM: `available, occupied, maintenance, dirty, cleaning, inspected, pending_inspection, on_hold`.
Current `room_status_blocks.status` ENUM: `maintenance, dirty, cleaning, pending_inspection, inspected, on_hold`.
`bookings.status` / `booking_segments.status`: `pending, confirmed, checked_in, checked_out, cancelled` (code also treats `completed` as inactive in `BookingRoomAvailability::INACTIVE_SEGMENT_STATUSES`).
`daily_room_cleanings.status`: `pending_cleaning, in_progress, cleaned` (`inspection_pending` removed by migration).
`pos_orders.status`: `open, billed, paid, void` (code also sets/reads `refunded`, `cancelled` on items).

## 16. Events and listeners

- 5 events, **all `implements ShouldBroadcastNow`**, used purely for Reverb UI refresh; **no listeners** and no `EventServiceProvider`.

| Event | Channel | `broadcastAs` | Dispatch style |
|---|---|---|---|
| `HousekeepingStateUpdated(array $roomIds, ?string $reason)` | `private-reception.housekeeping` | `housekeeping.state_updated` | `HousekeepingStateUpdated::dispatchIfEnabled($ids, 'reason')` — skips when broadcaster is `null`, dedups ids, defers via `App::terminating()`, wraps in try/`report()` |
| `RoomParStockUpdated` | `private-inventory.room-par` | `room_par.stock_updated` | `dispatchIfEnabled()` (same deferred pattern) |
| `BookingChargesUpdated(bookingId, extraCharges, addedAmount, badge)` | `private-reception.booking.{id}` | `booking.charges_updated` | `event(new ...)` directly |
| `DailyRoomCleaningDeskNotify` | `private-reception.housekeeping` | `daily_cleaning.desk_notify` | `event(new ...)` |
| `PosRestaurantUpdated(restaurantId, ?orderId)` | `private-pos.restaurant.{id}` | `pos.updated` | `event(new ...)` or `PosOutletBroadcast::forRestaurant()/forLocation()` (checks broadcaster `null`) |

- `reason` strings are free text snake_case: `request_inspection`, `booking_checkout`, `booking_cancelled`, `start_cleaning`, `finish_cleaning`, `cleaning_released`, `mark_inspected`, `room_transfer`, …
- Channel auth (`routes/channels.php`) uses role checks + `can()` checks inline; broadcasting auth route uses middleware `['api','auth:sanctum','active']`.
- The docblock on `HousekeepingStateUpdated` records why: queued `BroadcastEvent` previously caused HTTP 500s mid-request.

## 17. Jobs / queues

- **No Job classes; nothing implements `ShouldQueue`.** No `dispatch()` of jobs.
- Queue is configured (`QUEUE_CONNECTION=database`, `jobs`/`failed_jobs`/`job_batches` tables exist) and `composer dev` runs `queue:listen`, but application code does not enqueue work.
- **No scheduler** (`Schedule::` not used). Time-based transitions run lazily on read: e.g. `RoomCleaningAvailabilityService::expireOverdueWindows()` is invoked from `HousekeepingController::dailyCleaningIndex()`/nav counts and inside the service's metrics method.

## 18. Notifications

None. `User` uses `Notifiable` but no Notification classes, no `Mail::`, no `->notify()`. Mail driver is `log`. `AdminDashboardController::emailQueueSnapshot()` inspects the `jobs` table for display only.

## 19. Exceptions and error handling

**Global (in `bootstrap/app.php`)**, for `api/*` only:
- `JournalPostingException` → `422 {"message": $e->getMessage()}`.
- `QueryException` → `422 {"message": <mapped friendly text>}` (substring match on SQL message: missing `pos_void_waste`, `bot_sent`, accounting tables, dietary type null; default "Database error while saving. Contact support if this continues."). **All** query exceptions on the API become 422.
- Everything else: Laravel defaults (`ValidationException` → 422 `{message, errors}`, `HttpException`/`abort()` → status + `{message}`, `ModelNotFoundException` → 404).

**Custom exceptions**: `JournalPostingException extends RuntimeException` (empty), `LiquorTaxValidationException extends RuntimeException` (with `readonly ?string $itemName`, caught in inventory/PO controllers).

**Local patterns in controllers (all present)**:
1. Early-return guard: `return response()->json(['message' => '...'], 422);` — the dominant pattern (≈300+ occurrences across controllers).
2. `abort(403|401|404, 'message')` for auth/scope.
3. `try { … } catch (\RuntimeException $e) { return response()->json(['message' => $e->getMessage()], 422); }` around service calls (GRN, PO, procurement).
4. `try { … } catch (\InvalidArgumentException $e) { …422 }` (cleaning releases).
5. `DB::beginTransaction(); try { … DB::commit(); } catch (\Exception $e) { DB::rollBack(); return response()->json(['message' => $e->getMessage()], 500); }` — 25 places return **500 with the raw exception message** (HK, room par, room stock, inventory, PO, store requests, `RoomController::syncInventoryLocations`).
6. FK-violation on delete: `catch (QueryException $e) { if ($e->errorInfo[1] == 1451 || $e->getCode() == '23000') return 409 {...}; throw $e; }` — consistent across master-data `destroy()` methods.
7. `throw new HttpResponseException(response()->json([...], 422))` inside `DB::transaction` closures to abort + roll back with a specific response (POS).
8. `ValidationException` caught and flattened to `{message}` (booking availability/capacity).

Status codes seen: 200 (default), 201 (create), 204 (`response()->json(null, 204)` on delete), 401, 403, 404, 409 (FK / conflict), 422 (business + validation), 500 (caught exceptions), 503 (`booking_payments` table missing; QZ not configured).

## 20. Middleware

- Custom: `EnsureUserIsActive` (alias `active`) — see §5.
- Aliases registered but unused on routes: `role`, `permission`, `role_or_permission` (Spatie).
- Route middleware used: only `auth:sanctum` + `active` on the whole API group, and on broadcasting auth.
- No throttling on `/login` beyond framework defaults (no `throttle:` specified). No CORS customization beyond `config/cors.php`.

## 21. Database structure and migrations

- **266 migrations, all anonymous classes** (`return new class extends Migration`). Three Laravel defaults (`0001_01_01_*`), the rest dated `2026_03_04` → `2026_09_22`.
- Naming: `YYYY_MM_DD_HHMMSS_{verb}_{object}.php` — `create_*_table(s)`, `add_*_to_*`, `alter_*`, `widen_*`, `expand_*`, `backfill_*`, `enforce_*`, `zero_*`, and permission migrations `add_*_permission`.
- Heavy defensive style: **243 `Schema::hasTable/hasColumn` guards** inside migrations; **131 raw `DB::statement`/`DB::table`** usages (ENUM changes, data backfills, permission grants).
- Driver-specific branches (`getDriverName() === 'mysql'`) in several migrations; ENUM `MODIFY COLUMN` statements are MySQL-only.
- Foreign keys: `foreignId(...)->constrained()` (185) and `->foreign()` both used; **no soft deletes**.
- **Data migrations and permission seeding happen in migrations** (not only seeders).
- Dual date/time representation on stays: `bookings.check_in`/`check_out` (DATE) **and** `check_in_at`/`check_out_at` (DATETIME); same on `booking_segments`. Code keeps them in sync on every write ("legacy date columns").
- Booking money is stored as scalars on `bookings` (`total_price`, `deposit_amount`, `refund_amount`, `extra_charges`, `checkout_discount_amount`, `cancellation_fee_amount`, `payment_status`, `payment_method`) **and** as line items (`booking_payments`, `booking_extra_charges`); `BookingPaymentLedger::syncScalars()` keeps scalars in sync with the ledger.
- Inventory on-hand is in pivot `inventory_item_locations.quantity` (accessed via `DB::table` + `lockForUpdate`) with a denormalized `inventory_items.current_stock` synced by `InventoryItem::syncStoredCurrentStockFromLocations()`; movements in `inventory_transactions` (`reference_type`/`reference_id` strings).
- Accounting: `chart_of_accounts`, `journal_entries` (unique-ish by `source_type` + `source_id` when `status=posted`), `journal_lines`, `vendor_payments`, `inventory_cost_layers`, `inventory_cost_audit_log`.

Table groups (from `Schema::create`):
- **Front office**: `room_types`, `room_type_seasons`, `rate_plans`, `rooms`, `bookings`, `booking_groups`, `booking_segments`, `booking_payments`, `booking_extra_charges`, `booking_room_transfers`, `room_status_blocks`.
- **Housekeeping**: `housekeeping_jobs`, `housekeeping_job_lines`, `daily_room_cleanings`, `daily_room_cleaning_consumptions`, `room_cleaning_releases`, `room_cleaning_release_audits`, `housekeeping_checklist_items`, `service_checklist_items`, `laundry_requests`, `laundry_request_lines`, `room_par_templates`, `room_par_template_lines`.
- **F&B / POS**: `restaurant_masters`, `restaurant_tables`, `table_categories`, `table_reservations`, `menu_categories`, `menu_sub_categories`, `menu_items`, `menu_item_variants`, `restaurant_menu_items`, `restaurant_menu_item_variants`, `combos`, `combo_items`, `restaurant_combos`, `dietary_types`, `menu_item_stocks`, `pos_orders`, `pos_order_items`, `pos_payments`, `pos_order_refunds`, `pos_payment_amendments`, `pos_void_waste`, `pos_day_closings`, `pos_day_closing_archives`, `restaurant_user`, `outlets` (unused).
- **Inventory / procurement**: `inventory_categories`, `inventory_items`, `inventory_uoms`, `inventory_taxes`, `inventory_locations`, `inventory_item_locations`, `inventory_transactions`, `vendors`, `purchase_orders`, `purchase_order_items`, `grns`, `grn_items`, `grn_attachments`, `grn_audit_logs`, `store_requests`, `store_request_items`, `stock_returns`, `procurement_requisitions`, `procurement_requisition_items`, `procurement_requisition_item_vendors`, `recipes`, `recipe_ingredients`, `production_logs`, `cess_slabs`.
- **Platform**: `users`, `departments`, `department_user`, Spatie tables, `personal_access_tokens`, `settings`, `login_attempts`, `payment_methods`, `sessions`, `cache`, `jobs`.

## 22. Factories and seeders

- Factories: only `UserFactory` (default skeleton). Tests do not rely on factories for domain models; they `Model::create()` directly or `forceFill()` unsaved models.
- Seeders (44): operational/reference data for a live property, not generic fakes — tax/UOM/cess, RBAC (`RolePermissionSeeder`, `DefaultHotelRolesSeeder`, `RbacTestUsersSeeder`, role-specific user seeders), departments/locations, F&B catalogs (large arrays in `database/seeders/data/*.php`), bar/restaurant opening stock, room types/rooms, room par, HK checklist, sample bookings (`BookingSeeder`, `RoomChartTestReservationsSeeder`).
- `DatabaseSeeder` calls them in a fixed order and finally creates `admin@hotel.com` with password `'1'` and role `Admin`.
- `RolePermissionSeeder::permissionNames()` and `DefaultHotelRolesSeeder::rolePermissionMap()` are **also used at runtime** (by `RoleController`), so seeders double as the permission registry.
- Stray files in seeders dir: `Untitled` (empty), two `.code-workspace` files.

## 23. Tests and testing conventions

- PHPUnit 11; suites `Unit` (`tests/Unit`, 24 files incl. `Unit/Services`, `Unit/Support`) and `Feature` (3 files).
- `phpunit.xml`: SQLite `:memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`, `SESSION_DRIVER=array`.
- **`RefreshDatabase` is not used** (only commented out in `ExampleTest`). Because the real migrations are MySQL-specific, tests **hand-build the schema they need**:
  - `tests/Concerns/MigratesHousekeepingTestSchema::migrateHousekeepingTestSchema()` creates `users`, Spatie tables, `room_types`, `rooms`, bookings, segments, blocks, cleaning tables, etc. with `if (! Schema::hasTable(...)) Schema::create(...)`.
  - Individual tests create/extend tables in `setUp()` (e.g. `BookingCancellationPolicyTest` drops/creates `settings`; `BookingRoomAvailabilityTest::ensureRoomTypeCapacityColumns()`).
  - Accounting tests `markTestSkipped('Accounting migrations not run.')` if `journal_entries` is missing, and clean up rows by `source_type LIKE 'test_%'` in `tearDown()`.
- Fixtures: `tests/Concerns/CreatesHousekeepingFixtures` (`createUserWithPermission()`, `createRoom()`, `createActiveRelease()`, `releaseWindowPayload()`).
- Feature tests: `Sanctum::actingAs($user)` + `$this->postJson('/api/...')` + `assertCreated()->assertJsonPath(...)` + `assertDatabaseHas(...)` (`RoomCleaningReleasePriorityTest`, `RoomCleaningReleaseClassificationTest`).
- Unit tests target **Support/Service classes** (availability, cancellation policy, payment ledger, cleaning priority/classification, accounting posters, journal service, cost/BOM calculators, tax policies, `InventoryAuthorization`). Controllers are largely untested (no Booking/POS controller tests).
- Mockery used in 2 tests (`VendorPaymentPartialFlowTest`, `InventoryAuthorizationTest`).
- Method naming: `test_snake_case_description(): void`. `Tests\TestCase` is the empty skeleton base.
- `scripts/e2e_hotel_flow_test.php` is a standalone script, not part of the PHPUnit suites.

## 24. API response format

There is **no envelope convention**. Observed shapes:

| Situation | Shape |
|---|---|
| Resource read/list | Bare model / bare array of models (`[{...}, {...}]`) — most CRUD |
| Create | Bare model, HTTP 201 |
| Delete | `null` body, HTTP 204 |
| Action with side data | Object with named keys, often `message`: e.g. `{message, booking, settlement}`, `{message, payment, booking, totals}`, `{message, block, job}`, `{items, reasons}`, `{rooms, start, end}` |
| Error | `{"message": "..."}` (+ `errors` only for framework `ValidationException` and one manual case) |
| Reports | `{data: [...], summary: {...}, payments: [...], meta: {...}}` in some POS reports; `{data, current_page, last_page, per_page, total, ...}` in POS order history; bare paginator JSON elsewhere |
| Auth | `{token, user, permissions, roles}` |
| Files | `Pdf::loadView(...)->download()/->stream()`, `response()->streamDownload()` (CSV), `StreamedResponse` (CSV/XLSX), plain-text for QZ endpoints |

Other conventions:
- Dates: models serialize with Laravel defaults (ISO-8601 for datetimes); some arrays call `->toIso8601String()`, `->toDateString()` or `->toDateTimeString()` explicitly; `RoomStatusBlock` casts `date:Y-m-d`.
- Money: floats in JSON (manual `(float)` + `round(…, 2)`), strings where `decimal:2` casts apply (Laravel serializes decimal casts as strings).
- Keys are snake_case (DB columns); computed keys also snake_case (`signed_amount`, `received_by_name`, `is_voided`).

## 25. Pagination conventions

Mostly **no pagination** — lists return full collections (`->get()`), sometimes capped with `->limit(N)` (`limit(200)` on HK index, `limit(100)` audits, request-driven `$limit` on history endpoints).

Where pagination exists it varies:
- Bare `LengthAwarePaginator` JSON: `StockMovementController::index` (`per_page` clamped 10–100, default 50), `DayClosingController::index` (`per_page`, default 20), `InventoryReportController::stockLedger` (fixed 50).
- Custom flattened: `PosController::orderHistory` → `{data, …, current_page, last_page, per_page, total}` (`per_page` validated `1–100`, default 20).
- Custom with `meta`: `PosController::salesReport` → `{summary, payments, data, meta: {current_page, last_page, total}}` (fixed 50).
- Explicit page param: `->paginate(50, ['*'], 'page', $page)` in liquor/food sales reports.

## 26. API versioning

None. No `/v1` prefix, no version headers, single `routes/api.php`.

## 27. Logging and auditing

**Logging**: default `stack` → `single` channel. `Log::`/`report()` used sparingly (≈25 calls): inventory cost layers, GRN service, POS, recipe, guest identity images, HK controller, and in event dispatch fallbacks.

**Auditing — several independent mechanisms**:

| Mechanism | Where | Format |
|---|---|---|
| **Append-to-`bookings.notes`** bracketed audit lines | `BookingController` (create, update diff, early CI, late CO, extend, split stay, early checkout, cancellation) | `[Tag: details by {User Name} on Y-m-d H:i:s]`, joined with `\n`; `appendAuditNotesForBookingUpdate()` diffs guest, rate plan, deposit, refund, totals, payment status, room, dates, discount |
| Dedicated audit tables | `room_cleaning_release_audits` (`RoomCleaningReleaseAudit::ACTION_*`), `grn_audit_logs`, `inventory_cost_audit_log` | Row per action with user + meta JSON |
| Ledger-style immutable rows | `booking_payments` (void via `voided_at/voided_by/void_reason`, never deleted), `pos_payment_amendments`, `pos_order_refunds`, `pos_void_waste`, `pos_day_closing_archives`, `booking_room_transfers`, `journal_entries` (reversal via `STATUS_REVERSED`) | — |
| Actor columns | `created_by`, `received_by`, `performed_by`, `approved_by`, `started_by`, `completed_by`, `voided_by`, `discount_approved_by`… | Set from `auth()->id()` / `Auth::id()` |
| Login audit | `login_attempts` (every attempt) | — |
| Inventory movements | `inventory_transactions` with `user_id`, `reference_type`, `reference_id` (UUID or entity id), `reason`, `notes` | — |

No activity-log package. `AdminDashboardController::auditEventCount24h()` approximates "audit events" by counting logins + inventory transactions + updated POS orders + updated bookings.

## 28. Caching

- Only application cache use: `Setting::get()` → `Cache::remember("setting.{$key}", 300, …)`; `Setting::set()` → `Cache::forget(...)`.
- Spatie permission cache (24h) — explicitly flushed in `AuthController::me`, `UserController`, `RoleController`, permission migrations.
- Static in-process memo: `JournalPostingService::$accountIdsByCode` (+ `flushAccountCache()` for tests).
- Cache store: `database`.

## 29. External integrations

| Integration | Implementation |
|---|---|
| Laravel Reverb (WebSockets) | Broadcast events §16; frontend subscribes via Echo |
| QZ Tray (silent receipt printing) | `QzSignController` signs request strings with `openssl_sign(SHA512)` using `config('services.qz.private_key_path')`; serves certificate from `services.qz.certificate_path`. Behind auth. |
| DomPDF | Reservation invoice/voucher (`resources/views/bookings/*`), day-closing and POS report PDFs (`resources/views/reports/*`) |
| PhpSpreadsheet | XLSX exports (excise bar report, POS reports) |
| File storage | `GuestIdentityImageService` stores guest ID images/PDFs on disk `config('guest_identity.disk')` (default `public`, dir `identities`), compresses large images (GD, max dimension/JPEG quality from config); restaurant logos and receipt logo via `Storage` |
| Statutory (Kerala / India) | In-house logic only: `KeralaComplianceService` (GSTR-1 / KVAT summaries + CSV export), `KgstBarTotPolicy`, `BarTurnoverTaxService`, `LiquorTaxValidator`, `CessSlabResolver`, `BevcoPoTaxCorrection` |
| HTTP APIs | **None** — no `Http::` client, Guzzle or curl; no payment gateway, channel manager, OTA, SMS or email provider integration |

## 30. Multi-hotel / property / tenant isolation

- **Not implemented — single-property system.** No property/hotel/tenant columns, scopes, middleware or global scopes.
- Property identity is global settings (`Setting::getCompanyProfile()`, `invoice_*`, `receipt_*` keys).
- The only partitioning dimension is **F&B outlet** (`restaurant_masters`), enforced by `userCanAccessRestaurant()`-style checks in POS, day closing, broadcast channels and financial summary; outlets have their own `business_day_cutoff_time` and day closings.
- **Departments** (`departments`, `department_user`) are used for inventory locations, store requests and HK room-stock (`HousekeepingRoomStockController::requireHkDepartmentForUser()`), and as a fallback for outlet access.
- Rooms, bookings, housekeeping have **no** outlet/department scoping — any user with the permission sees all rooms.
- An `outlets` table exists but is unused (superseded by `restaurant_masters`).

## 31. Important Hotel PMS domain models and workflows

| Domain | Models | Main controller(s) | Shared logic |
|---|---|---|---|
| Room inventory | `RoomType`, `RoomTypeSeason`, `RatePlan`, `Room` | `RoomTypeController`, `RoomController` | `SeasonalRoomPricing` |
| Reservations | `Booking`, `BookingSegment`, `BookingGroup`, `BookingRoomTransfer` | `BookingController` | `BookingRoomAvailability`, `BookingRoomTransferService`, `BookingCancellationPolicy` |
| Room state / blocks | `RoomStatusBlock`, `Room.status` | `RoomStatusBlockController`, `BookingController`, `HousekeepingController` | — |
| Folio & payments | `BookingPayment`, `BookingExtraCharge`, `Booking.extra_charges`, `PosOrder (room_charge)` | `BookingController`, `LaundryRequestController`, `HousekeepingController`, `PosController` | `BookingPaymentLedger`, `BookingInvoiceRoomStay`, `BookingInspectionChargeLines`, `ReservationInvoiceViewData`, `BookingCheckoutPoster` |
| Housekeeping | `HousekeepingJob(+Line)`, `DailyRoomCleaning(+Consumption)`, `RoomCleaningRelease(+Audit)`, `HousekeepingChecklistItem`, `ServiceChecklistItem`, `LaundryRequest(+Line)`, `RoomParTemplate(+Line)` | `HousekeepingController`, `RoomCleaningReleaseController`, `HousekeepingChecklistController`, `LaundryRequestController`, `HousekeepingRoomStockController`, `RoomParController` | `RoomCleaningAvailabilityService`, `DailyRoomCleaningClassificationService`, `HousekeepingChecklistService`, `CleaningReleasePriority`, `CleaningServiceClassification`, `CheckoutInspection*`, `RoomParInventoryContext` |
| F&B / POS | `RestaurantMaster`, `PosOrder`, `PosOrderItem`, `PosPayment`, … | `PosController`, `DayClosingController` | tax/BOM/cost services, posters |
| Inventory / procurement | `InventoryItem`, `InventoryLocation`, `InventoryTransaction`, `PurchaseOrder`, `GRN`, `StoreRequest`, `Recipe` | Inventory controllers, `GrnController`, `PurchaseOrderController` | `GrnService`, `PurchaseOrderService`, cost layer services |
| Accounting | `ChartOfAccount`, `JournalEntry`, `JournalLine`, `VendorPayment` | `AccountingController` | `Services/Accounting/*` |
| Settings | `Setting` (key/value) | `SettingController` | config reader classes |

Time model: `APP_TIMEZONE=Asia/Kolkata`. `BookingController::parseHotelDateTime()` converts incoming datetimes to app timezone before persisting ("hotel wall time"). Day stays normalize `check_in_at`/`check_out_at` to midnight. F&B uses `BusinessDateService::resolve()` with an outlet cut-off (default `04:00`).

## 32. Reservation workflow

All in `BookingController` unless noted.

**Create — `POST /bookings` (`store`)**
1. Decide single vs group from raw input (`room_ids` count > 1 or `group_name`) → `allowReservationCreateSingle()` / `allowReservationCreateGroup()`.
2. Normalize `guest_gstin`, `bill_to_name`; inline validation (GSTIN regex, statuses, `booking_unit in:day,hour_package`, etc.).
3. Parse datetimes into hotel TZ; reject past `check_in`; `status=checked_in` only allowed if arrival is today; day bookings require `check_out` and are normalized to midnight.
4. Breakfast counts ≤ guest counts (manual 422 with `errors`).
5. Pre-check each room: `BookingRoomAvailability::assertSellable()` (converted to 422 `{message}`).
6. Group: create `BookingGroup` first (outside any transaction).
7. Guest ID images: `GuestIdentityImageService::storeDataUrl()/storeUploadedFile()/storeExistingPath()`.
8. Per room: apply per-room occupancy overrides (`room_occupancy[roomId]`), `assertCapacity()`, compute total:
   - `hour_package`: `computeHourlyPackageTotal()` (package price, seasonal adjustment, extra beds once, overtime by step/grace, tax added).
   - Day stay, multi-room: server recomputes per-room total via `SeasonalRoomPricing::sumDayRoomRentWithSeasons()` and `room_rates_include_gst` setting.
   - Day stay, single room: **client-supplied `total_price` is stored**.
9. Prepend `[Reservation created: …]` audit line to `notes`; derive `early_checkin_time` from `estimated_arrival_time` vs `standard_check_in_time`.
10. `BookingRoomAvailability::withRoomLocks([roomId], fn)` → `DB::transaction` + `lockForUpdate` on room row → re-`assertSellable` → `Booking::create` + one `BookingSegment::create` → if `checked_in`, `Room.status=occupied`.
11. If ledger enabled and `deposit_amount > 0`: zero scalar, then `BookingPaymentLedger::recordPayment(source: 'booking_create')`.
12. Response: group → array of bookings (201); single → booking array + optional `guest_identity_upload_meta` (201).

**Update — `PATCH /bookings/{id}` (`update`)** handles edits, check-in and check-out:
- Room change for confirmed/checked-in bookings is rejected unless `force_room_change` (directs to room transfer endpoint).
- `status=cancelled` rejected (must use `/cancel`).
- Checkout discount only while `checked_in`, ≤ gross, reason ≥ 3 chars.
- Deposit/refund patches become ledger postings (`legacy_patch` / `checkout` source), then removed from the scalar update.
- `appendAuditNotesForBookingUpdate()` writes diff lines to `notes`.
- Date/occupancy changes re-run `assertSellable(excludeBookingId)` and `assertCapacity()`.
- `DB::transaction { $booking->update(); if new checkout: BookingCheckoutPoster::post() }`.
- After the transaction (not inside it): segment sync (0 → create baseline, 1 → mirror booking, N → status fan-out), room status sync, housekeeping blocks, broadcast.

**Other reservation endpoints**
- `POST /booking-groups` — group master only.
- `GET /bookings/guest-search?phone=` — returns latest booking's guest profile (no separate guest table).
- `POST /bookings/{id}/early-checkin` / `late-checkout` — fee from room type (`per_hour` / `per_minute` / flat) with buffer minutes; adds to `extra_charges`; audit note.
- `POST /bookings/{id}/extend`, `extend-hours` (+ `preview-extend-hours`), `early-checkout` (+ `preview-early-checkout`).
- `POST /bookings/{id}/split-stay` — ends last segment at current checkout, creates a new segment in another room, adds segment price to `total_price` (no availability assertion or transaction in this method).
- `POST /bookings/{id}/room-transfer` (+ preview, list) — `BookingRoomTransferService::execute()` in `DB::transaction`, records `BookingRoomTransfer`, rate mode `keep_existing|apply_new_category`, creates dirty block on source room, broadcasts. Returns `['ok' => bool, ...]` arrays instead of throwing.
- `POST /bookings/{id}/cancel` (+ `preview-cancellation`) — only `pending|confirmed`; `BookingCancellationPolicy::preview()` (settings `cancellation_free_hours_before`, `cancellation_fee_type none|percent|first_night|fixed`, `cancellation_fee_value`) → requires `confirm_balance_waived` if fee > deposit, refund method if refund due → `DB::transaction` { ledger payment/refund with `source='cancellation'`, booking → `cancelled` + fee fields, segments cancelled, rooms `available`, overlapping `on_hold` blocks deactivated } → broadcast.
- `DELETE /bookings/{id}` — sets rooms `available` then hard-deletes the booking (no status check).
- `GET /bookings/{id}/voucher` / `billing` — DomPDF via `ReservationInvoiceViewData`.

## 33. Room availability logic

Central rules: `app/Support/BookingRoomAvailability.php` (static, `final`):
- **Occupancy** = overlap on `booking_segments` by datetime: `check_in_at < :end AND check_out_at > :start`, excluding segment statuses `cancelled, checked_out, completed`, optionally excluding one booking.
- **Hard blocks** (`maintenance`, `on_hold`) in active `room_status_blocks` with date overlap (`start_date < endExclusive AND end_date > startDate`, `end_date` exclusive) → never sellable.
- **Check-in-only blocks** (`dirty`, `cleaning`) → sellable for future stays, but block `status=checked_in`.
- `dateEndExclusiveFromDateTime()`: midnight checkout ⇒ same date exclusive; otherwise next day.
- `assertSellable()` throws `ValidationException(['room_id' => ...])`.
- `withRoomLocks()` / `lockAndAssertSellable()`: sorted room ids, `Room::lockForUpdate()` inside `DB::transaction` for concurrency.
- `capacityErrors()` / `assertCapacity()`: adults+children ≤ `capacity`; required extra beds from `base_occupancy`, `child_sharing_limit`, `extra_bed_capacity`.
- `isListedAsAvailable()` helper.

Where it is applied:
- `store()` (pre-check + locked re-check), `update()` (on date change, without lock), `getAvailableRooms()` (**re-implements** the same rules as an Eloquent `whereDoesntHave('segments')` / `whereDoesntHave('statusBlocks')` query using the class constants), room transfer service.
- `update()` check-in path separately queries active `dirty`/`cleaning` blocks for today.
- `chart()` returns rooms with `statusBlocks` (active + inactive `inspected` with snapshot), `cleaningReleases` and `segments` overlapping a date range (default 14 days) — availability rendering is done client-side.
- `summary()` computes occupied/reserved/maintenance/dirty/cleaning/available counts for a date.
- `Room.status` is a denormalized "current" status updated imperatively by booking/HK actions; availability checks use segments + blocks, not `Room.status`.

## 34. Check-in / check-out workflow

**Check-in** (via `PATCH /bookings/{id}` with `status=checked_in`, or create with `status=checked_in`):
1. Arrival calendar day must equal today (`bookingArrivalCalendarDay()`; hourly uses `check_in_at` in app TZ).
2. Reject if an active `dirty`/`cleaning` block covers today for `booking.room_id`.
3. Update booking; all segments → `checked_in` (multi-segment "continuous stay" semantics); all rooms across segments → `occupied`.
4. No daily-cleaning seeding at check-in (done at cleaning release).
- `POST /bookings/{id}/early-checkin` only records time/fee; it does not change status.

**Pre-checkout inspection** (`POST /bookings/{id}/request-inspection`): booking must be `checked_in` and today must be each active segment's checkout day; deactivates prior pending/HK blocks, creates `pending_inspection` `RoomStatusBlock` per segment with `inspection_snapshot {booking_id, room_id, segment_id}`, sets `Room.status=pending_inspection`, broadcasts. HK then runs checkout inspection (§36) which can post charges to the folio.

**Check-out** (via `PATCH /bookings/{id}` with `status=checked_out`):
1. Refund amount requires `refund_method`.
2. Paid check: `payment_status=paid`, else compare `deposit_amount` vs `max(effectiveBookingGrand, total_price)`; group bookings check pooled group balance (default `checkout_scope=group`) or per-room (`checkout_scope=room`). Fail → 422 "Checkout not allowed until payment is fully paid".
3. If checkout date is in the future → truncate `check_out` to today, `check_out_at` to tomorrow 00:00, add `[Early CO: …]` note.
4. Refund delta → `BookingPaymentLedger::recordRefund(source: 'checkout', allow_closed: true)`.
5. `DB::transaction { booking->update(); BookingCheckoutPoster::post() }` — journal: Dr tender accounts (split by ledger `netByMethod`) / Dr Folio AR shortfall; Cr Folio AR (POS room charges already recognized), Cr Room Revenue, Cr Output CGST/SGST (tax extracted from inclusive amount). Idempotent per `booking_checkout` + booking id.
6. Segments → `checked_out`; rooms → `dirty`; per segment: deactivate `inspected`/`pending_inspection` blocks and overlapping `dirty`/`cleaning` blocks, then create a one-day `dirty` block on the checkout date (`note: 'Auto: checkout'`) if none remains; broadcast `booking_checkout`.

`POST /bookings/{id}/early-checkout` (+ preview) is a separate path for recomputing charges when leaving early.

## 35. Billing / payment workflow

**Room folio composition** (`BookingInvoiceRoomStay::summarizeForInvoice()`): room stay (recomputed or stored, tax per room type, `room_rates_include_gst` setting) + folio extras → `gross_before_checkout_discount`; `BookingController::effectiveBookingGrand()` subtracts `checkout_discount_amount`. Hourly packages use `total_price + extra_charges`.

**Charges to the folio** come from several writers:
- `bookings.extra_charges` scalar incremented by: early check-in / late checkout fees, POS `room_charge` settlement (`Booking::increment('extra_charges')` inside POS transaction), laundry post-to-room, checkout inspection.
- `booking_extra_charges` rows with `source`: `inspection` (minibar consumption, asset penalties via `BookingInspectionChargeLines`), `laundry`.
- POS orders linked by `booking_id` (`room_service`), shown in `folioPostings()` / `folioOrderDetail()`.
- Each writer broadcasts `BookingChargesUpdated` to `reception.booking.{id}`.

**Payments** (`BookingPaymentLedger`, static):
- `enabled()` ⇔ `Schema::hasTable('booking_payments')` (every call path branches on this; legacy scalar-only mode still coded).
- `recordPayment` / `recordRefund` / `recordAdjustment` / `recordSplitPayments` (tenders in one transaction) / `voidPayment`.
- Methods whitelist: `cash, card, upi, bank_transfer`. Sources: `booking_create`, `deposit`, `checkout`, `manual`, `legacy_patch`, `cancellation`.
- Guards: no payments on cancelled bookings; no *payments* after checkout (refunds allowed with `allow_closed`).
- After each write `syncScalars()` recomputes `bookings.deposit_amount`, `refund_amount`, `payment_method`, `payment_status` from active rows (+ optional `bill_total`).
- Voids are soft (`voided_at`, `voided_by`, `void_reason`) and blocked after checkout/cancellation.
- Endpoints: `GET/POST /bookings/{id}/payments`, `POST /bookings/{id}/payments/{payment}/void`.

**Accounting linkage**: room revenue is recognized only at checkout (`BookingCheckoutPoster`); booking deposits themselves are **not** journaled at receipt time. POS settle posts sales/AR (`PosSettlePoster`) via `LedgerBackedTransaction` (fail-closed: journal failure rolls back the settle).

**Invoice/voucher**: `ReservationInvoiceViewData` builds view data from settings (`invoice_*`, bank details, SAC codes, `invoice_prefix`), `MoneyToWords`; rendered by DomPDF from `resources/views/bookings/*.blade.php`.

**POS payments (for completeness)**: `PosController::settle()` — permissions `pos-settle` (+ `pos-discount` for discount/complimentary), outlet access, business-date-open check (`PosDayClosing` + `DayClosingService::assertSequentialOrAbort`), methods `cash|card|upi|room_charge` (room charge only for `room_service` orders with a checked-in booking, re-checked under `lockForUpdate`), payments replaced, order → `paid`, inventory deduction, table → `cleaning`, journal via `PosSettlePoster::postStrict`. Refunds/voids/payment amendments have dedicated endpoints and posters.

## 36. Housekeeping workflow

Three parallel sub-workflows share `room_status_blocks`, `rooms.status` and `room_cleaning_releases`.

**A. Turnover (departure) cleaning — "Dirty Rooms" board** (`HousekeepingController`, permission sections `HK_DIRTY`/`HK_CLEANING`/`HK_CLEAN`):
1. Checkout creates a `dirty` block (§34). Room transfer also creates a dirty block on the source room.
2. `POST housekeeping/blocks/{block}/assign-staff` — requires `housekeeping-assignable` (dirty/cleaning) or `housekeeping-checkout-inspection-assign` (pending_inspection); assignee must be an active user in an active department flagged `departments.is_housekeeping` (set in Department Master). Pending checkout inspections must be assigned before clear/validate/apply, and only the assignee or a holder of `housekeeping-checkout-inspection-assign` may perform them.
3. `POST …/start-cleaning` — block must be active + `dirty` + assigned → block & room `cleaning`; linked active `RoomCleaningRelease` → `markCleaningStarted`.
4. `POST …/job` — upsert `HousekeepingJob` + replace `HousekeepingJobLine`s (`checklist`, `amenity`, `minibar`, `asset`) in `DB::beginTransaction`.
5. `POST …/finish` — in one transaction: validate consumed qty ≤ room-location on-hand (`inventory_item_locations`, `lockForUpdate`), decrement, write `inventory_transactions` (`reference_type='housekeeping'`), sync item stock; minibar lines → POS `room_charge` order on the active booking (`postMinibarRoomCharge`); asset `needs_repair|missing` → create `maintenance` block (5 years) + room `maintenance`; otherwise block → `inspected` + inactive and room → `available`. After commit: release → completed/ready; broadcast HK + room-par.
6. `POST …/mark-inspected` — supervisor step for blocks left in `inspected`: deactivate, room `available`, job completed, release ready.
7. `POST …/mark-cleaned` — shortcut path from `cleaning`: deactivates the block and sets room `available` with no job/stock/minibar processing and no release update.

**B. Checkout inspection** (`HK_CHECKOUT`): reception creates `pending_inspection` blocks (§34). HK uses `GET housekeeping/rooms/{room}/checkout-inspection-context`, `POST …/checkout-inspection/validate` (preview charges from penalties map `checkout_inspection_penalties` setting + room par assets), `…/apply` (posts `booking_extra_charges` with `source='inspection'` for minibar and asset penalties, stores `inspection_snapshot`, broadcasts `BookingChargesUpdated`), `…/clear` (no charges).

**C. Cleaning availability windows + daily (stay-over) cleaning** (`RoomCleaningReleaseController` + `RoomCleaningAvailabilityService`, permission `housekeeping-cleaning-availability`; `HK_DAILY` for daily board):
1. Front office `POST housekeeping/cleaning-releases` {room, date, window_start/end, priority, service type/subtype, assignee} → service transaction: cancel previous active releases for the room, resolve dirty block, classify service (`DailyRoomCleaningClassificationService`), if the room is occupied that day `firstOrCreate` a `DailyRoomCleaning` row, create `RoomCleaningRelease` (`available`), write audit rows (`released`, optionally `service_reclassified`), broadcast.
2. Extend / reschedule / cancel endpoints mutate the window with audits; `expireOverdueWindows()` marks `available` releases past `window_end` as `expired` lazily on board reads.
3. Daily board `GET housekeeping/daily-cleaning` lists occupied rooms with a release; `POST housekeeping/daily-cleaning/status` (`pending_cleaning → in_progress → cleaned`) requires an active release and, for `in_progress`, an open window (`assertCanStartCleaning`); rooms without an occupied segment are redirected to the Dirty Rooms workflow. `notify_front_desk` → `DailyRoomCleaningDeskNotify` event. `POST …/consumption` records amenity consumption from room location.
4. Supervisor `POST cleaning-releases/{id}/mark-inspected` (`housekeeping-supervisor-inspection`) when release is `inspection_pending`.
5. Release statuses: `available → in_progress → completed → inspection_pending → ready`, plus `expired`, `cancelled`.

**Supporting HK modules**: checklist master (`HousekeepingChecklistController` + service, reorder), laundry (`LaundryRequestController`: `pending_pickup → picked_up → processing → ready → delivered`, lines, `post-to-room` → `booking_extra_charges` `source='laundry'`), room stock / par (`HousekeepingRoomStockController`, `RoomParController`: templates, fill/allocate/assign, transfers between HK store and room locations, restock requests via `StoreRequest`), history (`roomCleaningHistory`, `dailyCleaningHistory`, history boards), dashboards (`navCounts`, `dashboardSummary`, `dirtyRoomsBoard`).

---

## 37. The request pipeline as it actually runs

```
HTTP /api/...
  → Route (routes/api.php; implicit model binding)
  → Middleware: auth:sanctum → active (EnsureUserIsActive)
  → Controller method
       1. Authorization helper (trait authorizePermissions / private checkPermission / InventoryAuthorization::assert*)
          [+ outlet scope check in POS/day-closing]
       2. Optional $request->merge() normalization
       3. $request->validate([...])            ← no FormRequest
       4. Business guards → early `return response()->json(['message'=>…], 422)`
       5. Work, one of:
          a. inline Eloquent / DB::table in controller (Booking, HK, POS, most CRUD)
          b. static Support class (BookingRoomAvailability, BookingPaymentLedger, BookingCancellationPolicy…)
          c. container service (GrnService, RoomCleaningAvailabilityService, DayClosingService…)
          d. LedgerBackedTransaction::run(mutate, postMutate, postJournal, journalRequired) for stock/POS + journal
       6. Side effects: Room.status updates, RoomStatusBlock create/deactivate, broadcast events (deferred)
  → Model(s)                                   ← $fillable, casts, relations; little behavior
  → Response: model/array via response()->json(...)  ← no JsonResource
  → Errors: abort()/422 JSON locally; JournalPostingException & QueryException mapped globally
```

Per-module variants of step 5:

| Module | Pattern |
|---|---|
| Reservations (`BookingController`) | Inline logic + static `Support/Booking*` helpers; `DB::transaction` only around critical writes; post-transaction side effects |
| Housekeeping turnover (`HousekeepingController`) | Inline logic, `DB::beginTransaction/commit/rollBack`, 500 on exception; delegates release state to `RoomCleaningAvailabilityService` |
| Cleaning releases | Thin controller → instance service (transaction + audits inside service) → `InvalidArgumentException` → 422 |
| GRN / PO | Thin controller → `GrnService` / `PurchaseOrderService` → `RuntimeException` → 422; presenter for output |
| POS | Very fat controller; `LedgerBackedTransaction` + posters; `HttpResponseException` inside closures |
| Master data CRUD | Minimal controller: check → validate → `Model::create/update` → return model; FK 409 on delete |
| Reports | Controller builds queries or calls a report service; returns arrays/paginators or streams files |

## 38. Common conventions

**Naming**
- Controllers `{Name}Controller`; services `{Name}Service` / `{Name}Poster` / `{Name}Policy` / `{Name}Config` / `{Name}Resolver` / `{Name}Calculator` / `{Name}Validator` / `{Name}Presenter`; Support classes named for the concept (`BookingPaymentLedger`, `SeasonalRoomPricing`).
- Models singular PascalCase; one uppercase acronym model `GRN` (others `GrnItem`, `GrnAttachment`).
- Tables snake_case plural; pivots `department_user`, `restaurant_user`; JSON/API keys snake_case.
- Permissions kebab-case; statuses snake_case strings; broadcast event names `domain.action` (`housekeeping.state_updated`).
- Route URIs kebab-case plural (`room-status-blocks`, `procurement-requisitions`); action segments kebab-case verbs (`start-cleaning`, `preview-room-transfer`).

**Method naming**
- Resource methods: `index, store, show, update, destroy`.
- Actions: verb-based (`cancelReservation`, `storePayment`, `voidPayment`, `startCleaning`, `markInspected`, `settle`, `approve`, `issue`, `recallIssue`).
- Previews: `preview{Action}`.
- Authorization helpers: `allow{Area}{Action}()`, `checkPermission()`, `authorize{Scope}()`, `assert{Condition}()`.
- Invariant checks: `assert*()` (throws/aborts), `can*()` (bool), `ensure*()` (create-if-missing), `resolve*()` (lookup/derive), `build*()` / `compute*()` (derive arrays/amounts), `format*()` (output shaping), `sync*()` (reconcile denormalized data).

**Query patterns**
- Eloquent builders with eager loading strings including nested and column-limited relations (`'assignedUser:id,name'`, `'room.roomType.tax'`).
- Closures in `with()` to filter loaded relations by date overlap.
- Date overlap idiom everywhere: `start < :end AND end > :start` (dates exclusive end for blocks; datetimes for segments).
- `->when($cond, fn)` for optional filters.
- Explicit 4-argument `where('col', '=', $v, 'and')` / `whereIn(..., 'and', false)` style in 150+ places (IDE-helper-friendly), alongside ordinary 2/3-arg `where`.
- `DB::table()` used directly (≈130× in controllers) for pivots (`inventory_item_locations`) and aggregates; `selectRaw/whereRaw/DB::raw` ≈200×.
- `Schema::hasTable/hasColumn` checks at runtime in app code (≈50 places) to tolerate partially migrated databases.
- `firstOrCreate` for idempotent child rows (daily cleaning, HK job, permissions).
- `lockForUpdate()` on rows being mutated (orders, bookings in POS, rooms for availability, inventory pivot rows, journal entries, GRN).

**Authorization patterns**: see §6 — first statement of each action method; any-of permission lists; Admin bypass differs by helper.

**Transaction patterns**
- `DB::transaction(fn)` (56 occurrences) and manual `DB::beginTransaction/commit/rollBack` (24) both common; manual style dominates older HK/inventory/room-par code.
- `LedgerBackedTransaction::run()` for "state mutation + mandatory journal" (POS settle, inventory adjustments, GRN approve…); converts non-journal exceptions to `JournalPostingException`.
- `BookingRoomAvailability::withRoomLocks()` for booking creation.
- `JournalPostingService::post()` opens its own nested transaction and is idempotent per source.
- Broadcasts deferred to `App::terminating()` so they never run inside the transaction.
- Some multi-step writes are intentionally *not* fully wrapped (e.g. `BookingController::update` segment/room/HK sync after the transaction; `store()` group creation before per-room transactions; `splitStay`).

**Error handling patterns**: see §19.

**Testing patterns**: see §23.

---

## Patterns that MUST be preserved

These are behaviors other code, the frontend, or accounting integrity depend on.

1. **Single API route group** `['auth:sanctum', 'active']` and the `/login` response shape `{token, user(departments, restaurants), permissions[], roles[]}`; `/me` returns the same minus token. The frontend gates UI by permission names.
2. **`EnsureUserIsActive`** revoking the current token and returning 403 for deactivated users; deactivation deleting all tokens.
3. **Spatie guard `web`** for all roles/permissions; `RolePermissionSeeder::permissionNames()` as the runtime-canonical permission list (read by `RoleController`); new permissions shipped via migrations that `firstOrCreate` + grant + `forgetCachedPermissions()`.
4. **Exact permission semantics per module**, including the Admin-bypass differences (trait: no bypass; `checkPermission`: Admin [+ Super Admin in some]; `InventoryAuthorization`: Admin + Super Admin), legacy coarse permissions accepted alongside granular ones, and any-of lists in `allow*()` helpers.
5. **Error body `{"message": "..."}`** with 422 for business-rule failures, 409 for FK-blocked deletes, 204 on delete, 201 on create — the frontend reads `message`.
6. **Global exception mapping** of `JournalPostingException` and `QueryException` to 422 JSON for `api/*`.
7. **Availability rules** in `BookingRoomAvailability`: segment datetime overlap; hard blocks `maintenance`/`on_hold`; `dirty`/`cleaning` block only check-in; inactive segment statuses `cancelled, checked_out, completed`; exclusive `end_date` on blocks; row locks on create. `getAvailableRooms()` mirrors these constants.
8. **Booking segments as the occupancy source of truth**, kept in sync with the booking row (dates, status, occupancy, rate plan, price) and the dual date/datetime columns (`check_in` + `check_in_at`, etc.) kept aligned; hotel-timezone normalization via `parseHotelDateTime()`; day stays at midnight.
9. **Check-in/out as `PATCH /bookings/{id}` status transitions** with their guards (arrival-day-only check-in, dirty-room block, paid-before-checkout incl. group/room scope, early-checkout truncation) and side effects (segments, `rooms.status`, auto `dirty` block on checkout day, broadcasts). Cancellation only via `POST /bookings/{id}/cancel`; room change only via room transfer.
10. **Checkout journal atomicity**: booking status update + `BookingCheckoutPoster::post()` in one `DB::transaction`; journal idempotency per `(source_type, source_id)`.
11. **Payment ledger invariants** (`BookingPaymentLedger`): append-only rows, soft voids, method whitelist, source tags, `syncScalars()` keeping `bookings.deposit_amount/refund_amount/payment_method/payment_status` consistent; the `enabled()` branch.
12. **Folio charge writers** incrementing `bookings.extra_charges` and/or writing `booking_extra_charges` with `source` values (`inspection`, `laundry`) and broadcasting `BookingChargesUpdated`.
13. **Bracketed audit lines appended to `bookings.notes`** (`[Tag: … by Name on Y-m-d H:i:s]`) — UI parses/display these as the reservation activity log.
14. **`LedgerBackedTransaction` fail-closed semantics** for POS settle and stock movements (journal required ⇒ rollback on failure).
15. **Housekeeping state machine** across `room_status_blocks` (`dirty → cleaning → inspected`/`maintenance`, `pending_inspection`), `rooms.status` updates, `RoomCleaningRelease` statuses and audit rows, assignee must hold role `Housekeeping` + `housekeeping-assignable` permission.
16. **Broadcast contract**: channel names, `broadcastAs` names, payload keys (`room_ids`, `reason`, `booking_id`, `extra_charges`, `added_amount`, `badge`), `ShouldBroadcastNow` + `dispatchIfEnabled()` + `App::terminating()` deferral and the `broadcasting.default === 'null'` short-circuit.
17. **Outlet (restaurant) access scoping** in POS/day-closing/channels (Admin/Super Admin → all; else assigned; else by department).
18. **Business date / day-closing locks** for POS (`BusinessDateService` cut-off, `PosDayClosing` existence, sequential closing).
19. **GRN frozen-cost rule** (`.cursor/rules/grn-frozen-cost.mdc`): inventory cost read only from frozen `grn_items`/`grns` fields via `GrnItemCostSnapshot`; `GrnApiPresenter::withCostSnapshots()` on GRN responses.
20. **`Setting::get/set` semantics**: `"0"` is a valid value (no `?:`), booleans stored `'1'/'0'`, non-scalars JSON-encoded, 300s cache with `forget` on set.
21. **Snake_case JSON keys straight from DB columns**, relation names as JSON keys (`room`, `roomType` → `room_type`, `segments`, `booking_group`), and computed attributes (`guest_name`, `seasonal_prices` with `seasons` hidden).
22. **Migrations as anonymous classes with defensive `Schema::has*` guards**, MySQL ENUM widening via raw `ALTER TABLE`, and data/permission changes shipped as migrations.
23. **Test isolation approach**: SQLite in-memory with hand-built schema traits (real migrations are not run in tests).

## Patterns that vary between modules

| Concern | Variants found (module examples) |
|---|---|
| Authorization helper | Trait `authorizePermissions` (Booking, Room*, HK) · private `checkPermission` copies (F&B, inventory masters, settings, users) · `InventoryAuthorization::assert*` (inventory/GRN/procurement/accounting) · `PosController::checkAnyPermission/checkKitchenActionPermission` |
| Admin bypass | None (trait) · `Admin` only (most `checkPermission`) · `Admin` + `Super Admin` (POS, day closing, accounting, `InventoryAuthorization`, some F&B masters) |
| Missing-user handling in `checkPermission` | `abort(401)` (POS, accounting, dashboard) vs silently allow when `$user` null (Department, User, Role, Menu*, Inventory*) |
| Read endpoints | Permission-checked (Booking, Room, GRN, inventory) vs unauthenticated-permission reads (menu categories, departments, users, roles, table categories) |
| Business logic location | Controller-inline (Booking, HK turnover, POS, room par, store requests) · static Support classes (booking availability/ledger/cancellation/transfer) · instance services (GRN, PO, cleaning releases, checklists, day closing, reports) |
| Service style | `final` + static only · instance with constructor DI · mixed static+instance (`GrnService`, `PurchaseOrderService`) |
| DI style | Constructor promotion (`private readonly` in HK/day-closing; plain `private` in menu controllers) · method injection (accounting/dashboard) · `app(X::class)` inline (POS, GRN, Booking) |
| Transactions | `DB::transaction` closure · manual `beginTransaction/commit/rollBack` · `LedgerBackedTransaction::run` · `BookingRoomAvailability::withRoomLocks` · none for some multi-step writes |
| Business error signaling | Early `return response()->json(...,422)` · `ValidationException::withMessages` · `HttpResponseException` inside closures · `RuntimeException`/`InvalidArgumentException` caught → 422 · `['ok' => false, 'message' => …]` result arrays (`BookingRoomTransferService`, `computeHourlyPackageTotal`) |
| Unexpected exception handling | Caught → 500 with raw message (HK, room par, inventory, PO, store requests) vs left to framework |
| Output shaping | Return models directly · private `format*()` · inline `map()` arrays · presenter `setAttribute` enrichment |
| Pagination | None (most) · bare paginator JSON · flattened `{data, current_page, …}` · `{data, meta:{…}}` · fixed vs request `per_page` |
| Status vocabularies | Model `const` (newer: payments, releases, GRN, laundry, journal) vs raw strings (older: bookings, rooms, blocks, POS orders, daily cleaning) |
| Model typing | Typed relation return types (newer models) vs untyped (older); `$casts` property vs `casts()` method (`User`); `decimal:2` casts on some money columns but not bookings |
| Audit trail | Notes-appended text (bookings) · dedicated audit tables (releases, GRN, cost) · immutable ledger rows (payments, refunds, amendments) · actor columns only (most others) |
| Events | `dispatchIfEnabled()` with deferral (`HousekeepingStateUpdated`, `RoomParStockUpdated`) vs direct `event(new …)` (`BookingChargesUpdated`, `PosRestaurantUpdated`, `DailyRoomCleaningDeskNotify`) vs `PosOutletBroadcast` helper |
| Route parameter naming | camelCase (`{roomStatusBlock}`, `{laundryRequest}`) vs snake_case (`{procurement_requisition}`, `{procurement_requisition_item}`) vs raw id params in recipes |
| Validation location | Inline in action · private `validatePayload()`/rule-builder helpers |
| Where-clause style | 4-arg explicit `where('x', '=', $v, 'and')` vs short `where('x', $v)` — often mixed in one method |
| Stock mutation code | Similar `transferStock`/`locationOnHand`/`validateSourceStock`/`formatQty` private methods duplicated in `RoomParController` and `HousekeepingRoomStockController`; POS has its own `executeDeduction/executeInventoryIn` |
| Outlet access check | Implemented separately in `PosController`, `DayClosingController`, `routes/channels.php`, `PropertyFinancialSummaryService` |

## Unknown / needs verification

1. **Production database engine/version and whether all 266 migrations have run** — app code has many `Schema::hasTable/hasColumn` fallbacks (`BookingPaymentLedger::enabled()`, accounting guards), implying some environments may be partially migrated.
2. **`Super Admin` role existence** — referenced in code and permission migrations but not created by any seeder found; confirm in production DB.
3. **Status values outside the DB ENUMs** — code references `completed` (segments) and `refunded` / item-level statuses on POS; confirm whether ENUMs were altered to include them or those values are never persisted.
4. **Frontend reliance on specific response shapes** for endpoints that return bare models (key sets change when eager loads change). Not verified against `passion/` frontend.
5. **Whether the `update()` post-transaction side effects** (segment sync, room status, HK blocks) ever run partially in production (they are outside the transaction) — no evidence gathered either way.
6. **`splitStay` behavior** — does not call `BookingRoomAvailability::assertSellable` for the new room nor use a transaction; confirm whether availability is guaranteed elsewhere (e.g. frontend) or this is accepted.
7. **`DELETE /bookings/{id}`** hard-deletes regardless of status; confirm whether this endpoint is used by the frontend.
8. **Single-room day booking price is client-supplied** (`total_price` from request) while multi-room is recomputed server-side; confirm this is intentional.
9. **Booking deposits are not journaled at receipt time**, only at checkout (`BookingCheckoutPoster`), and cancellation fees/forfeits are not journaled — confirm accounting expectation.
10. **Queue usage** — `queue:listen` runs in dev but no jobs exist; confirm nothing external (e.g. Reverb, mail) relies on the worker in production.
11. **Scheduled tasks** — none defined; confirm no server cron runs artisan commands (window expiry and similar transitions are done lazily on read).
12. **`app/Models/Role.php` / `Permission.php`** — appear dead; confirm no dynamic/string references (e.g. IDE helper or seeders run elsewhere).
13. **`outlets` and `stock_returns` tables** — no models or code references found; confirm they are unused.
14. **`BookingController::syncDailyCleaningOnCheckIn()`** — unused private method; confirm it is intentionally retired.
15. **Throttling on `/api/login`** — no explicit `throttle:` middleware; confirm whether rate limiting exists at proxy level.
16. **Sanctum tokens never expire** (`expiration => null`); confirm operational expectation.
17. **Test suite health** — tests were not executed during this analysis; accounting tests self-skip without tables; unknown pass rate.
18. **Root-level `debug_*.php`, `fix_plan.md`, `passion_db`, `scripts/*`** — purpose and whether they are deployed.
19. **Uncommitted change** in `database/migrations/2026_09_22_085400_add_pos_mini_dash_permission.php` (present in working tree at analysis time) — confirm intended final content before deploy.
20. **Guest identity file storage** on `public` disk — confirm whether ID documents are web-accessible via `storage:link` in production.
21. **Timezone assumptions** — code mixes `Carbon::today()` (app TZ) with client-supplied datetimes and `toDateString()` slices; correctness across DST-free IST is assumed but not verified for UTC-supplied inputs on all endpoints.
