# Room Chart — Passion Hotel PMS

The Room Chart is the reservation and front-desk screen. It shows every room against a run of days, and reception uses it to take a booking, hold or block a room, check a guest in, change a stay, take payment, and check the guest out.

Screen: Reception → Room Chart (`/reception/roomChart`). Owner code: `reception/roomChart/page.tsx` (state and every handler) with `RoomChartDrawer.tsx` (the side panel). API: `BookingController` and `RoomStatusBlockController`.

## Who can do what

The side-menu item shows with any of `reservation-view`, `view-rooms`, `manage-rooms`, `rooms-view`. The API checks each call. There is no Admin bypass on these endpoints.

| Action | Permission (any of) |
|---|---|
| See the chart and summary tiles | `reservation-view`, `reservation`, `view-rooms`, `manage-rooms`, `rooms-view` |
| Open a booking, folio, payments | `reservation-view`, `reservation` |
| Find free rooms for a move, split or transfer | `reservation-create`, `reservation-create-group`, `reservation-edit`, `reservation`, `view-rooms` |
| New single-room booking | `reservation-create` |
| New group booking (more than one room, or a group name) | `reservation-create-group` |
| Check in, check out, edit, every stay change, take or void a payment | `reservation-edit` |
| Cancel a booking | `reservation-delete` |
| Voucher and billing PDF | `reservation-view`, `reservation-edit` |
| Hold a room | `reservation-hold-room` |
| Maintenance block | `reservation-maintenance-room` |
| Mark a room dirty or cleaning | `manage-rooms`, `housekeeping-dirty-rooms`, `housekeeping-cleaning-tasks` |
| Release a room for cleaning | `housekeeping-cleaning-availability` |

A button the user cannot use stays on screen, muted. Clicking it shows a "Not allowed" message.

Without `reservation-view` or `reservation`, the chart still draws each stay, but its booking carries only the guest name, booking number, status, dates and times, and head counts. Phone, email, ID documents, money, and notes are left out. Booking detail returns only the creator's `id` and `name`.

## The grid

The screen loads `GET /bookings/chart?start=&end=`, `GET /bookings/summary?date=`, and `GET /room-types` together. The chart range is at most 62 days, and `end` cannot be before `start` (422). It loads them again after every change and whenever housekeeping state changes in another tab or on another device.

- Window: Today, Week (7 days), Month (30 days), or Custom.
- Filters: room type, floor, status.
- Summary tiles: total rooms, available, occupied, reserved, maintenance, on hold, dirty, cleaning, for the first day shown, plus today's check-ins and check-outs.
- Each cell combines the room's booked stays with its room blocks. Block statuses: `maintenance`, `on_hold`, `dirty`, `cleaning`, `inspected`, `pending_inspection`. A `confirmed` booking shows as "Reserved".
- Who is in a room comes from booked stays (`booking_segments`) and room blocks, not from `rooms.status`.
- While Doorloom is on, the chart also reads `GET /doorloom/calendar` and shows a note when Doorloom has stop-sell or fewer free rooms on some nights (see `DOORLOOM.md`).

Clicking a free cell opens a menu: **New Reservation**, **Hold Room**, **Maintenance**. Clicking a booked cell opens the booking panel. Past dates cannot take a new booking.

## New booking

`POST /bookings`.

