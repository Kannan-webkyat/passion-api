# Booking data reset — Passion Hotel PMS

`php artisan db:wipe-bookings` clears front-office data and keeps POS, inventory, procurement, and masters. It is for a property that ran POS and inventory in production before the front office started.

Without `--force` it only prints the row counts it would change. With `--force` it asks for the database name (skipped with `--yes`), optionally writes a `mysqldump` to `storage/app/backups/` (`--backup`), and runs every change in one transaction.

## What it changes

- Deletes all rows in `bookings`, `booking_segments`, `booking_groups`, `booking_payments`, `booking_extra_charges`, `booking_room_transfers`, `aiosell_booking_links`, `aiosell_messages`, `doorloom_booking_links`, `room_cleaning_releases`, `room_cleaning_release_audits`, `daily_room_cleanings`, `daily_room_cleaning_consumptions`, `laundry_requests`, and `laundry_request_lines`.
- Deletes `room_status_blocks` with status `dirty`, `cleaning`, `inspected`, or `pending_inspection`, and the `housekeeping_jobs` and `housekeeping_job_lines` on them.
- Deletes `journal_entries` with `source_type = booking_checkout` and their `journal_lines`. The command warns when any exist, because they are already in the trial balance.
- Sets `pos_orders.booking_id` to null. The POS order, its payments, and its journal stay.
- Sets `rooms.status` to `available` where it was `occupied`, `dirty`, `cleaning`, `inspected`, or `pending_inspection`.

## What it keeps

POS orders and payments, inventory items, stock quantities, `inventory_transactions` (including stock used by room cleaning), GRNs, purchase orders, requisitions, every other journal, `maintenance` and `on_hold` room blocks, room types, rate plans, rooms, users, and settings.

When AioSell is connected, run Push now on Settings → Integrations afterwards so the channel receives the new free-room counts.

## Not included

No undo apart from the optional backup. `db:wipe-ops --with-hotel` is a different command: it also truncates POS, stock, procurement, and all journals.
