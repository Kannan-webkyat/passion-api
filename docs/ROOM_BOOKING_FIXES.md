# Room Booking — Bug & Security Fixes (Phase 0–9)

Room booking section (backend `passion-api` + frontend `passion`) മുഴുവൻ review ചെയ്ത് കണ്ടെത്തിയ bugs-ഉം security issues-ഉം 10 phases-ൽ ആയി ശരിയാക്കിയതിന്റെ രേഖയാണ് ഇത്.

- **Screen:** Room Chart (`/reception/roomChart`), Bookings list (`/reception/bookings`).
- **Feature doc (ഇപ്പോഴത്തെ behaviour):** `docs/ROOM_CHART.md`. ഈ file "എന്ത് മാറി" എന്ന് മാത്രം പറയുന്നു.
- **മനഃപൂർവം മാറ്റാത്തത്:** Login page-ലെ demo-admin hint (`passion/src/app/login/page.tsx`), seeder-ലെ admin password (`DatabaseSeeder.php`).
- **Tests:** Phase 0-ൽ 406 → അവസാനം **490 pass** (84 പുതിയ tests). Frontend: `tsc` clean, പുതിയ ESLint problems ഇല്ല.
- **Browser manual test ചെയ്തിട്ടില്ല.** Deploy-ന് മുമ്പ് താഴെയുള്ള checklist നോക്കുക.

---

## ഒറ്റനോട്ടത്തിൽ

| Phase | വിഷയം | Repo | പുതിയ tests |
|---|---|---|---|
| 0 | Baseline (നിലവിലെ tests, type-check) | രണ്ടും | — |
| 1 | Guest ID documents security | രണ്ടും | 10 |
| 2 | Login rate limit, token expiry, security headers | രണ്ടും | 3 |
| 3 | Guest data exposure, Aiosell permission | രണ്ടും | 4 |
| 4 | Status rules, server pricing, payment integrity | API | 14 |
| 5 | Row locks, transactions (double click / രണ്ട് desk) | API | 7 |
| 6 | Pricing fixes (extend, early checkout, transfer, fees) | API | 11 |
| 7 | Doorloom sync, refund journal, group journal, hourly overlap | API | 5 |
| 8 | Frontend fixes + PATCH server repricing | രണ്ടും | 2 |
| 9 | ചെറിയ backend fixes (timezone, locks, `bill_total`…) | API | 8 |

---

## Deploy checklist (ചെയ്യേണ്ടവ)

1. **പഴയ ID images private disk-ലേക്ക് മാറ്റുക** (ഇല്ലെങ്കിൽ പഴയ images screen-ൽ കാണില്ല):
   ```bash
   php artisan guest-identities:move-to-private --dry-run   # എണ്ണം മാത്രം
   php artisan guest-identities:move-to-private
   ```
2. **`.env`:** `GUEST_IDENTITY_DISK=public` ഉണ്ടെങ്കിൽ remove ചെയ്യുക (default `local` = private).
3. **Frontend build:** `NEXT_PUBLIC_API_URL`, `NEXT_PUBLIC_REVERB_*` എന്നിവ `next build` സമയത്ത് set ആയിരിക്കണം. ഇല്ലെങ്കിൽ CSP API calls block ചെയ്യും.
4. **Expired tokens cleanup:** scheduler ഇല്ല. Server cron-ൽ ചേർക്കുക: `php artisan sanctum:prune-expired --hours=24`.
5. **Permissions:** Aiosell reply / no-show ചെയ്യുന്ന staff-ന്റെ role-ൽ `reservation-edit` ഉണ്ടെന്ന് ഉറപ്പാക്കുക.
6. **Rate plans:** ഓരോ room type-നും ഒരു nightly (day) rate plan വേണം. ഇല്ലെങ്കിൽ day booking 422 തരും.
7. **Users ഒരിക്കൽ വീണ്ടും login ചെയ്യേണ്ടിവരും:** 7 ദിവസത്തിൽ പഴയ tokens deploy-ഓടെ expire ആകും.
8. **Real MySQL-ൽ ഒരിക്കൽ നോക്കുക:** ഒരു checkout-ഉം ഒരു post-checkout refund-ഉം ചെയ്ത് trial balance.
9. **Browser-ൽ ഒരിക്കൽ നോക്കുക:** booking create, guest edit, ID photo, deposit, checkout, cancel, transfer, request inspection, PDF download, QZ Tray print, Reverb live update. CSP block ആണെങ്കിൽ console-ൽ "Content Security Policy" error കാണും.

## Front desk അറിയേണ്ട behaviour മാറ്റങ്ങൾ

