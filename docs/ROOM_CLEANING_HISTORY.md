# Room cleaning history

The Room Chart drawer tab **Room Cleaning History** lists finished cleaning for one room. The list is `GET /api/housekeeping/rooms/{room}/cleaning-history`. Opening a card loads `GET /api/housekeeping/rooms/{room}/cleaning-history/detail` with `source` and `id`.

Anyone who can open a housekeeping menu can read this history.

Service duration is the clock time from `started_at` to `completed_at` for that release. Under one minute it is shown in seconds (`13 sec`). One minute and longer is shown in whole minutes. The average on the tab uses those same clock times, not a rounded minute count.

## Not included

A duration is not taken from the cleaning window or from the time the supervisor later approves the room.
