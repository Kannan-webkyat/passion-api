# Passion ERP — Official Workflow SOP

**Version:** 1.0 · **Property:** Passion Hotel & Restaurant  
**Status:** Adopted — aligned with system behaviour as implemented

---

## Golden rule

**POS never uses Main Store.**

Even if Main Store has stock and Kitchen/Bar has zero → POS shows **Out of Stock**.  
Staff must complete a **store requisition** (accepted) before service.

**GRN posts to Main Store only** — Kitchen, Bar, and Housekeeping receive stock via store requisition, never from supplier delivery.

```
Main Store → Requisition → Kitchen / Bar → Production → POS
```

### Current stock vs expected stock (planning)

| Field | Meaning | When it changes |
|-------|---------|-----------------|
| **Current stock** | Physical quantity on hand (per location) | GRN approval, requisition accept, production, POS, adjustments |
| **Expected stock** (`stock_expected`) | Open PO commitment — goods ordered but not yet received | Increases when PO is **sent**; decreases when GRN is **approved** |

Draft POs do **not** change expected stock. Staff must not treat `stock_expected` as stock already in the building.

---

## Page 1 — Operational flow (staff)

### Daily flow (12 steps)

| Step | Action | Where in Passion |
|------|--------|------------------|
| **1** | Supplier setup | Vendor Master |
| **2** | Purchase requisition *(optional)* | Procurement Requisition |
| **3** | Purchase order | Purchase Orders |
| **4** | GRN / receive goods | **GRN:** draft → receive (pending) → **inspect** → **approve** → **Main Store** (stock on approval only; partial/full on PO) |
| **5** | Move stock to service areas | Store Requisition |
| **6** | Semi-finished production | Kitchen Production (Prep) |
| **7** | Batch production | Kitchen Production (Batch) |
| **8** | Take order | POS + KOT |
| **9** | Stock deduction (automatic) | See table below |
| **10** | Wastage / spoilage | Inventory Adjustment |
| **11** | Physical stock audit | Count → Adjustment |
| **12** | Reports | Inventory & sales reports |

### Step 5 — Store requisition (two-step)

Stock does **not** move instantly. Flow:

```
Request → Approve → Issue (Main Store) → Accept (Kitchen / Bar / HK)
```

**Stock moves on Accept**, not on Issue.

Destinations:

- **Kitchen Store** — restaurant (OTTAAL)
- **Bar Store** — bar outlets
- **Housekeeping Store** — room PAR / minibar *(not F&B POS)*

### Steps 6–7 — Production @ Kitchen (or Bar)

```
Kitchen Store (or Bar Store for bar outlets)
│
├── Semi-finished Production
│   └── Biryani Masala, ginger-garlic paste, curry base
│
└── Batch Production
    └── Chicken Biryani, fried rice batches, dessert portions
```

- **OTTAAL:** prep + batch @ **Kitchen**
- **Bar:** spirits = direct sale @ **Bar Store**; cocktails (MTO) deduct @ **Bar Store**

### Step 9 — Automatic stock deduction

| Item type | Production? | When deducted | What deducted |
|-----------|-------------|---------------|---------------|
| **Batch** (e.g. Biryani) | Yes (Step 7) | KOT **Ready** or Settle | Finished **portions** |
| **MTO** (e.g. Tea, cocktails) | No | KOT **Ready** | Raw **ingredients** |
| **Direct sale** (whisky, beer, cans) | No | **Settle** (usually) | SKU qty (ml / pcs) |

**Always from Kitchen or Bar Store — never Main Store.**

---

## Page 2 — Master data (admin / setup)

### BOM is not a workflow step

Recipes are **master data** — configured once, referenced by production and POS.  
Do **not** place BOM in the numbered operational flow.

### Four item types

| Type | Examples | Production | POS / consumption |
|------|----------|------------|-------------------|
| **Semi-finished** | Biryani Masala | Prep production @ Kitchen | Consumed by batch BOM |
| **Batch** | Chicken Biryani | Batch production @ Kitchen | Deduct finished portions |
| **MTO** | Tea, cocktails | None | Deduct ingredients on KOT ready |
| **Direct sale SKU** | Spirits (peg), beer, Pepsi | None | Deduct linked SKU on settle |

### BOM examples

**Semi-finished — Biryani Masala (1 kg batch)**  
Coriander, Kashmiri chilli, chilli, turmeric, fennel, garam masala, pepper, cumin, shahi jeera, nutmeg, mace

**Batch — Chicken Biryani (per 1 kg chicken → 10 portions)**  
Chicken, rice, Biryani Masala, onion, tomato, ginger-garlic paste, green chilli, curd, mint, coriander, ghee, oil, lemon, salt

**MTO — Tea**  
Tea powder, milk, sugar

**Direct sale — Whisky 60ml**  
Linked spirit SKU (60 ml per peg)

### Admin flags (Passion-specific)

| Setting | Field | Meaning |
|---------|-------|---------|
| Send to KOT | `menu_item.requires_production` | Kitchen display? |
| Batch preparation | `recipe.requires_production` | Batch (true) vs MTO (false) |

| Item | KOT? | Recipe batch? | Behaviour |
|------|------|---------------|-----------|
| Chicken Biryani | Yes | Yes | Produce portions → sell |
| Tea | Optional | No | MTO — deduct on ready |
| Cocktail | Yes | No | MTO at bar |
| Whisky 60ml | No | — | Direct sale from Bar Store |

### Hotel extras

- **Room charge** — POS can post to guest folio
- **GRN** — Procurement → **GRN** tab; receive → inspect → approve; mandatory rejection reasons; document uploads; partial/full stays on PO. Legacy **Quick receive all** on PO still works (auto-inspects).
- **Stock audit** — physical count → inventory adjustment

---

## Auditor checklist

- [ ] Purchases received into **Main Store**
- [ ] Kitchen/Bar replenished via **requisition (accepted)**
- [ ] Prep items produced before batch recipes need them
- [ ] Batch dishes produced before POS shows availability
- [ ] Wastage recorded same day
- [ ] POS OOS when sub-store empty *(by design — not a bug)*

---

## System alignment notes (developers)

| Rule | Implementation |
|------|----------------|
| POS never uses Main Store | `InventoryDeductionStoreResolver` |
| Batch: produce raw → sell portions | Production deducts ingredients; POS deducts `inventory_item_id` |
| MTO: no production log | `recipe.requires_production = false`; deduct on KOT ready |
| Main full, Kitchen empty = OOS | POS `available_qty` reads outlet store only |
| Deduction idempotency | `inventory_deducted` flag on order lines |