- വീണ്ടും വരുന്ന guest-ന്റെ **ID photo phone search വഴി auto-fill ആകില്ല**. ഓരോ stay-ക്കും പുതിയ photo എടുക്കണം. Name, email, GSTIN പഴയതുപോലെ fill ആകും.
- Phone search-ന് **കുറഞ്ഞത് 7 അക്കം** വേണം.
- 5 മിനിറ്റിൽ 5 തവണ password തെറ്റിയാൽ **login കുറച്ചുനേരം block** ആകും.
- **Checked-out / cancelled booking വീണ്ടും തുറക്കാൻ പറ്റില്ല.**
- **Deposit കുറയ്ക്കാൻ** payments panel-ൽ **void** ചെയ്യണം. Void-ന് മുമ്പ് confirmation വരും.
- രണ്ടുപേർ ഒരേ booking ഒരേസമയം മാറ്റിയാൽ രണ്ടാമത്തെയാൾക്ക് *"This reservation was just changed by another action. Reload it and try again."* എന്ന് കാണും. Reload ചെയ്ത് വീണ്ടും ചെയ്യുക.
- Nightly guest പോകുന്ന ദിവസം **standard check-out time (default 11:00) വരെ** ആ room-ൽ hourly booking എടുക്കാൻ പറ്റില്ല.
- Aiosell **"Mark no-show"**-ന് confirmation ഉണ്ട്, `reservation-edit` വേണം.

---

## Phase 0 — Baseline

മാറ്റങ്ങൾ തുടങ്ങുന്നതിന് മുമ്പ് നിലവിലെ booking tests (`tests/Feature/RoomChart`, `tests/Unit/Support`), `npx tsc --noEmit` എന്നിവ run ചെയ്തു. ആദ്യം മുതലേ fail ആകുന്നവ ഏതൊക്കെയെന്ന് അറിയാനായിരുന്നു ഇത്. Code മാറ്റം ഇല്ല.

## Phase 1 — Guest ID documents security

**പ്രശ്നം:** ID images (Aadhaar ഉൾപ്പെടെ) public `/storage`-ൽ ആയിരുന്നു. File type ശരിയായി check ചെയ്തിരുന്നില്ല. Guest search-ൽ `%%%%` അയച്ചാൽ ഏതെങ്കിലും ഒരു guest-ന്റെ ID കിട്ടുമായിരുന്നു.

**മാറ്റം:**
- **Private storage:** ID images private disk-ൽ, `identities/` folder-ൽ, random file name-ഓടെ save ചെയ്യുന്നു.
- **File check:** server file-ന്റെ contents നോക്കിയാണ് type check ചെയ്യുന്നത്. JPEG / PNG / WebP മാത്രം, ഒരു file പരമാവധി 8 MB, ഒരു booking-ൽ പരമാവധി 20 എണ്ണം. Extension server തന്നെ തീരുമാനിക്കും.
- **Signed link:** booking JSON-ൽ പുതിയ `guest_identity_urls` field ഉണ്ട്. ഓരോ link-ഉം 12 മണിക്കൂർ മാത്രം valid. Endpoint: `GET /api/guest-identity-files/{path}`.
- **ID reuse block:** മറ്റൊരു booking-ന്റെ ID path ഉപയോഗിക്കാൻ പറ്റില്ല.
- **Guest search:** കുറഞ്ഞത് 7 അക്കം, `%` / `_` escape ചെയ്യുന്നു. Result-ൽ ID documents വരില്ല.
- **Cleanup:** booking save fail ആയാൽ ആ request-ൽ എഴുതിയ ID files delete ചെയ്യും.
- **Migration command:** `guest-identities:move-to-private`.

**Files:** `GuestIdentityImageService.php`, `GuestIdentityFileController.php` (പുതിയത്), `BookingController.php`, `config/guest_identity.php`, `routes/api.php`, `routes/console.php`. Frontend: `src/lib/guestIdentityUrl.ts`, Room Chart, Bookings `GuestsTab.tsx`.
**Tests:** `GuestIdentitySecurityTest.php`, `GuestIdentityImageServiceTest.php`.

## Phase 2 — Login & browser security

- **Login rate limit** (`AuthController::login()`):
  - ഒരേ email + IP-ൽ നിന്ന് 5 മിനിറ്റിൽ 5 തവണ തെറ്റിയാൽ block, 429 തരും.
  - ഒരു IP-ൽ നിന്ന് മിനിറ്റിൽ 30 തെറ്റായ attempts കഴിഞ്ഞാലും block.
  - ശരിയായ login counter reset ചെയ്യും.
