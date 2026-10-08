# Rooms — Passion Hotel PMS

Rooms are the physical rooms: number, room type, floor, view, smoking, connected room, notes. Each room belongs to one room type.

Screen: Admin → Rooms (`/admin/rooms`, "Rooms Master"). API: `RoomController`.

## Permissions

| Action | Permission (any of) |
|---|---|
| Read the list (Rooms Master, room chart, bookings, housekeeping) | `rooms-view`, `view-rooms`, `reservation-view`, `reservation` |
| Create | `rooms-create` |
| Edit | `rooms-edit` |
| Archive and restore | `rooms-delete` (labelled "Archive and restore rooms") |

There is no Admin bypass on these endpoints.

## Endpoints

- `GET /rooms` — active rooms. `?include_inactive=1` adds rooms switched off with `is_active`. `?archived=1` returns only archived rooms, newest archive first.
- `POST /rooms`, `PUT /rooms/{id}` — create and edit. The room type must not be archived, and a connected room must not be archived.
- `DELETE /rooms/{id}` — archives the room. Returns 204.
- `POST /rooms/{id}/restore` — brings an archived room back. Returns the room.

## Archive

**Archive Room** in the row's Options menu replaces Delete. After a confirmation, the room gets a `deleted_at` time. Bookings, stay segments, folios, payments, room transfers, housekeeping jobs, cleaning releases, laundry, and POS orders for that room are kept.

- Archive is refused with 409 while the room has a current or upcoming stay (a segment that is not `cancelled`, `checked_out` or `completed` and ends after now): "Cannot archive Room #{n} while it has a current or upcoming stay. Move or cancel that stay first."
- Archiving ends every active room block on the room (`on_hold`, `maintenance`, `dirty`, `cleaning`, `pending_inspection`) and clears it as another room's connected room.
- An archived room disappears from the Rooms list, room chart, available-room lookups, new bookings, and room counts sent to Doorloom and AioSell. The room type is synced again after the archive.
- Past records still show the room number. Bookings, segments, transfers, blocks, housekeeping, laundry, POS orders and inventory locations load the room even when it is archived.
- The room number stays taken. Adding a room with an archived number returns 422: "Room #{n} is archived. Restore it from Archived rooms instead of adding it again."
- `is_active` is separate. An inactive room is still listed with `include_inactive=1` and can still be edited.

The **Archived (n)** button in the header lists archived rooms with room type, floor and archive date. **Restore** (needs `rooms-delete`) clears `deleted_at`, and the room returns to the list and the room chart. Restore is refused with 422 when the room's type is archived ("Restore the room type first"), or when the room is not archived.

A room type can be archived once all its rooms are archived (see `ROOM_TYPES.md`).

## Not included

No permanent delete from the screen or API. Blocks ended by an archive are not brought back on restore. The room's inventory location and any room stock in it stay as they were.
