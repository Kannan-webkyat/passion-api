# Laundry

Housekeeping → Laundry Management. The queues are Laundry Requests, Pickup Tasks, Processing Laundry, Ready for Delivery, and Delivered Laundry, under `/reception/housekeeping/laundry/`. Opening a row uses the same sheet. Permission: `housekeeping-laundry`.

Pickup stores the collected items as `label` and `qty`. On a picked-up or processing request, each charge line selects a collected item. The dropdown keeps that item while some of its collected count is still unused on the other lines, and leaves it out once that count is used. Choosing it sets the quantity to the count still left. That quantity cannot be higher than the collected count. `POST /api/housekeeping/laundry/{id}/lines` returns 422 when the item is not in the collection or the quantity is higher.

Saving charges on a picked-up request moves it to processing. Folio posting is available after the request is delivered.

## Not included

A charge line cannot name an item that was not collected. The collected count is not changed from the charge sheet.
