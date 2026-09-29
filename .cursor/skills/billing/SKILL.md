---
name: billing
description: Changes room folio billing and payments in passion-api — BookingPaymentLedger (payments, refunds, adjustments, split tenders, voids, syncScalars), folio charge writers (extra_charges, booking_extra_charges, POS room_charge), checkout discount, cancellation fees, invoices/vouchers, and the checkout journal (BookingCheckoutPoster, JournalPostingService, LedgerBackedTransaction). Use when working on booking payments, deposits, refunds, folio charges, invoices, cancellation money or accounting postings for rooms in passion-api.
---

# Billing and payments (passion-api)

## 1. When to use the skill
- Recording/voiding booking payments or refunds; split tenders.
- Posting a new charge to a room folio.
- Changing bill totals, checkout discount, cancellation fee/refund, invoice/voucher PDFs.
- Touching room-side accounting (`BookingCheckoutPoster`) or `JournalPostingService`.

## 2. Required investigation before coding
1. `PASSION_LARAVEL_ARCHITECTURE.md` §35 (billing), §32 cancel, §34 checkout.
2. Read `app/Support/BookingPaymentLedger.php` (all public methods) and `BookingController::storePayment()`,
   `voidPayment()`, `listPayments()`, `folioPostings()`, `effectiveBookingGrand()`.
3. Read `app/Support/BookingInvoiceRoomStay.php` (`summarizeForInvoice()`, `sumPosRoomChargePayments()`).
4. For a new folio charge: read an existing writer — `LaundryRequestController::postToRoom()`,
   `HousekeepingController::checkoutInspectionApply()`, POS `room_charge` settle in `PosController`.
5. For journals: `JournalPostingService::post()`, `LedgerBackedTransaction::run()`, `BookingCheckoutPoster::post()`.

## 3. Existing project patterns to follow
- **Payments are recorded through the ledger** (`BookingPaymentLedger`, static, `final`):
  `recordPayment` / `recordRefund` / `recordAdjustment` / `recordSplitPayments` / `voidPayment`, each in
  `DB::transaction` and followed by `syncScalars()` which recomputes `bookings.deposit_amount`,
  `refund_amount`, `payment_method`, `payment_status`.
