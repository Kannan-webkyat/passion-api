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

## The grid

The screen loads `GET /bookings/chart?start=&end=`, `GET /bookings/summary?date=`, and `GET /room-types` together. It loads them again after every change and whenever housekeeping state changes in another tab or on another device.

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
- The API refuses a past check-in, refuses `checked_in` unless arrival is today, and checks each room again with a row lock before saving. A single-room day stay keeps the total the screen sent. A multi-room day stay is priced again on the server from seasons.
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

## Check-in

`PUT /bookings/{id}` with `status: checked_in`.

1. Only on the arrival date (an hourly stay uses its start time).
2. Refused while the room has an active `dirty` or `cleaning` block today. The screen shows the dirty-room dialog.
3. The stay and every room in it become occupied. Other screens and tabs refresh.

**Early check-in** (`POST /bookings/{id}/early-checkin`) and **late checkout** (`POST /bookings/{id}/late-checkout`) record the time and add the room-type fee (per hour, per minute or flat, after the free buffer) to the folio. They do not change status.

## Changing a stay

Every action that changes money shows a preview first.

| Action | Endpoints | Rule |
|---|---|---|
| Change check-in date | `preview-change-check-in` → `change-check-in` | Before arrival only (`pending`, `confirmed`). Not for hourly or split stays. Keep the number of nights, or keep check-out. The booked average nightly rate prices any new night count. |
| Extend nights | `preview-extend` → `extend` | A clash with another stay returns 409 with the conflicting booking. |
| Extend hours | `preview-extend-hours` → `extend-hours` | Hourly stays. |
| Split stay | `split-stay` | Starts a new segment in another room from the current checkout. |
| Room transfer | `preview-room-transfer` → `room-transfer`, history `room-transfers` | Same category or upgrade. Rate: `keep_existing` or `apply_new_category`. A transfer of an in-house guest makes the old room dirty. |
| Early checkout | `preview-early-checkout` → `early-checkout` | Prices the stay again for the shorter dates. |
| Cancel | `preview-cancellation` → `cancel` | `pending` or `confirmed` only. The fee follows the cancellation settings. A fee larger than the deposit needs the balance waived. A refund needs a method. The room is freed and holds on those dates end. |

All endpoints are `POST /bookings/{id}/…`. Available rooms for transfer and split come from `GET /bookings/available-rooms`.

## Folio and payments

- Folio: `GET /bookings/{id}/folio-postings`, `GET /bookings/{id}/inspection-charges`, POS order lines `GET /bookings/{id}/folio-orders/{order}`.
- Charges reach the folio from early check-in, late checkout, POS room charge, laundry and checkout inspection. While a checked-in booking is open, the panel updates live when a charge is posted.
- Payments: `GET|POST /bookings/{id}/payments`. Methods: `cash`, `card`, `upi`, `bank_transfer`. Split payment allowed; each part uses a different method.
- Void a payment: `POST /bookings/{id}/payments/{payment}/void`. Not after checkout or cancellation.
- Reservation voucher: `GET /bookings/{id}/voucher`. Bill: `GET /bookings/{id}/billing`. Both are PDFs.

## Checkout inspection

`POST /bookings/{id}/request-inspection`, on the checkout date only. The room becomes Pending Inspection and housekeeping sees it on the Checkout Inspection board. The booking stays `checked_in`. Housekeeping can post minibar and damage charges to the folio.

## Check-out

`PUT /bookings/{id}` with `status: checked_out`, `checkout_scope` (`room` or `group`), and an optional `refund_amount` with `refund_method`.

1. The settle step reads `POST /bookings/{id}/preview-checkout`: bill, received, balance due, refund due, inspection status.
2. Refused until the checkout inspection is done for every departing room.
3. Refused until the bill is fully paid. A group pays as one pool (`group`) or room by room (`room`).
4. The bill PDF opens, then check-out is saved.
5. A checkout before the booked date moves check-out to today.
6. Room revenue and tax are posted to the accounts in the same save.
7. Each room becomes dirty with a one-day `dirty` block (`Auto: checkout`) and appears on the Dirty Rooms board.

## Release for cleaning

From the panel, reception releases an occupied or dirty room to housekeeping with a time window, priority and service type (`POST /housekeeping/cleaning-releases`, reschedule `POST /housekeeping/cleaning-releases/{id}/reschedule`). Details are in the housekeeping workflow.

## Hotel APIs

After a booking create, edit, extend, early checkout, split, cancel or room transfer, and after any room block save, Passion updates Doorloom and AioSell. Each one does nothing while it is off. A failed push does not undo the Passion save. See `DOORLOOM.md` and `AIOSELL.md`.

## Not included

No overbooking, no drag-and-drop moves on the grid, no multi-property view, and no booking for a past date. Split stay does not check availability or run in a transaction. `DELETE /bookings/{id}` removes a booking outright and is not on the chart.
