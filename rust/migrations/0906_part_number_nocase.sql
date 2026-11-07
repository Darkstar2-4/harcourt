-- Part numbers are one key whatever their case, as under the legacy tables' latin1 collation: `inventory` holds
-- one row per number, and a cross reference line names its part and the other number case-insensitively, so an
-- import line for BLP-3 finds and replaces the stored blp-3 instead of adding a second spelling beside it.
DROP INDEX "inventory_sku_key";
CREATE UNIQUE INDEX "inventory_sku_key" ON "inventory" ("sku" COLLATE NOCASE);

CREATE TABLE "inventory_xref_nocase" (
  "sku" TEXT NOT NULL COLLATE NOCASE,
  "competitor" TEXT NOT NULL COLLATE NOCASE,
  "competitor_sku" TEXT NOT NULL COLLATE NOCASE,
  "update_timestamp" INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY ("sku", "competitor", "competitor_sku")
);
INSERT OR REPLACE INTO "inventory_xref_nocase" ("sku", "competitor", "competitor_sku", "update_timestamp")
  SELECT "sku", "competitor", "competitor_sku", "update_timestamp" FROM "inventory_xref" ORDER BY "update_timestamp", rowid;
DROP TABLE "inventory_xref";
ALTER TABLE "inventory_xref_nocase" RENAME TO "inventory_xref";
