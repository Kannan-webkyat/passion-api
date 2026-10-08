# Room Booking — Bug & Security Fixes (Phase 0–9)

This is the record of the bugs and security issues found in a full review of the room booking section (backend `passion-api` and frontend `passion`), and how they were fixed across 10 phases.

- **Screens:** Room Chart (`/reception/roomChart`) and the Bookings list (`/reception/bookings`).
- **Feature doc (current behaviour):** `docs/ROOM_CHART.md`. This file only records what changed.
- **Intentionally left unchanged:** the demo-admin hint on the login page (`passion/src/app/login/page.tsx`) and the admin password in the seeder (`DatabaseSeeder.php`).
- **Tests:** 406 tests at Phase 0; **490 passing** at the end (84 new tests). Frontend: `tsc` is clean and there are no new ESLint problems.
- **No manual browser testing was done.** Go through the checklist below before deploying.

---

## At a glance

| Phase | Topic | Repo | New tests |
|---|---|---|---|
| 0 | Baseline (existing tests, type-check) | Both | — |
| 1 | Guest ID document security | Both | 10 |
| 2 | Login rate limit, token expiry, security headers | Both | 3 |
| 3 | Guest data exposure, AioSell permission | Both | 4 |
| 4 | Status rules, server-side pricing, payment integrity | API | 14 |
| 5 | Row locks and transactions (double clicks, two desks at once) | API | 7 |
| 6 | Pricing fixes (extend, early checkout, transfer, fees) | API | 11 |
| 7 | Doorloom sync, refund journal, group journal, hourly overlap | API | 5 |
| 8 | Frontend fixes + server-side repricing on PATCH | Both | 2 |
| 9 | Minor backend fixes (timezone, locks, `bill_total`, …) | API | 8 |

---

## Deploy checklist

1. **Move old ID images to the private disk** (otherwise old images will not show on screen):
   ```bash
   php artisan guest-identities:move-to-private --dry-run   # count only
   php artisan guest-identities:move-to-private
   ```
2. **`.env`:** remove `GUEST_IDENTITY_DISK=public` if it is set (the default `local` disk is private).
3. **Frontend build:** `NEXT_PUBLIC_API_URL` and `NEXT_PUBLIC_REVERB_*` must be set at `next build` time. Otherwise the CSP blocks API calls.
4. **Expired token cleanup:** there is no scheduler. Add this to the server cron: `php artisan sanctum:prune-expired --hours=24`.
5. **Permissions:** make sure the roles of staff who reply to AioSell bookings or mark no-shows have `reservation-edit`.
6. **Rate plans:** every room type needs a nightly (day) rate plan. Without one, a day booking returns 422.
7. **Users will need to log in again once:** tokens older than 7 days expire on deploy.
8. **Check once on real MySQL:** do one checkout and one post-checkout refund, then check the trial balance.
9. **Check once in the browser:** booking create, guest edit, ID photo, deposit, checkout, cancel, transfer, request inspection, PDF download, QZ Tray print, Reverb live update. If the CSP blocks something, the console shows a "Content Security Policy" error.

## Behaviour changes the front desk should know

- A returning guest's **ID photo no longer auto-fills from phone search**. Take a new photo for each stay. Name, email and GSTIN still fill in as before.
- Phone search needs **at least 7 digits**.
- After 5 wrong passwords in 5 minutes, **login is blocked for a short while**.
- **A checked-out or cancelled booking cannot be reopened.**
- **To reduce a deposit**, **void** the payment in the payments panel. A confirmation appears before the void.
- If two people change the same booking at the same time, the second one sees *"This reservation was just changed by another action. Reload it and try again."* Reload and try again.
- On the day a nightly guest leaves, an hourly booking for that room **cannot start before the standard check-out time (default 11:00)**.
- AioSell **"Mark no-show"** asks for confirmation and needs `reservation-edit`.

---

## Phase 0 — Baseline

Before any change, the existing booking tests (`tests/Feature/RoomChart`, `tests/Unit/Support`) and `npx tsc --noEmit` were run to see what already failed. No code changes.

## Phase 1 — Guest ID document security

**Problem:** ID images (including Aadhaar) were stored in the public `/storage`. The file type was not checked properly. Sending `%%%%` to guest search could return some guest's ID.

**Changes:**
- **Private storage:** ID images are saved on the private disk, in the `identities/` folder, with random file names.
- **File check:** the server checks the type from the file's contents. JPEG / PNG / WebP only, at most 8 MB per file and 20 per booking. The server chooses the extension.
- **Signed links:** the booking JSON has a new `guest_identity_urls` field. Each link is valid for 12 hours only. Endpoint: `GET /api/guest-identity-files/{path}`.
- **No reuse:** another booking's ID path cannot be used.
- **Guest search:** at least 7 digits; `%` and `_` are escaped. Results do not include ID documents.
- **Cleanup:** if the booking save fails, ID files written by that request are deleted.
- **Migration command:** `guest-identities:move-to-private`.

