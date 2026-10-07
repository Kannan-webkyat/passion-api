# Doorloom — Passion Hotel PMS

Doorloom is the online listing and nightly calendar. Passion stays the hotel system for walk-in rooms, walk-in prices, meals, seasons, and hourly packages.

Base URL: `https://doorloom.com/api/integrations/v1` ([developer overview](https://doorloom.com/developers)). Doorloom staff issue the API key and the webhook secret. Until those are saved and the connection is turned on, the screens are there and every outbound call is skipped.

## Who owns what

| Passion | Doorloom |
|---|---|
| Walk-in room price, seasons, meal billing, hourly packages | Nightly online price, stop-sell, free-room count |
| Guest stay created at the desk | The same overnight stay, pushed after Passion saves it |
| Room type name, bedrooms, washrooms, occupancy | Listing facts after the first send, plus photos, amenities, cancellation rules, and booking rules edited in the Doorloom app |

A `rates.changed` webhook writes the online calendar. It does not change `rate_plans` or seasons. Opening weekday and weekend prices are sent once, when the room type is first created on Doorloom. Later Doorloom nightly edits stay on the online calendar. An ordinary room-type save does not send a new base price.

## Mapping

- One hotel. There is no property selector.
- Each room type is one Doorloom listing. External id: `rt-{room_type_id}`. Stored as `room_types.doorloom_property_id`.
- Each room is one unit. External id: `room-{room_id}`. Stored as `rooms.doorloom_inventory_id`.
- A stay that uses two room types becomes one Doorloom booking per room type. Doorloom allows several rooms only on the same listing.
- Hourly packages are not sent.
- External booking id: `b-{booking_id}-rt-{room_type_id}`.

Meal codes sent with a stay: only stay `EP`, breakfast `CP`, breakfast + 1 meal `MP`, all meals `AP`.

## Settings

Settings → Integrations, for someone who can `manage-settings` (the Admin role is included).

The section lists hotel APIs in one panel. Doorloom is the hotel API on that list. Each row shows a small Doorloom logo and the API name on the left. On the right are a Settings button, with a settings icon, and an enable toggle. The logo appears only on that list row. The open settings panel groups Credentials, Listing address, Webhook, and Room mapping. Save sits with Send room types, Catch up, and Full sync.

Clicking Settings opens the Doorloom settings under the list. Clicking Settings again closes them.

The toggle saves `enabled` with `PUT /doorloom`. It is on while the connection is on. Switching it off turns the connection off. The API key, webhook secret, address, webhook URL, room mapping, and the sync actions stay on the settings panel either way. Outbound calls still run only when the connection is on and an API key is saved.

- Paste the API key and webhook secret. The page shows whether each one is saved. It never shows the secret again.
- Saving a new API key calls [GET /me](https://doorloom.com/developers) and stores the connection name.
- Address line, city, state, and PIN are required before **Send room types**. The company profile only has one address string, so these fields live on the Doorloom settings.
- Copy the webhook URL. Point Doorloom at `POST /api/doorloom/webhook`.
- Room type and room rows show the Doorloom ids. Send room types fills them. You can also paste an id Doorloom already issued.
- **Send room types** creates listings that do not have a Doorloom id yet. A new room type is not pushed on the normal save, so a half-filled type is not sent by accident. After a type has an id, later room-type and room saves update the listing (name, description, space, occupancy, units) and do not send a new base price.
- **Catch up** reads [GET /events](https://doorloom.com/developers) from the last sequence and runs each event through the same adapter as the webhook.
- **Full sync** calls [POST /sync](https://doorloom.com/developers). Doorloom allows one full sync every 24 hours. Passion does not schedule it.

## Online calendar and the room chart

The online calendar is at Reception → Online calendar, next to Rate Calendar. It is read-only: nightly price, free rooms, and stop-sell. It stays empty until Doorloom sends nights. `GET /doorloom/calendar` uses the same view permissions as Rate Calendar.

On the room chart, a new overnight stay for a linked room type is refused when any night has stop-sell or zero free units. The walk-in price on the receipt does not change. The chart shows a short note when Doorloom’s free count is lower than the rooms on the chart.

## Webhook

`POST /api/doorloom/webhook` is public. `X-Doorloom-Signature` is `t={unix},v1={hex}`. The hex is HMAC-SHA256 of `{timestamp}.{raw body}` using the webhook secret. A timestamp more than 300 seconds off, or a bad signature, returns 401. `ping` returns 200.

An event is applied only when its sequence is higher than the cursor for that Doorloom property and event type. A window replaces every night inside `from`–`to` for the fields that event owns.

- `rates.changed`, `availability.changed`, and `restrictions.changed` write `doorloom_nights`.
- `inventory.changed` stores unit ids and marks a unit inactive when Doorloom says it is deleted.
- `property.changed` stores the Doorloom property id when `external_id` is `rt-{id}`. It does not rename the Passion room type or change the walk-in price.
- `booking.changed` with `origin: "partner"` is the echo of our own write. Passion stores the revision and does nothing else. `origin: "doorloom"` updates that Passion booking (dates, guest name, phone, notes, total, or cancel). Passion does not create a guest reservation from a free-room count.

## Outbound calls

`App\Support\DoorloomClient` runs inline after the Passion save, and from the settings buttons. There is no queue and no scheduler.

| Call | When | Docs |
|---|---|---|
| Create property, including bulk (max 25) | Send room types | [Create property](https://doorloom.com/developers) |
| Update property | Room type or room save, only if that type already has a Doorloom id | [Update property](https://doorloom.com/developers) |
| Create booking | New overnight stay. `pricing.total` is Passion’s total. The room is pinned with `external_unit_id`. `source.channel` is `walk_in`. | [Create booking](https://doorloom.com/developers) |
| Update booking | Date, guest, price, or unit change on the same room type. `If-Match` is the stored revision. On 412, Passion re-reads and retries once. | [Update booking](https://doorloom.com/developers) |
| Cancel | Passion stay cancelled. A transfer to another room type cancels the old listing booking and creates one on the new listing. | [Cancel booking](https://doorloom.com/developers) |
| Replay events | Catch up | [Events](https://doorloom.com/developers) |
| Full sync | Full sync button only | [Sync](https://doorloom.com/developers) |

Create calls send an `Idempotency-Key`. The same key is reused only for the same body.

Stay push runs after a successful save for create, guest or price edit, extend, early checkout, cancel, split stay, and room transfer. Hourly stays and room types with no Doorloom id are skipped.

## What a 409 means at the desk

`409 UNAVAILABLE` does not undo the walk-in booking. Reception sees Doorloom’s message. The dates in that response are written onto the online calendar the same way as `availability.changed`. The walk-in stay remains. Doorloom did not take the room.

## Not in this connection

Doorloom’s own OTA channel connections, listing photos, and a weekday extra-guest card beyond the opening prices already stored on the room type.