- Existing direct scalar writes (keep them; don't add new ones):
  `BookingController::store()` zeroes `deposit_amount` before seeding the ledger;
  `cancelReservation()` writes `payment_status` (+ deposit/refund scalars when the ledger is disabled);
  `update()` passes a patched `payment_status` through unless a bill hint is present.
- `enabled()` ⇔ `Schema::hasTable('booking_payments')`; callers branch on it (`storePayment()` returns 503 when disabled).
- Methods: `BookingPaymentLedger::METHODS` = `cash, card, upi, bank_transfer`.
  Sources: `booking_create, deposit, checkout, manual, legacy_patch, cancellation`.
- Guards: no postings on cancelled bookings (except `source=cancellation`); no *payments* after checkout;
  refunds after close need `allow_closed => true`. Voids are soft (`voided_at`, `voided_by`, `void_reason`)
  and refused after checkout/cancellation.
- **Folio charges**: create `BookingExtraCharge` (`source` `inspection` | `laundry`, `kind`, `label`, `qty`,
  `unit_amount`, `total_amount`, `meta`), increment `bookings.extra_charges`, then broadcast
  `BookingChargesUpdated(bookingId, extraCharges, addedAmount, badge)` deferred via `App::terminating()`
  (laundry, checkout inspection and POS room charge all defer this event; POS *outlet* broadcasts are immediate).
  `LaundryRequestController::postToRoom()` guards with `Schema::hasColumn('booking_extra_charges', 'description')`.
- **Bill total**: `effectiveBookingGrand()` = room stay + folio extras − `checkout_discount_amount`;
  payment endpoints accept optional `bill_total`, defaulting to `round($this->effectiveBookingGrand($booking), 2)`.
- **Journals**: room revenue is recognized only at checkout by `BookingCheckoutPoster::post()` inside the
  checkout transaction; deposits are not journaled at receipt. `JournalPostingService::post()` is idempotent
  per `(source_type, source_id)` and balanced; `JournalPostingException` → 422 globally.
  POS/inventory use `LedgerBackedTransaction::run(mutate, postMutate, postJournal, journalRequired)` (fail-closed)
  for settle / amend / paid void; POS refund uses a plain `DB::transaction` with `PosRefundPoster::post()`.
- **POS room charge**: settle `increment('extra_charges')` inside the ledger-backed transaction; a
  `room_charge` refund lowers `extra_charges` under `lockForUpdate()`. POS payments are `pos_payments`,
  not `booking_payments`.
- **Cancellation money**: `BookingCancellationPolicy::preview()` → ledger payment/refund with
  `source => 'cancellation'` inside the cancel transaction.
- **Invoices**: `ReservationInvoiceViewData` + DomPDF views in `resources/views/bookings/*`.

## 4. Step-by-step implementation workflow
```
- [ ] 1. Classify: payment/refund, folio charge, bill computation, journal, or invoice
- [ ] 2. Payment/refund: call BookingPaymentLedger::record*() with source + bill_total; no new direct scalar writes
- [ ] 3. Folio charge: BookingExtraCharge row + extra_charges increment in one transaction; broadcast after commit
- [ ] 4. Bill computation: change BookingInvoiceRoomStay / effectiveBookingGrand and check every consumer
         (checkout paid-check, payments bill_total, invoice view data, BookingCheckoutPoster::netGrand)
- [ ] 5. Journal: posting inside the same transaction as the state change; stable source_type + source_id
- [ ] 6. Response: {message, payment(s), booking, totals: BookingPaymentLedger::totals($booking)} like storePayment()
- [ ] 7. Tests
```

## 5. Validation requirements
- `storePayment()` rules: `type in:payment,refund`, `amount numeric|min:0.01`,
  `method in:cash,card,upi,bank_transfer`, `source in:deposit,checkout,manual`, `tenders.*` for split payments
  (split tenders only for payments). Ledger re-validates amount > 0 and method.
- Ownership: payment must belong to the booking (404 otherwise).
- Money rounding: `round(..., 2)` and `0.004` / `0.009` tolerances as in existing code.

## 6. Authorization requirements
- Booking payments / voids / folio reads: `allowReservationEdit()` / `allowReservationRead()` in `BookingController`.
- Invoice / voucher: `allowReservationBillingExport()`.
- Laundry post-to-room: `allowHousekeepingLaundryOperate()` (from `AuthorizesHousekeepingPermissions`).
- Checkout inspection charges: `HK_CHECKOUT` in `HousekeepingController`.
- POS room charge: `pos-settle` (+ outlet access) in `PosController`.

## 7. Testing requirements
- Ledger: extend `tests/Unit/Support/BookingPaymentLedgerTest.php` (tables built in `setUp()`).
- Cancellation fee math: `tests/Unit/Support/BookingCancellationPolicyTest.php`.
- Journals: `tests/Unit/JournalPostingServiceTest.php`, `AccountingPostersPhaseATest.php`,
  `LedgerBackedTransactionTest.php` — these self-skip when `journal_entries` is missing; say so if you
  could not exercise them.

## 8. Verification checklist
- [ ] New booking money movements go through `BookingPaymentLedger`; no new direct scalar writes;
      existing ones (`store()`, `cancelReservation()`, `update()`) unchanged unless asked
- [ ] New code does not delete or edit `booking_payments` rows (`destroy()` cascade is existing behavior)
- [ ] Folio charge writes `booking_extra_charges` + `extra_charges` and broadcasts `BookingChargesUpdated`
- [ ] Journals posted in the same transaction as the state change; idempotent source key
- [ ] Bill-total change reviewed across checkout, payments, invoice and poster
- [ ] Response includes `totals`; status codes unchanged (201 on record, 503 ledger missing)