- **Token expiry:** 7 ദിവസം (`config/sanctum.php`, `SANCTUM_TOKEN_EXPIRATION`). Expire ആയാൽ 401 തരും, user login page-ലേക്ക് പോകും.
- **Security headers** (`passion/next.config.ts`): Content-Security-Policy, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy` (camera ഈ site-ന് മാത്രം).

**Tests:** `AuthLoginSecurityTest.php`.

## Phase 3 — Guest data exposure, Aiosell permission

- **Room Chart data:** `view-rooms` മാത്രമുള്ള user-ന് (`reservation-view` ഇല്ലാത്തവർക്ക്) `GET /bookings/chart` ചുരുക്കം മാത്രം തരും: name, booking number, status, dates, head counts. Phone, email, ID, money, notes വരില്ല.
- **Creator:** booking detail, invoice, transfer എന്നിവയിൽ `creator`-ന്റെ `id`, `name` മാത്രം.
- **Aiosell reply / no-show:** `reservation-edit` നിർബന്ധം (ഇല്ലെങ്കിൽ 403). Screen-ൽ (`AiosellStayPanel.tsx`) permission ഇല്ലെങ്കിൽ buttons muted ആണ്. No-show-ന് confirmation dialog-ഉം double-submit guard-ഉം ഉണ്ട്.

**Tests:** `BookingDataExposureTest.php`.

## Phase 4 — Status rules, server pricing, payment integrity

**Create (`POST /bookings`):**
- Status ആയി `pending`, `confirmed`, `checked_in` മാത്രം accept ചെയ്യും.
- Single-room day booking-ന്റെ `total_price`-ഉം server കണക്കാക്കും (rate plan, seasons, extra beds, meals, early-arrival fee, GST). Client total എടുക്കില്ല.
- `payment_status` server opening deposit നോക്കി set ചെയ്യും.
- Day booking-ന് room type-ന്റെ nightly rate plan നിർബന്ധം.
- `room_occupancy` validate ചെയ്യും. `room_ids: []` അയച്ചാൽ crash ആകില്ല.

**Edit (`PATCH /bookings/{id}`):**
- **Status transitions:** `pending ↔ confirmed`, `pending/confirmed → checked_in`, `checked_in → checked_out` മാത്രം. Reopen block. ഇതേ status വീണ്ടും അയച്ചാൽ ഒന്നും നടക്കില്ല.
- **Close ചെയ്ത booking:** room, dates, counts, rate plan, group, money fields മാറ്റാൻ പറ്റില്ല. Contact, GST, notes, ID മാറ്റാം.
- **Deposit:** കുറയ്ക്കാൻ പറ്റില്ല (void ഉപയോഗിക്കണം).
- **Refund:** check-out-ന്റെ കൂടെ മാത്രം, overpayment-ൽ കൂടരുത്.
- **Room മാറ്റം:** room lock എടുത്ത് availability check ചെയ്യും. Multi-room stay ആണെങ്കിൽ Room Transfer ഉപയോഗിക്കണം.
- **ഒറ്റ transaction:** availability, ledger, save, checkout journal എല്ലാം ഒരുമിച്ച്. മുമ്പ് payment ആദ്യം എഴുതിയിട്ടായിരുന്നു check.

**Delete:** payment ഇല്ലാത്ത `pending`/`confirmed` booking മാത്രം delete ചെയ്യാം.

**Tests:** `BookingStatusMoneyIntegrityTest.php`.

## Phase 5 — Row locks & transactions

ഓരോ action-ഉം save ചെയ്യുന്നതിന് തൊട്ടുമുമ്പ് booking row lock ചെയ്യും (`lockForUpdate`). Screen load ചെയ്തശേഷം status, room, dates, early/late time, money എന്നിവയിൽ ഏതെങ്കിലും മാറിയിട്ടുണ്ടെങ്കിൽ action refuse ചെയ്യും.

- **Payment / refund / void** (`BookingPaymentLedger`): refund limit കടക്കില്ല. Closed booking-ലേക്ക് payment പോകില്ല. ഒരേ payment രണ്ടുവട്ടം void ആകില്ല.
- **Cancel, check-out, PATCH, change check-in date:** row lock ചേർത്തു.
- **Extend (nights / hours), early checkout:** ഒറ്റ transaction ആക്കി. Room lock എടുത്ത ശേഷം availability വീണ്ടും check ചെയ്യും.
- **Early check-in / late checkout fee:** atomic ആക്കി. Fee നഷ്ടപ്പെടില്ല, രണ്ടുവട്ടം ചേരില്ല.
- **Room transfer:** രണ്ട് rooms-ഉം booking-ഉം segment-ഉം lock ചെയ്യും. Availability lock-നുള്ളിൽ check ചെയ്യും.

**Tests:** `BookingConcurrencyTest.php`.

## Phase 6 — Pricing fixes

- **Extend nights, split stay:** rate plan + seasons + extra beds + meals + GST വെച്ച് price കണക്കാക്കും. `room_rates_include_gst` on ആണെങ്കിൽ GST വീണ്ടും കൂട്ടില്ല. Hourly booking-ന് Extend nights ഇല്ല.
- **Early checkout:** booked rate നിലനിൽക്കും. ഉദാ: ₹6000-ന്റെ 4-night stay 2 nights ആക്കിയാൽ ₹3000.
- **Room transfer (`apply_new_category`):** nights അനുസരിച്ച് split ചെയ്യും. മുമ്പ് മിനിറ്റ് കണക്കിന് split ചെയ്തിരുന്നതുകൊണ്ട് ~0.67 night അധികം charge ആയിരുന്നു.
- **Early check-in fee രണ്ടുവട്ടം ചേരുന്നത്:** create-ൽ arrival time കൊടുത്തിട്ടുണ്ടെങ്കിൽ, പിന്നീട് early check-in ചെയ്യുമ്പോൾ difference മാത്രം folio-യിൽ ചേർക്കും.
- **`percentage` fee type:** rate plan-ന്റെ nightly price-ന്റെ percentage (മുമ്പ് flat ആയി charge ചെയ്തിരുന്നു).
- **Late checkout:** last room-ന്റെ fee policy. Early check-in first room-ന്റേത്.
- **Cancellation:** net deposit (`deposit − refund`) വെച്ചാണ് settlement.
- **Extend hours:** cancelled / checked-out booking-ൽ, അല്ലെങ്കിൽ room on hold / maintenance ആണെങ്കിൽ 422.
- **Helpers:** `BookingInvoiceRoomStay::timeFeeAmount()`, `nightlyRateForFees()`, `earlyCheckInFolioFeeFromNotes()`.

**Tests:** `BookingStayPricingTest.php`.

## Phase 7 — Integrations & accounting

- **Change check-in date:** save കഴിഞ്ഞാൽ Doorloom-ഉം AioSell-ഉം sync ചെയ്യും.
- **Checkout-നു ശേഷമുള്ള refund journal** (`BookingRefundPoster`, പുതിയത്, source `booking_refund`):
  - Overpayment മാത്രം തിരിച്ചുകൊടുക്കുമ്പോൾ journal ഇല്ല.
  - അതിനു മുകളിലുള്ള ഭാഗം Room Revenue + CGST/SGST reverse ചെയ്ത് tender account credit ചെയ്യും.
- **Group checkout journal:** ഓരോ departing room-ഉം group-ന്റെ pooled payment-ൽ നിന്ന് എടുക്കും (`BookingCheckoutPoster::postGroupDeparture()`). മുമ്പ് payment ഇല്ലാത്ത rooms Folio AR open ആയി post ആയിരുന്നു.
- **Hourly vs nightly overlap** (`BookingRoomAvailability`): nightly guest-ന്റെ departure ദിവസം standard check-out (അല്ലെങ്കിൽ late checkout) time വരെ hourly booking block ചെയ്യും. തിരിച്ചും ഇതേ rule. Back-to-back nightly bookings-നെ ഇത് ബാധിക്കില്ല.

**Tests:** `BookingIntegrationsAccountingTest.php`.

## Phase 8 — Frontend fixes (+ ഒരു backend മാറ്റം)

**Backend:**
- `PATCH /bookings/{id}` client അയക്കുന്ന `total_price` ignore ചെയ്യും.
- Guests, extra beds, rate plan മാറുമ്പോൾ server total കണക്കാക്കും: stored total + (പുതിയ mix-ന്റെ വില − പഴയതിന്റെ വില). ഇതാണ് `repricedTotalForGuestChange()`.
- Negotiated rate, extension, transfer എന്നിവ total-ൽ അതേപടി നിൽക്കും.

**Frontend (Room Chart):**
- **Double-submit guard:** Add deposit, checkout payment, Extend hours.
- **Payment void:** confirmation dialog (`BookingPaymentsPanel.tsx`).
- **Checkout:** preview-ലെ `refund_amount_after` അയക്കും.
- **Extend preview:** server-ന്റെ `preview-extend` തുക മാത്രം കാണിക്കും (browser estimate-ഉം UTC bug-ഉം മാറ്റി).
- **Create, guest edit:** `total_price`, `payment_status` അയക്കുന്നില്ല.
- **Chart:** stale response guard, load fail ആയാൽ toast.
- **Camera:** page വിട്ടാൽ off ആകും.
- **Rounding:** deposit amounts, `bill_total` paise-ലേക്ക് round ചെയ്യും.
- **Cancel dialog:** inputs മാറുമ്പോൾ preview auto-refresh ആകും. Preview വരുന്നതുവരെ Cancel button disabled.
- **Corrupt `user_data`:** crash ആകില്ല, login-ലേക്ക് പോകും (`layout.tsx`, `portalAuth.ts`).
- **Suspense:** Room Chart page-ന് boundary ചേർത്തു.

## Phase 9 — ചെറിയ backend fixes

- **Chart range:** പരമാവധി 62 days. `end < start` ആണെങ്കിൽ 422.
- **Walk-in (create as `checked_in`):** arrival day hotel time-ൽ നോക്കും.
- **Available rooms:** UTC times hotel time-ലേക്ക് മാറ്റും. Create-ലെ അതേ overlap rule ഇവിടെയും (departure-morning ഉൾപ്പെടെ).
- **Group checkout:** departing rooms എല്ലാം id order-ൽ lock ചെയ്യും. Deadlock ഇല്ല.
- **Edit fail ആയാൽ** പുതിയ ID uploads delete ചെയ്യും.
- **`bill_total`:** payment, void endpoints client value ignore ചെയ്യും. `payment_status` server bill വെച്ച് set ചെയ്യും.
- **Request inspection:** blocks, room status, Activity line എല്ലാം ഒറ്റ transaction-ൽ, row lock-ഓടെ.
- **Transfer preview:** read-only ആക്കി (മുമ്പ് പഴയ bookings-ൽ ഒരു segment DB-യിൽ എഴുതുമായിരുന്നു).

**Tests:** `BookingEdgeRulesTest.php`.

---

## User എടുത്ത തീരുമാനങ്ങൾ

| ചോദ്യം | തീരുമാനം |
|---|---|
| Login token expiry | 7 ദിവസം |
| Checked-out booking reopen | Block |
| Checkout-നു ശേഷം refund | അനുവദിക്കും, journal entry-ഓടെ |
| Hourly vs nightly departure ദിവസം | Standard check-out time വരെ block |
| Early checkout-ൽ `on_hold` release | ഇപ്പോഴത്തെ പോലെ: ഒഴിവാകുന്ന nights-ൽ start ചെയ്യുന്ന എല്ലാ holds-ഉം release ചെയ്യും |

## ഇപ്പോഴും ഉള്ള പരിമിതികൾ

- Post-checkout refund reversal മുഴുവൻ room revenue-ലേക്ക് പോകും. Folio-യിലെ F&B ഭാഗം വേറെ split ചെയ്യുന്നില്ല.
- Guest edit-ൽ multi-room (split / transfer) stay-യുടെ repricing ഇപ്പോഴത്തെ room-ന്റെ type വെച്ചാണ്.
- Frontend chart grid-ന് departure-morning rule അറിയില്ല. Room free ആയി കാണിക്കാം, save ചെയ്യുമ്പോൾ server refuse ചെയ്യും. Available-rooms list-ൽ ഇത് ശരിയാണ്.
- `GET /bookings` paginate ചെയ്തിട്ടില്ല. Bookings screen ഒറ്റയടിക്ക് മുഴുവൻ load ചെയ്യുന്നതുകൊണ്ട് മനഃപൂർവം അങ്ങനെ വിട്ടു.
- Test DB-യിൽ journal tables ഇല്ല. Accounting tests അവ ആ test-ൽ മാത്രം ഉണ്ടാക്കിയാണ് run ചെയ്യുന്നത്.

## Update ചെയ്ത മറ്റ് docs

- `docs/ROOM_CHART.md`, `docs/DOORLOOM.md`, `docs/AIOSELL.md`
- `PASSION_LARAVEL_ARCHITECTURE.md`, `../passion/PASSION_FRONTEND_ARCHITECTURE.md`
- Agent rules / skills (backend): `.cursor/rules/30-api-contract.mdc`, `40-security.mdc`, `50-hotel-domain.mdc`; `.cursor/skills/billing`, `reservation`, `room-availability`.
- Agent rules / skills (frontend): `../passion/.cursor/rules/95-forms-validation.mdc`; `../passion/.cursor/skills/reservation-ui`, `api-integration`.
