# Room Types — Passion Hotel PMS

Room types are the room categories: occupancy, nightly and hourly rate plans, seasons, meals, and early check-in / late checkout fees. Rooms belong to one room type.

Screen: Admin → Room Types (`/admin/roomTypes`). API: `RoomTypeController`.

## Permissions

| Action | Permission (any of) |
|---|---|
| Read the list (Room Types page, room chart, rate calendar, housekeeping) | `room-types-view`, `view-rooms`, `manage-rooms`, `reservation-view`, `reservation` |
| Create | `room-types-create` |
| Edit | `room-types-edit` |
| Archive and restore | `room-types-delete` (labelled "Archive and restore room types") |

There is no Admin bypass on these endpoints.

## Endpoints

- `GET /room-types` — active room types. `?include_inactive=1` adds types switched off with `is_active`. `?archived=1` returns only archived types, newest archive first.
- `POST /room-types`, `PUT /room-types/{id}` — create and edit. A price change on a mapped type can ask whether to update AioSell (see `AIOSELL.md`).
- `DELETE /room-types/{id}` — archives the type. Returns 204.
- `POST /room-types/{id}/restore` — brings an archived type back. Returns the room type.

## Archive

**Archive** in the card's Actions menu replaces Delete. After a confirmation, the type gets a `deleted_at` time. Nothing is removed.

- Archive is refused with 409 while any room is assigned to the type: "Cannot archive room type as it has existing rooms assigned to it. Move or delete those rooms first."
- An archived type disappears from every room-type list and lookup: the Room Types page, room chart, rate calendar, new bookings, and the Doorloom and AioSell settings and syncs.
- Rate plans and seasons stay in the database, so past bookings keep their rate plan.
- `is_active` is separate. An inactive type is still listed with `include_inactive=1` and can still be edited. An archived type is not listed until restored.

The **Archived (n)** button in the page header lists archived types with the archive date and weekday and weekend prices. **Restore** (needs `room-types-delete`) clears `deleted_at`, and the type returns to the main list with its rate plans and seasons. A restore of a type that is not archived returns 422.

## Not included

No permanent delete from the screen or API. Archiving does not cancel or close a Doorloom listing or AioSell mapping. Those stay as they were and are no longer synced while the type is archived.
