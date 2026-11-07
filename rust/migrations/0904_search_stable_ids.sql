-- A suggestion the search box showed carries its `search` row id, and search_utility empties and refills the
-- table. With AUTOINCREMENT a refill hands out fresh ids, so a suggestion left on screen across a rebuild finds no
-- row (and falls back to the keyword search) instead of finding a different row under its old id.
DROP VIEW search_named;

CREATE TABLE search_rebuilt (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "language_code" TEXT,
  "active" INTEGER,
  "table_name" TEXT,
  "code" TEXT,
  "code_normalized" TEXT,
  "category_id" INTEGER,
  "subcategory_id" INTEGER,
  "inventory_id" INTEGER,
  "ranking" INTEGER
);
INSERT INTO search_rebuilt SELECT * FROM search;
DROP TABLE search;
ALTER TABLE search_rebuilt RENAME TO search;
CREATE INDEX "search_code_index" ON "search" ("language_code", "active", "code");
CREATE INDEX "search_code_normalized_index" ON "search" ("code_normalized");
CREATE TRIGGER search_insert AFTER INSERT ON search BEGIN UPDATE search_version SET n = n + 1; END;
CREATE TRIGGER search_update AFTER UPDATE ON search BEGIN UPDATE search_version SET n = n + 1; END;
CREATE TRIGGER search_delete AFTER DELETE ON search BEGIN UPDATE search_version SET n = n + 1; END;

DELETE FROM sqlite_sequence WHERE name IN ('search', 'search_rebuilt');
INSERT INTO sqlite_sequence (name, seq) VALUES ('search', coalesce((SELECT max(id) FROM search), 0));

CREATE VIEW search_named AS
SELECT s.id, s.language_code, s.active, s.table_name, s.category_id, s.subcategory_id, s.inventory_id, s.ranking,
       CASE WHEN i.id IS NULL THEN s.code ELSE i.sku END AS code,
       CASE WHEN i.id IS NULL THEN s.code_normalized
            ELSE upper(replace(replace(replace(replace(replace(i.sku, '-', ''), ' ', ''), '_', ''), '.', ''), '/', '')) END AS code_normalized
FROM search s LEFT JOIN inventory i ON s.table_name = 'inventory' AND i.id = s.inventory_id;
