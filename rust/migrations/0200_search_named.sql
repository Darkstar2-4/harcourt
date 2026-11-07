-- The search rows under the names they stand for now: a part row by its part's current number (the table keeps
-- the number the part had when search_utility last filled it), every other row by its own code.
CREATE VIEW search_named AS
SELECT s.id, s.language_code, s.active, s.table_name, s.category_id, s.subcategory_id, s.inventory_id, s.ranking,
       CASE WHEN i.id IS NULL THEN s.code ELSE i.sku END AS code,
       CASE WHEN i.id IS NULL THEN s.code_normalized
            ELSE upper(replace(replace(replace(replace(replace(i.sku, '-', ''), ' ', ''), '_', ''), '.', ''), '/', '')) END AS code_normalized
FROM search s LEFT JOIN inventory i ON s.table_name = 'inventory' AND i.id = s.inventory_id;
