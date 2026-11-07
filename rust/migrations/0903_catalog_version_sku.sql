-- The search index (catalog/search.rs, `export`) labels parts with their part number and is rebuilt on
-- `catalog_version`, so a renumbered part moves it too. Prices, CAD links and the other part columns are read live.
DROP TRIGGER inventory_update;
CREATE TRIGGER inventory_update AFTER UPDATE OF sku, subcategory_id, active, new_date ON inventory BEGIN UPDATE catalog_version SET n = n + 1; END;