**Files:** `GuestIdentityImageService.php`, `GuestIdentityFileController.php` (new), `BookingController.php`, `config/guest_identity.php`, `routes/api.php`, `routes/console.php`. Frontend: `src/lib/guestIdentityUrl.ts`, Room Chart, Bookings `GuestsTab.tsx`.
**Tests:** `GuestIdentitySecurityTest.php`, `GuestIdentityImageServiceTest.php`.

## Phase 2 — Login and browser security

- **Login rate limit** (`AuthController::login()`):
  - 5 wrong attempts from the same email + IP within 5 minutes are blocked with 429.
  - More than 30 wrong attempts per minute from one IP are also blocked.
  - A correct login resets the counter.
- **Token expiry:** 7 days (`config/sanctum.php`, `SANCTUM_TOKEN_EXPIRATION`). An expired token returns 401 and the user is sent to the login page.
- **Security headers** (`passion/next.config.ts`): Content-Security-Policy, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (camera allowed for this site only).

**Tests:** `AuthLoginSecurityTest.php`.

## Phase 3 — Guest data exposure, AioSell permission

- **Room Chart data:** users with only `view-rooms` (no `reservation-view`) get a trimmed booking from `GET /bookings/chart`: name, booking number, status, dates and head counts. No phone, email, ID, money or notes.
- **Creator:** booking detail, invoice and transfer responses return only the `creator`'s `id` and `name`.
- **AioSell reply / no-show:** `reservation-edit` is required (otherwise 403). On screen (`AiosellStayPanel.tsx`) the buttons are muted without the permission. No-show has a confirmation dialog and a double-submit guard.

**Tests:** `BookingDataExposureTest.php`.

## Phase 4 — Status rules, server-side pricing, payment integrity

**Create (`POST /bookings`):**
- Only `pending`, `confirmed` and `checked_in` are accepted as status.
- The server also computes `total_price` for single-room day bookings (rate plan, seasons, extra beds, meals, early-arrival fee, GST). The client total is ignored.
- The server sets `payment_status` from the opening deposit.
- A day booking requires a nightly rate plan on the room type.
- `room_occupancy` is validated. Sending `room_ids: []` no longer crashes.

**Edit (`PATCH /bookings/{id}`):**
- **Status transitions:** only `pending ↔ confirmed`, `pending/confirmed → checked_in` and `checked_in → checked_out`. Reopening is blocked. Sending the same status again does nothing.
- **Closed bookings:** room, dates, counts, rate plan, group and money fields cannot change. Contact, GST, notes and ID can.
- **Deposit:** cannot be reduced (use void).
- **Refund:** only together with check-out, and not more than the overpayment.
- **Room change:** checked for availability under a room lock. Multi-room stays must use Room Transfer.
- **One transaction:** availability, ledger, save and checkout journal all happen together. Previously the payment was written before the checks.

**Delete:** only `pending`/`confirmed` bookings with no payment can be deleted.

**Tests:** `BookingStatusMoneyIntegrityTest.php`.

## Phase 5 — Row locks and transactions

Every action locks the booking row (`lockForUpdate`) just before saving. If the status, room, dates, early/late time or money changed after the screen was loaded, the action is refused.

- **Payment / refund / void** (`BookingPaymentLedger`): refunds cannot exceed the limit, payments cannot go to a closed booking, and the same payment cannot be voided twice.
- **Cancel, check-out, PATCH, change check-in date:** row lock added.
- **Extend (nights / hours), early checkout:** made a single transaction. Availability is re-checked after taking the room lock.
- **Early check-in / late checkout fee:** made atomic. The fee is never lost or added twice.
- **Room transfer:** locks both rooms, the booking and the segment. Availability is checked inside the lock.

**Tests:** `BookingConcurrencyTest.php`.

## Phase 6 — Pricing fixes

- **Extend nights, split stay:** priced from rate plan + seasons + extra beds + meals + GST. When `room_rates_include_gst` is on, GST is not added again. Hourly bookings cannot use Extend nights.
- **Early checkout:** the booked rate is kept. Example: a ₹6000 4-night stay shortened to 2 nights becomes ₹3000.
- **Room transfer (`apply_new_category`):** split by nights. It used to split by minutes, which overcharged about 0.67 night.
- **Early check-in fee added twice:** if an arrival time was given at create, a later early check-in adds only the difference to the folio.
- **`percentage` fee type:** a percentage of the rate plan's nightly price (it used to be charged as a flat amount).
- **Late checkout:** uses the last room's fee policy. Early check-in uses the first room's.
- **Cancellation:** settled on the net deposit (`deposit − refund`).
- **Extend hours:** returns 422 for cancelled / checked-out bookings, or when the room is on hold / under maintenance.
- **Helpers:** `BookingInvoiceRoomStay::timeFeeAmount()`, `nightlyRateForFees()`, `earlyCheckInFolioFeeFromNotes()`.

