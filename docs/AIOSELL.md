# AioSell — Passion Hotel PMS

AioSell is the channel manager. Passion stays the hotel system. Passion pushes free-room counts and nightly prices. AioSell distributes them to the connected OTAs and sends those bookings back. Doorloom can be on at the same time. The free-room count includes stays already in Passion.

Base URL: `https://live.aiosell.com/api/v2/cm` ([channel manager overview](https://apidocs.aiosell.com/api-overview)). Outbound calls use Basic Auth. The partner id is in the path. The hotel code is in the body. Calls run only when the connection is on and the username, password, partner id, and hotel code are saved. A `429` is retried once. A failed push is stored on the settings row and does not undo the Passion save.

## Settings

Settings → Integrations, for someone who can `manage-settings` (the Admin role is included).

AioSell is a row on the hotel API list, with its own logo, Settings button, and enable toggle. `PUT /aiosell` saves `enabled`. Credentials, mapping, webhooks, restrictions, and the multiplier stay on the settings panel either way.

- Username and password are encrypted. The page shows whether each one is saved and never shows them again.
- **Load mapping** calls `GET /property_details/{hotelCode}?partnerId={pms}` and stores `aiosell_room_maps` and `aiosell_rate_plan_maps`, plus `connected_channels`.
- Reservation webhook: `POST /api/aiosell/webhook`. Message webhook: `POST /api/aiosell/messages`. Both sit outside Sanctum and check Basic Auth with `hash_equals`.
- **Push now** sends inventory and rates for 366 nights and clears the dirty flag.
- **Catch up** calls Fetch Reservations and runs the same writer as the webhook.

Meal map: `room_only` EP, `breakfast` CP, `half_board` MAP, `full_board` AP. Occupancy letters are `s`, `d`, `t`, `q`. Hourly plans are not pushed. A mapping row can store its own price. Otherwise single and double codes that share one Passion plan share that plan’s seasonal price (`SeasonalRoomPricing` on `base_price`).

## Inventory and rates

| AioSell | When Passion calls it |
|---|---|
| `POST /update/{pms}` | After a stay or room-block save, and on Push now. Count is physical rooms of that type still sellable that night. |
| `POST /update-rates/{pms}` | After a room-type save, and on Push now. |
| Inventory restrictions, same `/update/{pms}` with `rooms[].restrictions` and `toChannels` | Restrictions form with no rate plan |
| Rate restrictions, same `/update-rates/{pms}` | Restrictions form with a rate plan |
| `POST /channel_multiplier/{pms}` | Multiplier form. `channels` cannot be empty. `1` leaves rates unchanged. |
| `POST /data/{pms}` `type=reservation` | Catch up |

Stay and block saves that already pushed Doorloom also push AioSell through `HotelApiSync`: booking create, update, extend, early checkout, split, cancel, room transfer, room create/update/delete, room-type update, and room-status block store, update, and destroy. Each integration no-ops when it is off.

## Reservations

`book`, `modify`, and `cancel` arrive on the reservation webhook. Success bodies are `Reservation Updated Successfully`, `Reservation Modified Successfully`, and `Reservation Cancelled Successfully`. Anything else is HTTP 409 with `success: false`, and nothing from that call is saved.

- Room codes are room types. Passion locks rooms and assigns the lowest-numbered sellable room of that type. A multi-room payload is one Passion booking per room under one `booking_group_id`.
- The same `channel` + `bookingId` on a second `book` returns success and does not create another stay.
- `modify` overwrites guest, dates, rooms, and total. A checked-in stay is not moved when no free room fits. The webhook fails and the current stay stays.
- `cancel` cancels a pending or confirmed stay. It does not charge a cancellation fee. An in-house stay is left as it is and the webhook fails.
- `booking_source` is the OTA channel. `source_reference` is `bookingId`. A missing guest name is stored as Guest. `specialRequests` is appended to `notes`.
- `pah: false` records `amount.amountAfterTax` through the payment ledger as `bank_transfer`, with notes naming the channel and booking id. `pah: true` stores the total for the desk to collect. Commission, TCS, and TDS stay on `aiosell_booking_links` and are not folio lines.
- Card fields are removed before the payload is handled. They are not stored.

Booking.com and Goibibo / MakeMyTrip (`booking.com`, `gommt`) can be marked no-show from the booking detail. That calls `POST /marknoshow/{pms}` and appends an audit line on `notes`. Other channels do not show the action. Reading the thread needs `reservation-view` (or `reservation`, `reservation-edit`, `manage-settings`, or Admin). Reply and no-show need `reservation-edit` (or `reservation`, `manage-settings`, or Admin).

`POST /api/aiosell/messages` stores `aiosell_messages` by `message_id`. A repeat `message_id` is ignored. The booking detail shows the thread and sends `POST /message-reply/{pms}`. Booking.com also sends `booking_id`. Guest name, phone, and email from that payload are not written to logs.

## Not included

AioSell’s PMS public API (leads, stay and invoice reads, check-in, and AioSell-as-PMS webhooks), the OTA API, dynamic pricing, and the sandbox tester. There is no queue and no scheduler.
