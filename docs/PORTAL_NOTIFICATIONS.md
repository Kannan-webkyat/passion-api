# Portal notifications

The header bell is on every portal screen except the POS terminal and the kitchen display. It loads `GET /api/notifications`. Marking one row read is `POST /api/notifications/{id}/read`. Marking the visible list read is `POST /api/notifications/read-all`. A row the signed-in user cannot see returns 404.

Live alerts use the private channel `portal.notifications` and the event `.portal.notification.created`. The toast and chime play only when that user is allowed to see the row. Without Reverb the bell still refreshes about every 30 seconds.

The person who triggers a housekeeping or front-office alert is marked read, so their own badge does not rise. An assignment alert is stored only for the assigned staff member.

## Who sees which alert

| Alert | Who sees it |
|---|---|
| Room released for cleaning | Front office: `view-rooms`, `reservation`, or `reservation-view` |
| Room cleaned, inspection complete, room ready, re-service approved | Front office, same permissions |
| New dirty room, checkout inspection requested, re-service requested | Users who can allocate that board, until a staff member is assigned |
| Task assigned (dirty room, checkout inspection, daily cleaning, re-service) | Only the assigned staff member |
| Laundry requested | `housekeeping-laundry` |
| Laundry ready or posted to the folio | Front office |

Allocation access is `housekeeping-assignable` for dirty rooms and daily cleaning, and `housekeeping-checkout-inspection-assign` for checkout inspection. Other attendants on the same board do not see a task that was not assigned to them.

Assignment titles are “Room {number} assigned to you”. They do not name the person who allocated the work.

## Not included

The assign lists on the boards still show every housekeeping attendant who can be given the task. The bell does not add a second notice for the allocator.