- One room is a single booking. More than one room, or a group name, is a group booking. Each room gets its own booking under one `booking_group_id`.
- Booking type: Day (per night) or hourly package (`booking_unit: hour_package`, 3, 6 or 12 hours).
- Group booking room picker: lists the rooms free for the chosen dates. A room-type filter narrows the list. Rooms already picked stay visible under any filter so they can be removed.
- Per room: rate plan, adults, children (with ages), infants, extra beds, meal counts. Each room is checked against capacity and extra-bed limits.
- Optional: guest phone lookup (`GET /bookings/guest-search?phone=`), GST details, guest ID images (compressed before upload), opening deposit.
- Phone lookup needs at least 7 digits. It fills name, email, phone, city, country, bill-to and GSTIN from the newest booking with that phone. It does not bring over ID documents; a returning guest's ID is photographed again.
- The API refuses a past check-in, refuses `checked_in` unless arrival is today, and checks each room again with a row lock before saving.
- A night guest keeps the room until the standard check-out time (`standard_check_out_time`, default 11:00) on the departure day, or until their late checkout time. An hourly stay that day cannot start earlier, and a new day stay cannot depart into an hourly stay that starts before standard check-out. Both are refused as "already reserved for the selected dates". A new booking starts as `pending`, `confirmed` or `checked_in`; other statuses are refused.
- Each day-stay room needs a nightly rate plan of its own room type. The server prices every day-stay room (single or group) from that plan, seasons, extra beds, meals, the early-arrival fee and GST; the total the screen sends is ignored. `payment_status` is set by the server from the opening deposit, not taken from the request.
- Per-room values in `room_occupancy` are validated (adults at least 1, breakfast counts not above the room's guests).
- An opening deposit is recorded in the payment ledger with source `booking_create`.
- Rate Calendar can open the chart with the new-booking form filled in (`?check_in=&check_out=&room_type_id=…&from=rateCalendar`).

## Holds, maintenance and housekeeping blocks

`POST /room-status-blocks`, `PATCH /room-status-blocks/{id}` (`is_active: false` removes a block), `DELETE /room-status-blocks/{id}`.

- Hold (`on_hold`) and maintenance need a note and cannot start in the past.
- A block is refused when a stay already covers those dates, or another active block overlaps.
- `on_hold` and `maintenance` make the room unsellable. `dirty` and `cleaning` still allow future bookings but stop check-in.
- `rooms.status` follows the block that covers today.
- A new `dirty` block records a portal notification.

## Booking panel

Tabs: Stay details, Room stock, Room Cleaning History, Laundry.

For a group booking, **Linked Stays** lists every room in the group. Clicking one switches the panel to that room. Edits made in one room (guests, ID documents, payments, dates) carry over when you switch back.

Guest counts, contact, GST details and ID documents save with `PATCH /bookings/{id}`. Every change appends a line to the booking notes, shown under Activity.

Rules on `PATCH /bookings/{id}`:
- Status moves only `pending` ↔ `confirmed`, `pending`/`confirmed` → `checked_in`, and `checked_in` → `checked_out`. A `checked_out` or `cancelled` booking cannot be reopened. Sending the current status again changes nothing.
- Once a booking is `checked_out` or `cancelled`, its room, dates, guest counts, rate plan, group and money fields are refused with 422. Contact, GST details, notes and ID documents can still be edited.
- `total_price` and `payment_status` are not taken from the request. When adults, children, child ages, extra beds or the rate plan change, the server moves the stored room total by the price difference between the old and new guest mix (rate plan, seasons, extra beds, meals, GST setting; hourly stays use the package price). A negotiated rate, extension or transfer already in the total is kept. The early-arrival fee follows the arrival time given at booking; a later early check-in stays on the folio. `payment_status` is then set again from money received against the new bill.
- The amount received cannot be lowered here (void the payment instead). A refund is accepted only with check-out.
- A room change is checked for availability under a room lock. A stay that already uses more than one room must use Room Transfer.
- Availability, payment-ledger postings, the booking save and the checkout journal commit together; a refusal leaves nothing half-written.

### Guest ID documents

- Accepted: JPEG, PNG or WebP images, up to 8 MB each (`GUEST_IDENTITY_MAX_UPLOAD_BYTES`), at most 20 per booking. The server checks the file contents, not the name the browser sends, and picks the file extension itself.
- Files are saved on the private disk (`GUEST_IDENTITY_DISK`, default `local`) under `identities/` with a random name. They are not reachable under `/storage`.
- Booking JSON carries the stored paths in `guest_identities` and, at the same index, a signed link in `guest_identity_urls` (`GET /api/guest-identity-files/{path}?expires=&signature=`). A link works for 12 hours (`GUEST_IDENTITY_URL_TTL_MINUTES`); reloading the booking gives a fresh one.
- A new booking only takes new uploads. An edit keeps the booking's own stored paths and takes new uploads; a path from another booking is refused with 422.
- When a booking create or edit is refused or fails, ID files written by that request are deleted.
- Moving files saved before this change: `php artisan guest-identities:move-to-private` (add `--dry-run` to only count).

## Check-in

`PUT /bookings/{id}` with `status: checked_in`.

1. Only on the arrival date (an hourly stay uses its start time). A booking created straight as `checked_in` (walk-in) follows the same rule, with the arrival read in hotel time.
2. Refused while the room has an active `dirty` or `cleaning` block today. The screen shows the dirty-room dialog.
3. The stay and every room in it become occupied. Other screens and tabs refresh.

**Early check-in** (`POST /bookings/{id}/early-checkin`) and **late checkout** (`POST /bookings/{id}/late-checkout`) record the time and add the room-type fee (per hour, per minute, flat, or `percentage` of the rate plan's nightly price, after the free buffer) to the folio. They do not change status. Early check-in uses the first room's policy and late checkout the last room's, so a split or transferred stay is charged by the room the guest is in at that time. When the booking was created with an early arrival time, that fee is already in the room total, so early check-in adds only the difference to the folio; the audit line shows the full fee and "Added to folio: ₹X".

## Changing a stay

Every action that changes money shows a preview first. The figures in the preview come from the preview endpoint; the screen does not estimate them. The cancel dialog asks for a new preview each time the waiver, fee override or amount collected changes, and the Cancel button waits for it.

| Action | Endpoints | Rule |
|---|---|---|
| Change check-in date | `preview-change-check-in` → `change-check-in` | Before arrival only (`pending`, `confirmed`). Not for hourly or split stays. Keep the number of nights, or keep check-out. The booked average nightly rate prices any new night count. |
| Extend nights | `preview-extend` → `extend` | Day stays only; hourly stays use Extend hours. Added nights are priced from the rate plan with seasons, extra beds, meals and GST (no GST added when room rates include GST). A clash with another stay returns 409 with the conflicting booking. |
| Extend hours | `preview-extend-hours` → `extend-hours` | Hourly stays that are not cancelled or checked out. Refused when the room is on hold or under maintenance during the added time. |
| Split stay | `split-stay` | Starts a new segment in another room from the current checkout, priced with seasons and GST like extend. The new room is checked for availability under a lock. |
| Room transfer | `preview-room-transfer` → `room-transfer`, history `room-transfers` | Same category or upgrade. Rate: `keep_existing` or `apply_new_category`. With a new rate, the old room keeps its share for the nights already used and the new room is priced for the remaining nights. A transfer of an in-house guest makes the old room dirty. |
| Early checkout | `preview-early-checkout` → `early-checkout` | Prices the shorter stay at the booked rate: the stored total is scaled by the shorter dates' share of the plan price, so a negotiated rate is kept. |
| Cancel | `preview-cancellation` → `cancel` | `pending` or `confirmed` only. The fee follows the cancellation settings and is settled against the deposit net of refunds already made. A fee larger than that needs the balance waived. A refund needs a method. The room is freed and holds on those dates end. |

All endpoints are `POST /bookings/{id}/…`. Previews do not write anything. Available rooms for transfer and split come from `GET /bookings/available-rooms`. It reads the times in hotel time (a UTC value is converted) and applies the same overlap rule as create, including the departure-morning rule for hourly stays.

Extend (nights and hours), early checkout, change check-in date and room transfer save in one transaction with the booking row locked; extend and transfer check the room again under a room lock. Cancel, check-out, edits, early check-in and late checkout lock the booking row the same way. If another desk changed the booking's status, room, dates, early/late times or money after the screen loaded it, the action is refused with "This reservation was just changed by another action. Reload it and try again."

## Folio and payments

- Folio: `GET /bookings/{id}/folio-postings`, `GET /bookings/{id}/inspection-charges`, POS order lines `GET /bookings/{id}/folio-orders/{order}`.
- Charges reach the folio from early check-in, late checkout, POS room charge, laundry and checkout inspection. While a checked-in booking is open, the panel updates live when a charge is posted.
- Payments: `GET|POST /bookings/{id}/payments`. Methods: `cash`, `card`, `upi`, `bank_transfer`. Split payment allowed; each part uses a different method.
- Void a payment: `POST /bookings/{id}/payments/{payment}/void`. The panel asks for confirmation first. Not after checkout or cancellation. A second void of the same payment is refused.
- On the Room Chart, Add deposit, Collect at checkout and Extend hours send one request per click; a second click while one is running is ignored. Amounts and `bill_total` go to the API rounded to paise. The API sets `payment_status` against its own bill, not the `bill_total` sent by the screen. Check-out sends the refund from the checkout preview (`refund_amount_after`).
- Every payment, refund and void locks the booking row first, so two desks posting at once cannot exceed the refund limit or post onto a booking that was just cancelled or checked out.
- After check-out, payments are refused but refunds are allowed. A refund that only returns an overpayment (cash the checkout journal did not book) posts no journal. Any part beyond that posts a `booking_refund` journal in the same save: room revenue and output GST are reversed and the tender account it was paid from is credited. A group booking looks at the whole group's payments.
- Reservation voucher: `GET /bookings/{id}/voucher`. Bill: `GET /bookings/{id}/billing`. Both are PDFs.

## Checkout inspection

`POST /bookings/{id}/request-inspection`, on the checkout date only. The room becomes Pending Inspection and housekeeping sees it on the Checkout Inspection board. The blocks, room status and an Activity line ("Checkout inspection requested for room … by …") save together with the booking row locked. The booking stays `checked_in`. Housekeeping can post minibar and damage charges to the folio.

## Check-out

`PUT /bookings/{id}` with `status: checked_out`, `checkout_scope` (`room` or `group`), and an optional `refund_amount` with `refund_method`.

1. The settle step reads `POST /bookings/{id}/preview-checkout`: bill, received, balance due, refund due, inspection status.
2. Refused until the checkout inspection is done for every departing room.
3. Refused until the bill is fully paid. A group pays as one pool (`group`) or room by room (`room`). The refund cannot be more than the amount received over the bill.
4. The bill PDF opens, then check-out is saved.
5. A checkout before the booked date moves check-out to today.
6. Room revenue and tax are posted to the accounts in the same save. On a group checkout each departing room is paid from the group's pooled payments (less what earlier group checkouts already used), so a room whose payment sits on another group booking does not show an open folio balance. A group checkout locks every departing room's booking first; if one of them changed meanwhile, the checkout is refused with "This reservation was just changed by another action."
7. Each room becomes dirty with a one-day `dirty` block (`Auto: checkout`) and appears on the Dirty Rooms board.

## Release for cleaning

From the panel, reception releases an occupied or dirty room to housekeeping with a time window, priority and service type (`POST /housekeeping/cleaning-releases`, reschedule `POST /housekeeping/cleaning-releases/{id}/reschedule`). Details are in the housekeeping workflow.

## Hotel APIs

After a booking create, edit, change of check-in date, extend, early checkout, split, cancel or room transfer, and after any room block save, Passion updates Doorloom and AioSell. Each one does nothing while it is off. A failed push does not undo the Passion save. See `DOORLOOM.md` and `AIOSELL.md`.

## Not included

No overbooking, no drag-and-drop moves on the grid, no multi-property view, and no booking for a past date. `DELETE /bookings/{id}` is not on the chart; it removes only a `pending` or `confirmed` booking with no payments (others get 422).
