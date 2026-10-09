# Checkout inspection board

Checkout inspection is the housekeeping check of a room before or at the guest's checkout. Reception requests it from the Room Chart, a supervisor assigns a housekeeper, and the assigned housekeeper inspects the room and either clears it or posts minibar and asset charges to the guest's folio.

Screen: `/reception/housekeeping/checkout-inspection` (`CheckoutInspectionBoard`). Menu permission: `housekeeping-checkout-inspection`.

## Request

Reception requests the inspection from the booking panel on the Room Chart: `POST /bookings/{id}/request-inspection` (see `ROOM_CHART.md`). The booking must be `checked_in`. The API creates one active `pending_inspection` block per open room segment of the booking, sets those rooms to `pending_inspection`, and sends a "checkout inspection requested" notification to users who can allocate the board.

## Board data

`GET /housekeeping?checkout_scope=pending|inspected|history`, with optional `floor` and `room_type_id` filters. Needs `housekeeping-checkout-inspection`.

- `pending`: active `pending_inspection` blocks.
- `inspected`: active `inspected` blocks with an inspection snapshot (inspection done, guest not yet gone).
- `history`: closed `inspected` blocks with a snapshot, newest 200.

Each block carries the assigned staff (`assigned_to`, `assigned_staff_name`) and the inspector name. The response also has `staff`, the people who can be assigned.

## Screen

- Overview tiles for Pending, Done today, Damage reports and Charges raised.
- Filters for floor, room type, priority and search.
- Pending cards show the room, guest, checkout time, staff and priority (VIP, early arrival, standard).
- A Damages & charges summary and a Completed inspections list.
- Opening a card opens the shared housekeeping workboard drawer with the inspection workflow: Room condition, Minibar, Assets, Damages (when issues are found) and Review.

The board reloads on the `portal:housekeeping-state` window event and after every action.

## Assignment and who can inspect

| Action | Endpoint | Rule |
|---|---|---|
| Assign or unassign staff | `POST /housekeeping/blocks/{id}/assign-staff` with `assigned_to` | Needs `housekeeping-checkout-inspection-assign`. The assignee gets a "Room {number} assigned to you" notification. |
| Clear with no charges | `POST /housekeeping/blocks/{id}/checkout-inspection/clear` | Only the assigned staff member. |
| Preview charges | `POST /housekeeping/blocks/{id}/checkout-inspection/validate` | Only the assigned staff member. |
| Apply charges and complete | `POST /housekeeping/blocks/{id}/checkout-inspection/apply` | Only the assigned staff member. |

All three inspection actions need `housekeeping-checkout-inspection`. With no one assigned they return 422 "Assign a housekeeping staff member before starting the checkout inspection." For anyone else, including users with `housekeeping-checkout-inspection-assign`, they return 403 "Only the assigned staff member can complete this checkout inspection."

On the board:

- The assignee sees "Start inspection".
- A user with `housekeeping-checkout-inspection-assign` sees "View" on a card assigned to someone else. The drawer opens view-only: the steps can be browsed, the inputs are disabled, the charge preview is not run, the submit buttons are hidden, and the footer says "View only. Only {staff name} can complete this inspection." The checklist ticks are kept in the inspector's browser until they submit, so a viewer sees the steps and room items, not the inspector's unsaved ticks.
- Anyone else is told "Assigned to another staff member" and the drawer does not open. An unassigned card shows "Assign staff first".

## Completing

- Clear: the `pending_inspection` block closes and a new active `inspected` block with a cleared snapshot (inspector, time, booking) takes its place. Message: "Inspection completed. Room marked inspected on the chart."
- Apply: minibar consumption is deducted from the room's inventory location (`inventory_transactions`, reason "Checkout inspection consumption") and charged to the guest's folio together with missing or damaged assets. The block is replaced by an active `inspected` block with the snapshot. Message: "Inspection completed. Charges applied; room marked inspected on the chart."
- Minibar quantity above room stock returns 422 "Minibar quantity exceeds on-hand stock for item #… (max …)."

The `inspected` block stays on the Room Chart until checkout. At checkout the room goes to the Dirty Rooms board (`DIRTY_ROOMS.md`).

## Not included

- Turnover cleaning after the guest leaves (`DIRTY_ROOMS.md`).
- Stay-over cleaning (`DAILY_ROOM_CLEANING.md`).
- Saving an inspection draft on the server.
