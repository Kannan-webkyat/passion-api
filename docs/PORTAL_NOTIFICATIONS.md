# Portal notifications

The header bell is on every portal screen except the POS terminal and the kitchen display. It loads `GET /api/notifications`. Marking one row read is `POST /api/notifications/{id}/read`. Marking the visible list read is `POST /api/notifications/read-all`. A row the signed-in user cannot see returns 404.

Clearing is per user, and only read notifications can be cleared. A read row shows an × that calls `POST /api/notifications/{id}/clear`. Unread rows have no ×, and the endpoint returns 422 "Only read notifications can be cleared." for them. "Clear read" opens a confirmation strip inside the panel ("Clear N read notifications from your list?"). Confirming calls `POST /api/notifications/clear-read`, which clears every read notification in that user's feed, leaves unread ones, and returns `cleared` and `unread_count`. A cleared notification leaves only that user's bell. Other users still see it with their own read state. The mark is `cleared_at` on `portal_notification_reads`. Without that column (migration `2026_10_08_170000`), both clear endpoints return 422.

Live alerts use the private channel `portal.notifications` and the event `.portal.notification.created`. The API broadcasts it through Reverb after the database commit. The payload has `id`, `audience`, `kind`, `title`, `message`, `recipient_user_id`, `actor_user_id`, `href`, `booking_id`, `room_id` and `service_date`. When the alert reaches a user who is allowed to see the row, the bell refreshes, the chime plays and a toast shows for 8 seconds. When the alert has a link, the toast has an "Open" button (or "Open stay" for a booking). The button marks the notification read and opens the board or stay. Without Reverb the bell still refreshes about every 30 seconds, but no toast shows.

The person who triggers a housekeeping or front-office alert is marked read, so their own badge does not rise. They get no toast or chime for it. An assignment alert is stored only for the assigned staff member.

## Who sees which alert

| Alert | Who sees it |
|---|---|
| Room released for cleaning | Front office: `view-rooms`, `reservation`, or `reservation-view` |
| Room cleaned, inspection complete, room ready, re-service approved | Front office, same permissions |
| New dirty room, checkout inspection requested, re-service requested | Users who can allocate that board, until a staff member is assigned |
| Task assigned (dirty room, checkout inspection, daily cleaning, re-service), including staff chosen when the Room Chart releases a room for cleaning | Only the assigned staff member |
| Laundry requested | `housekeeping-laundry` |
| Laundry ready or posted to the folio | Front office |

The turnover room-ready alert is sent when a supervisor approves a cleaned room on the Dirty Rooms board, not when the housekeeper finishes. Title "Room {number} is ready", message "{approver} approved the cleaning. Room {number} is available for check-in." Its toast has an "Open" button to the Room Chart (`/reception/roomChart`).

Allocation access is `housekeeping-assignable` for dirty rooms and daily cleaning, and `housekeeping-checkout-inspection-assign` for checkout inspection. Other attendants on the same board do not see a task that was not assigned to them.

Assignment titles are “Room {number} assigned to you”. They do not name the person who allocated the work.

## Not included

The assign lists on the boards still show every housekeeping attendant who can be given the task. The bell does not add a second notice for the allocator.

A cleared notification cannot be restored from the bell. Clearing never deletes the shared row. The bell keeps its existing rule of storing only the latest 200 notifications.
