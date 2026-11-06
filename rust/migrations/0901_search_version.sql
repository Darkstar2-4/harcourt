-- The search box keeps an index of the `search` table (catalog/search.rs, `Cache`) and answers with its row ids. A
-- rebuild of the table reuses ids, so the index is rebuilt when this count of every change to the table moves.
CREATE TABLE search_version (id INTEGER PRIMARY KEY CHECK (id = 0), n INTEGER NOT NULL);
INSERT INTO search_version (id, n) VALUES (0, 0);

CREATE TRIGGER search_insert AFTER INSERT ON search BEGIN UPDATE search_version SET n = n + 1; END;
CREATE TRIGGER search_update AFTER UPDATE ON search BEGIN UPDATE search_version SET n = n + 1; END;
CREATE TRIGGER search_delete AFTER DELETE ON search BEGIN UPDATE search_version SET n = n + 1; END;
