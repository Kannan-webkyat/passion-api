# Dirty Rooms board

The Dirty Rooms board is the housekeeping turnover pipeline after a guest leaves. It lists every room with an active `room_status_blocks` row in status `dirty`, `cleaning` or `inspected`, so staff can assign a housekeeper and start cleaning, and a supervisor can approve the cleaned room.

Screen: `/reception/housekeeping/dirty-rooms` (`DirtyRoomsBoard`). Menu permission: `housekeeping-dirty-rooms`.

## Board data

`GET /housekeeping/dirty-rooms-board`, with optional `floor` and `room_type_id` filters. Before it reads, the endpoint syncs split-stay room moves and carries forward open turnover blocks to today.

It returns:

- `blocks`: each block with its room and room type, the assigned staff, `waiting_minutes`, `checkout_at`, `next_arrival_at`, `next_arrival_guest` and `priority`.
- `stats`: counts for `dirty`, `cleaning`, `ready` and `priority`.
- `staff`: the people who can be assigned.

An `inspected` block for a room that still has an in-house booking is left out. That room stays on the Checkout Inspection board until the guest leaves.

Priority:

- `urgent`: the next guest is VIP or has early check-in; or the next arrival is today and is within 4 hours; or the next arrival is today and the room has waited 60 minutes or more.
- `soon`: any other arrival today, an arrival tomorrow, or a dirty room that has waited 60 minutes or more.
- `normal`: everything else.

## Screen

- Overview tiles for Dirty, In cleaning, Awaiting approval and Priority. Each tile filters the board.
- Filters for floor, room type, priority, status and search.
- A card view grouped Dirty / In cleaning / Awaiting approval, and a kanban view.
- Each card shows the room, type, floor, checkout time, next arrival and staff.
- An Awaiting approval card shows Send back and Approve to users with `housekeeping-supervisor-inspection`, and "Waiting for supervisor approval" to everyone else.
- The top-right label reads "Waiting", "Cleaning" or "Awaiting approval", followed by the time since the block last changed (its `updated_at`), for example "Waiting · Just now", "Waiting · 25 min", "Waiting · 1 hr 5 min" or "Waiting · 2 days 3 hr". The time is counted in the browser and updates while the board is open. The colour is green under 30 minutes, amber up to 60 minutes and red after that.
- Opening a card opens the shared housekeeping workboard drawer in turnover-workflow mode.

The board reloads on the `portal:housekeeping-state` window event and after every action.

## Actions

| Action | Endpoint | Rule |
|---|---|---|
| Assign or unassign staff | `POST /housekeeping/blocks/{id}/assign-staff` with `assigned_to` | Needs `housekeeping-assignable` plus Dirty Rooms or Cleaning Tasks access. Once cleaning has started, changing or removing the staff needs `confirm_reassign: true`; without it the API returns 422 with `requires_confirmation: true` and "{name} has already started cleaning this room. Confirm to change the staff." The screen asks "Change staff during cleaning?" with Keep current staff / Change staff before it sends the change. |
| Start cleaning | `POST /housekeeping/blocks/{id}/start-cleaning` | Only a `dirty` block, only by the assigned staff member. The block becomes `cleaning`. |
| Approve | `POST /housekeeping/blocks/{id}/mark-inspected`, optional `remarks` | Only for an active `inspected` block, and refused while the room still has a checked-in guest. For a turnover block (one with a housekeeping job) it needs `housekeeping-supervisor-inspection`; a checkout-inspection handoff block with no job needs `housekeeping-clean-rooms`. The block is closed, the room becomes `available`, the job becomes `completed` with `approved_by` and `approved_at`, remarks are added as "Supervisor: …", and front desk gets the room-ready notification. Message: "Cleaning approved. Room is available." |
| Send back for re-cleaning | `POST /housekeeping/blocks/{id}/send-back`, optional `remarks` | Needs `housekeeping-supervisor-inspection`. Only for a turnover block awaiting approval; otherwise 422 "Room is not awaiting approval." The block and room go back to `cleaning`, the job to `in_progress` (finish time cleared), remarks are added as "Sent back: …", and a cleaning release in `inspection_pending` goes back to in progress. Message: "Sent back for re-cleaning." |

The cleaning checklist and finish steps run in the workboard drawer: `POST /housekeeping/blocks/{id}/job`, `…/finish` and `…/mark-cleaned`. Finish records `finished_at` and, when no asset needs maintenance, moves the block and room to `inspected` and the job to `inspected`; the room is not available yet. Message: "Cleaning complete. Waiting for supervisor approval." Mark cleaned does the same without the checklist: "Room marked as cleaned. Waiting for supervisor approval." The drawer for an awaiting-approval room shows "Awaiting supervisor approval" with Approve → Available and Send back for re-cleaning for supervisors.

While a room waits for approval, check-in is refused (see `ROOM_CHART.md`), and an unapproved block is carried forward to today like a dirty or cleaning block.

Only the assigned staff member can start cleaning, save the checklist, finish or mark the room cleaned. With no one assigned, these return 422 "Assign a housekeeping staff member before starting cleaning." For anyone else, including supervisors and admins, they return 403 "Only the assigned staff member can clean this room." On the card and in the drawer, Start is disabled for anyone but the assignee, and an in-cleaning card shows "View" instead of "Continue". For anyone else the cleaning drawer opens view-only: the steps can be browsed, the inputs are disabled, Save draft and Complete cleaning are hidden, and the footer says "View only. Only {staff name} can work on this room." Supervisors can still change who is assigned.

## Not included

- Checkout inspection before the guest leaves (Checkout Inspection board).
- Stay-over cleaning of occupied rooms (`DAILY_ROOM_CLEANING.md`).
- Room cleaning history (`ROOM_CLEANING_HISTORY.md`).
