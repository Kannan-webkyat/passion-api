# Daily room cleaning

Housekeeping → Daily Room Cleaning, at `/reception/housekeeping/daily-room-cleaning`. The board lists occupied rooms that the front office has released for cleaning. Permission: `housekeeping-daily-room-cleaning`. Assigning staff needs `housekeeping-assignable`.

The status moves `pending_cleaning → in_progress → cleaned` through `POST /api/housekeeping/daily-cleaning/status`. Starting a cleaning (`in_progress`) needs an active release, an open release window, and a housekeeper assigned in the schedule. The assignee is the `assigned_to` sent with the request; otherwise the one saved on the day's cleaning row, then the one on the release. With no assignee the API returns 422 "Assign a housekeeping staff member in the daily cleaning schedule before starting this cleaning." The board blocks the start in the same case with an "Assign staff first" warning, both on the row's quick action and in the room sheet.

Re-service tasks (service type `other`) also need an assignee, and only that staff member can start them.

A supervisor sending a cleaned room back for re-cleaning during inspection does not need a new assignment. A cleaning that is already `in_progress` or `cleaned` is not checked again.

## Not included

The rule does not assign staff by itself and does not check that the assignee is on shift.
