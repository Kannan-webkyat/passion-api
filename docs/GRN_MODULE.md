# GRN Module — Passion ERP

## Overview

Standalone **Goods Received Note** module replacing direct PO → stock posting.  
Inventory increases **only on GRN approval** via `reference_type = grn`.

**Golden rule:** Partial / full receipt lives on the **PO**. Each GRN is simply `approved` once stock is posted — never `partial_received` or `fully_received` at GRN level.

## Workflow

```
PO (sent / partial)
  → GRN draft
  → submit (pending = goods received at dock)
  → inspect (quality check — accepted / rejected / partial acceptance)
  → approve (approved = stock IN to Main Store)
```

Multiple GRNs per PO supported (partial deliveries).

**Main Store only:** GRN create/approve rejects Kitchen, Bar, and Housekeeping locations server-side. Use store requisition after GRN approval.

Example:

| PO #1001 | Chicken 50 kg |
|----------|---------------|
| GRN #1   | 30 kg → approved |
| GRN #2   | 20 kg → approved |
| PO status | `partial` then `received` |

## Database

| Table | Purpose |
|-------|---------|
| `grns` | Header + finance-ready fields (invoice date, currency, etc.) |
| `grn_items` | Line-level received / rejected / accepted + quality status |
| `grn_attachments` | Delivery note, invoice, transport doc, photos |
| `grn_audit_logs` | Who / when / action / status changes |

### Future-ready header fields (schema only)

- `supplier_invoice_number`, `invoice_date`, `payment_due_date`
- `currency`, `exchange_rate`

### Future-ready line fields (schema only)

- `manufacture_date`, `expiry_date`, `batch_number`, `storage_condition`

## API (`/api/inventory/grns`)

| Method | Endpoint | Action |
|--------|----------|--------|
| GET | `/grns/meta` | Statuses, rejection reasons, document types |
| GET | `/grns` | List (filter: `purchase_order_id`, `status`) |
| POST | `/grns` | Create draft (`submit`, `approve` flags optional) |
| GET | `/grns/{id}` | Detail + audit + attachments |
| PUT | `/grns/{id}` | Update draft only |
| POST | `/grns/{id}/submit` | Draft → Pending (received at dock) |
| POST | `/grns/{id}/inspect` | Quality inspection (required before approve) |
| POST | `/grns/{id}/approve` | Pending → Approved + stock IN |
| POST | `/grns/{id}/cancel` | Cancel draft/pending |
| POST | `/grns/{id}/attachments` | Upload document (multipart) |
| GET | `/purchase-orders/{id}/grn-remaining` | Prefill lines |

## GRN statuses

| Status | Meaning |
|--------|---------|
| `draft` | Being prepared |
| `pending` | Submitted — goods received, awaiting inspection |
| `approved` | Inspected and approved — stock posted |
| `cancelled` | Voided |

## PO status sync

| Condition | PO status |
|-----------|-----------|
| No lines received | `sent` |
| Some lines received | `partial` |
| All lines fully received | `received` |

## Quality inspection

Per line:

| Field | Values |
|-------|--------|
| `quality_status` | `accepted`, `rejected`, `partial_acceptance` |
| `rejection_reason` | System constant only (see below) |
| `rejection_notes` | Free text for extra detail (e.g. "2 bottles cracked during unloading") |

When `quantity_rejected > 0`, `rejection_reason` is **mandatory** — system constants only:

| Key | Label |
|-----|-------|
| `bottle_damaged` | Bottle damaged |
| `seal_broken` | Seal broken |
| `short_supply` | Short supply |
| `wrong_item` | Wrong item |
| `expired` | Expired |
| `packaging_damage` | Packaging damage |

Never allow custom text in `rejection_reason`. Staff use `rejection_notes` for detail.

### Alcohol / bar (optional batch traceability)

No per-bottle serial numbers required in v1. Optional `batch_number` on GRN lines during inspection (e.g. `BL240617` for Black Label 750ml — 11 accepted).

## Approval lock (immutability)

Once `approved` (or `cancelled`), a GRN is **immutable**:

| Disallowed | Allowed |
|------------|---------|
| Edit header/lines | View |
| Delete | Print / export |
| Change quantities or costs | View attachments |
| Cancel | Add supplementary attachments (audited) |

**Corrections:** use **Inventory Adjustment** or a future **Reversal GRN** — never edit history.

## Audit trail (header)

| Field | Purpose |
|-------|---------|
| `created_by` / `created_at` | Draft created |
| `submitted_by` / `submitted_at` | Goods received at dock |
| `inspected_by` / `inspected_at` | Quality inspection |
| `approved_by` / `approved_at` | Stock posted |
| `cancelled_by` / `cancelled_at` | Voided |

Plus `grn_audit_logs` for every action.

## Document uploads

| Type | Purpose |
|------|---------|
| `delivery_note` | Supplier delivery slip |
| `supplier_invoice` | Invoice copy |
| `transport_document` | Courier / vehicle document |
| `photo` | Damage evidence |

## Backward compatibility

- `POST /purchase-orders/{id}/receive` → creates GRN → submit → auto-inspect → approve
- Seeders using `PurchaseOrderService::receivePurchaseOrder()` unchanged
- Old `inventory_transactions` with `reference_type=purchase_order` remain valid

## Permissions

- `manage-grn` — GRN CRUD + inspect + approve
- `manage-inventory` — also allowed (store team)

## UI

**Procurement → GRN tab** — list, create from PO, receive → inspect → approve, documents, audit.  
**PO detail → Create GRN** or **Quick receive all** (legacy one-step).

## Future accounting hooks

- `grn_id` → AP invoice matching
- `line_subtotal_accepted` + `line_tax_accepted` per line for GL posting
