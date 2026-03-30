-- Keep only the 2 most recent purchase_orders (created_at DESC, then id DESC).
-- Deletes older POs (purchase_order_items cascade). Deletes inventory_transactions
-- with reference_type = purchase_order that are not for those two POs.
-- GRNs: purchase_order_id becomes NULL when a PO row is removed.
--
-- BACKUP FIRST.

START TRANSACTION;

DELETE FROM inventory_transactions
WHERE reference_type = 'purchase_order'
  AND CAST(reference_id AS UNSIGNED) NOT IN (
    SELECT id FROM (
      SELECT id
      FROM purchase_orders
      ORDER BY created_at DESC, id DESC
      LIMIT 2
    ) AS keepers
  );

DELETE FROM purchase_orders
WHERE id NOT IN (
  SELECT id FROM (
    SELECT id
    FROM purchase_orders
    ORDER BY created_at DESC, id DESC
    LIMIT 2
  ) AS keepers2
);

COMMIT;
