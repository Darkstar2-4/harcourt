-- The search rows under the names they stand for now: a part row by its part's current number, a family row by the
-- family's current name in the row's language (the table keeps what search_utility last copied from `content`),
-- every other row by its own code. The search box and the results page both read this view, so a renamed family
-- is found by its new name and no longer by the old one, in both, before the table is filled again.
DROP VIEW search_named;

CREATE VIEW search_named AS
SELECT s.id, s.language_code, s.active, s.table_name, s.category_id, s.subcategory_id, s.inventory_id, s.ranking,
       CASE WHEN i.id IS NOT NULL THEN i.sku WHEN c.id IS NOT NULL THEN c.name ELSE s.code END AS code,
       CASE WHEN i.id IS NOT NULL
                 THEN upper(replace(replace(replace(replace(replace(i.sku, '-', ''), ' ', ''), '_', ''), '.', ''), '/', ''))
            WHEN c.id IS NOT NULL
                 THEN replace(replace(replace(replace(replace(c.name, '-', ''), ' ', ''), '_', ''), '.', ''), '/', '')
            ELSE s.code_normalized END AS code_normalized
FROM search s
LEFT JOIN inventory i ON s.table_name = 'inventory' AND i.id = s.inventory_id
LEFT JOIN content c ON s.table_name = 'subcategory' AND c.record_type = 'subcategory' AND c.record_item = ''
                   AND c.record_id = s.subcategory_id AND c.language_code = s.language_code;
