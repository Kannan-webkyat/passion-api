-- =============================================================================
-- DELETE ALL INVENTORY CATALOG ITEMS (inventory_items)
-- MySQL / MariaDB — run against your app database after a BACKUP.
--
-- Clears:
--   - All rows in inventory_items (CASCADE removes: inventory_item_locations,
--     inventory_transactions, recipe_ingredients, purchase_order_items,
--     pos_void_waste rows tied to those items).
--
-- Also clears:
--   - store_request_items (required first: FK to inventory_items is RESTRICT)
--
-- Sets menu_items.inventory_item_id to NULL (optional; nullOnDelete does this
-- on delete too, but explicit avoids edge cases).
--
-- Does NOT delete: inventory_categories, inventory_uoms, vendors,
-- inventory_locations, purchase_orders (headers may remain with no lines),
-- recipes (headers remain; ingredients cleared), menu_items rows.
-- =============================================================================

START TRANSACTION;

-- Blocked by RESTRICT unless removed first
DELETE FROM store_request_items;

UPDATE menu_items SET inventory_item_id = NULL WHERE inventory_item_id IS NOT NULL;

DELETE FROM inventory_items;

COMMIT;

-- Verify:
-- SELECT COUNT(*) FROM inventory_items;
