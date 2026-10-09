# Room cleaning history

The Room Chart drawer tab **Room Cleaning History** lists finished cleaning for one room. The list is `GET /api/housekeeping/rooms/{room}/cleaning-history`. Opening a card loads `GET /api/housekeeping/rooms/{room}/cleaning-history/detail` with `source` and `id`.

Anyone who can open a housekeeping menu can read this history.

The detail's Service activity is a step timeline: Assigned, Started, Completed (with the service duration) and Supervisor approved, each with the person and time. Steps not reached yet show as Pending. A turnover record shows Started, Finished and its inspection status, then Supervisor approved with the approver and time from the housekeeping job (`approved_by`, `approved_at`), or "Waiting for supervisor approval" while the job is `inspected`. Turnover status labels: "Awaiting supervisor approval" (job `inspected`), "Supervisor approved (room available)" (job `completed` with an approver). The approver and time (`approved_by_user`, `approved_at`) come from the release's `inspection_completed` audit row, or `room_ready` when there is none.

Service duration is the clock time from `started_at` to `completed_at` for that release. Under one minute it is shown in seconds (`13 sec`). One minute and longer is shown in whole minutes. The average on the tab uses those same clock times, not a rounded minute count.

## Not included

A duration is not taken from the cleaning window or from the time the supervisor later approves the room.