**Tests:** `BookingStayPricingTest.php`.

## Phase 7 — Integrations and accounting

- **Change check-in date:** syncs to Doorloom and AioSell after saving.
- **Post-checkout refund journal** (`BookingRefundPoster`, new, source `booking_refund`):
  - Refunding only the overpayment posts no journal.
  - Any part above that reverses Room Revenue + CGST/SGST and credits the tender account.
- **Group checkout journal:** each departing room draws from the group's pooled payment (`BookingCheckoutPoster::postGroupDeparture()`). Previously rooms without their own payment were posted as open Folio AR.
- **Hourly vs nightly overlap** (`BookingRoomAvailability`): on a nightly guest's departure day, hourly bookings are blocked until the standard check-out (or late checkout) time. The same rule applies the other way round. Back-to-back nightly bookings are not affected.

**Tests:** `BookingIntegrationsAccountingTest.php`.

## Phase 8 — Frontend fixes (+ one backend change)

**Backend:**
- `PATCH /bookings/{id}` ignores the `total_price` sent by the client.
- When guests, extra beds or the rate plan change, the server computes the total: stored total + (new mix price − old mix price). This is `repricedTotalForGuestChange()`.
- Negotiated rates, extensions and transfers already in the total stay as they are.

**Frontend (Room Chart):**
- **Double-submit guard:** Add deposit, checkout payment, Extend hours.
- **Payment void:** confirmation dialog (`BookingPaymentsPanel.tsx`).
- **Checkout:** sends `refund_amount_after` from the preview.
- **Extend preview:** shows only the amount from the server's `preview-extend` (the browser estimate and its UTC bug were removed).
- **Create, guest edit:** no longer send `total_price` or `payment_status`.
- **Chart:** stale response guard, and a toast when loading fails.
- **Camera:** turns off when leaving the page.
- **Rounding:** deposit amounts and `bill_total` are rounded to paise.
- **Cancel dialog:** the preview refreshes automatically when inputs change. The Cancel button stays disabled until the preview arrives.
- **Corrupt `user_data`:** no crash; the user is sent to login (`layout.tsx`, `portalAuth.ts`).
- **Suspense:** boundary added around the Room Chart page.

## Phase 9 — Minor backend fixes

- **Chart range:** at most 62 days. `end < start` returns 422.
- **Walk-in (create as `checked_in`):** the arrival day is checked in hotel time.
- **Available rooms:** UTC times are converted to hotel time. Uses the same overlap rule as create (including the departure-morning rule).
- **Group checkout:** locks all departing rooms in id order, so no deadlocks.
- **Failed edit:** new ID uploads are deleted.
- **`bill_total`:** the payment and void endpoints ignore the client value. `payment_status` is set from the server's bill.
- **Request inspection:** blocks, room status and the Activity line are written in one transaction with a row lock.
- **Transfer preview:** made read-only (it used to write a segment to the DB for older bookings).

**Tests:** `BookingEdgeRulesTest.php`.

---

## Decisions made by the user

| Question | Decision |
|---|---|
| Login token expiry | 7 days |
| Reopening a checked-out booking | Blocked |
| Refund after checkout | Allowed, with a journal entry |
| Hourly vs nightly on departure day | Blocked until the standard check-out time |
| `on_hold` release on early checkout | Unchanged: all holds starting in the freed nights are released |

## Known limitations

- The post-checkout refund reversal goes entirely to room revenue. The F&B part of the folio is not split out.
- Guest-edit repricing of a multi-room (split / transfer) stay uses the current room's type.
- The frontend chart grid does not know the departure-morning rule. A room can show as free and the server refuses it on save. The available-rooms list handles this correctly.
- `GET /bookings` is not paginated. This was left on purpose because the Bookings screen loads the whole list at once.
- The test DB has no journal tables. The accounting tests create them inside those tests only.

## Other docs updated

- `docs/ROOM_CHART.md`, `docs/DOORLOOM.md`, `docs/AIOSELL.md`
- `PASSION_LARAVEL_ARCHITECTURE.md`, `../passion/PASSION_FRONTEND_ARCHITECTURE.md`
- Agent rules / skills (backend): `.cursor/rules/30-api-contract.mdc`, `40-security.mdc`, `50-hotel-domain.mdc`; `.cursor/skills/billing`, `reservation`, `room-availability`.
- Agent rules / skills (frontend): `../passion/.cursor/rules/95-forms-validation.mdc`; `../passion/.cursor/skills/reservation-ui`, `api-integration`.
