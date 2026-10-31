-- The catalog pages keep their parsed model of categories and families (catalog/pages/mod.rs, `Models`) until a row it
-- is built from changes. These triggers count every such change, made by this server, a command or another process.
CREATE TABLE catalog_version (id INTEGER PRIMARY KEY CHECK (id = 0), n INTEGER NOT NULL);
INSERT INTO catalog_version (id, n) VALUES (0, 0);

CREATE TRIGGER category_insert AFTER INSERT ON category BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER category_update AFTER UPDATE ON category BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER category_delete AFTER DELETE ON category BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER subcategory_insert AFTER INSERT ON subcategory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER subcategory_update AFTER UPDATE ON subcategory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER subcategory_delete AFTER DELETE ON subcategory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER inventory_insert AFTER INSERT ON inventory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER inventory_update AFTER UPDATE OF subcategory_id, active, new_date ON inventory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER inventory_delete AFTER DELETE ON inventory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER content_insert AFTER INSERT ON content WHEN NEW.record_type IN ('category', 'subcategory')
BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER content_update AFTER UPDATE ON content WHEN NEW.record_type IN ('category', 'subcategory') OR OLD.record_type IN ('category', 'subcategory')
BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER content_delete AFTER DELETE ON content WHEN OLD.record_type IN ('category', 'subcategory')
BEGIN UPDATE catalog_version SET n = n + 1; END;
