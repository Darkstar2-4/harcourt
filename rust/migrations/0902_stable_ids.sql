-- Ids that other rows point at without a foreign key are never handed out again: a new row of these tables gets a
-- fresh id, as MySQL's AUTO_INCREMENT gave, instead of the id of the last row deleted, so it inherits nothing
-- (texts, files, parts, prices, log entries, category grants) of a deleted row. Each table is rebuilt with
-- AUTOINCREMENT, with its indexes and triggers as they were, and its counter starts past every id in use anywhere.
--
-- A deleted row takes its own attachments with it: an account's category grants, and the `content` rows (texts,
-- files) of a family, a campaign or a portal content item. History (log, orders, revisions) and the rows of other
-- tables that name it (parts, prices, portal items) stay, as the PHP admin left them.
--
-- Dropping a table that others reference would cascade to their rows unless foreign keys are off, and SQLite only
-- switches them off outside a transaction. sqlx runs every SQLite migration inside one (it does not honour
-- `-- no-transaction` for SQLite), so this migration ends that transaction, rebuilds in a transaction of its own,
-- and opens a fresh one for sqlx to record the migration in and commit.

COMMIT;
PRAGMA foreign_keys = OFF;
BEGIN;

CREATE TABLE user_rebuilt (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    ip                  TEXT,
    company_name        TEXT,
    address             TEXT,
    city                TEXT,
    state               TEXT,
    zip                 TEXT,
    country_code        TEXT,
    status              TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Active', 'Denied')),
    "type"              TEXT NOT NULL DEFAULT 'Customer'
                        CHECK ("type" IN ('Customer', 'External', 'Internal', 'Executive', 'Administrator', 'Super User')),
    last_login          INTEGER NOT NULL DEFAULT 0,
    login_document_id   INTEGER NOT NULL DEFAULT 0,
    landing_document_id INTEGER NOT NULL DEFAULT 0,
    comment             TEXT,
    portal_id           INTEGER NOT NULL DEFAULT 0,
    customer_code       TEXT,
    catalog             INTEGER NOT NULL DEFAULT 0,
    cad                 INTEGER NOT NULL DEFAULT 0,
    ecommerce           INTEGER NOT NULL DEFAULT 0,
    price_pct           REAL NOT NULL DEFAULT 0,
    language_code       TEXT NOT NULL DEFAULT 'EN',
    industry_id         INTEGER NOT NULL DEFAULT 0,
    leadscore           INTEGER NOT NULL DEFAULT 1,
    tabs                TEXT,
    price_type          TEXT NOT NULL DEFAULT '',
    revenue             TEXT NOT NULL DEFAULT '',
    employees           TEXT NOT NULL DEFAULT '',
    domain              TEXT,
    currency_code       TEXT NOT NULL DEFAULT 'USD',
    ipstack_json        TEXT,
    recaptcha_score     REAL NOT NULL DEFAULT 1.0,
    signup_ip           TEXT,
    signup_email        TEXT
) STRICT;
INSERT INTO user_rebuilt SELECT * FROM "user";
DROP TABLE "user";
ALTER TABLE user_rebuilt RENAME TO "user";
CREATE INDEX user_domain ON "user" (lower(domain));
CREATE INDEX user_ip ON "user" (ip);
CREATE INDEX user_signup_email ON "user" (signup_email);
CREATE TRIGGER user_denied_ends_sessions AFTER UPDATE OF status ON "user" WHEN NEW.status = 'Denied' BEGIN
    DELETE FROM session WHERE user_id = NEW.id;
END;
CREATE TRIGGER user_delete_categories AFTER DELETE ON "user" BEGIN
    DELETE FROM user_category WHERE user_id = OLD.id;
END;

CREATE TABLE subcategory_rebuilt (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "category_id" INTEGER DEFAULT 0,
  "name" TEXT,
  "prj" TEXT,
  "image_url" TEXT,
  "thumbnail_url" TEXT,
  "sketch_url" TEXT,
  "parameters" TEXT,
  "parameter_search" TEXT,
  "documents" TEXT,
  "sort_order" INTEGER DEFAULT 0,
  "new_date" TEXT NOT NULL DEFAULT '0000-00-00',
  "search_words" TEXT,
  "download" TEXT,
  "prj_landing_page" INTEGER DEFAULT 0,
  "page_break" INTEGER DEFAULT 1,
  "industry" TEXT,
  "portal" TEXT,
  "options" TEXT,
  "output" TEXT NOT NULL DEFAULT '',
  "status" TEXT NOT NULL DEFAULT '',
  "leadtime_option" TEXT NOT NULL DEFAULT '',
  "leadtime_text" TEXT,
  "currency" TEXT,
  "price_increase" INTEGER DEFAULT 0,
  "custom" INTEGER DEFAULT 0,
  "lookup" INTEGER DEFAULT 1
);
INSERT INTO subcategory_rebuilt SELECT * FROM subcategory;
DROP TABLE subcategory;
ALTER TABLE subcategory_rebuilt RENAME TO subcategory;
CREATE UNIQUE INDEX "subcategory_name_key" ON "subcategory" ("category_id", "name");
CREATE UNIQUE INDEX "subcategory_prj_UNIQUE" ON "subcategory" ("prj");
CREATE INDEX "subcategory_output_index" ON "subcategory" ("output");
CREATE TRIGGER subcategory_insert AFTER INSERT ON subcategory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER subcategory_update AFTER UPDATE ON subcategory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER subcategory_delete AFTER DELETE ON subcategory BEGIN UPDATE catalog_version SET n = n + 1; END;
CREATE TRIGGER subcategory_delete_content AFTER DELETE ON subcategory BEGIN
    DELETE FROM content WHERE record_type = 'subcategory' AND record_id = OLD.id;
END;

CREATE TABLE bot_rebuilt (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT NOT NULL DEFAULT '',
  "pattern" TEXT NOT NULL DEFAULT '',
  "name" TEXT NOT NULL DEFAULT '',
  "portal_id" INTEGER NOT NULL DEFAULT 0,
  "catalog" INTEGER NOT NULL DEFAULT 0,
  "comment" TEXT,
  "status" TEXT NOT NULL DEFAULT ''
);
INSERT INTO bot_rebuilt SELECT * FROM bot;
DROP TABLE bot;
ALTER TABLE bot_rebuilt RENAME TO bot;
CREATE UNIQUE INDEX "bot_code_index" ON "bot" ("code");

CREATE TABLE campaign_rebuilt (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "code" TEXT,
  "name" TEXT,
  "description" TEXT,
  "from_date" TEXT DEFAULT '0000-00-00',
  "thru_date" TEXT DEFAULT '0000-00-00',
  "active" INTEGER DEFAULT 1,
  "subcategory" TEXT,
  "invite" INTEGER DEFAULT 1,
  "type" TEXT DEFAULT 'Invite',
  "sort_order" INTEGER DEFAULT 0,
  "css" TEXT,
  "js" TEXT,
  "title_omit" INTEGER DEFAULT 0,
  "divs" TEXT,
  "sf_campaign_id" TEXT
);
INSERT INTO campaign_rebuilt SELECT * FROM campaign;
DROP TABLE campaign;
ALTER TABLE campaign_rebuilt RENAME TO campaign;
CREATE UNIQUE INDEX "campaign_code_key" ON "campaign" ("code");
CREATE UNIQUE INDEX "campaign_name_key" ON "campaign" ("name");
CREATE UNIQUE INDEX "campaign_sf_campaign_key" ON "campaign" ("sf_campaign_id");
CREATE TRIGGER campaign_delete_content AFTER DELETE ON campaign BEGIN
    DELETE FROM content WHERE record_type = 'campaign' AND record_id = OLD.id;
END;

CREATE TABLE portal_rebuilt (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL DEFAULT '',
  "headline" TEXT,
  "legacy" INTEGER NOT NULL DEFAULT 1,
  "active" INTEGER DEFAULT 1
);
INSERT INTO portal_rebuilt SELECT * FROM portal;
DROP TABLE portal;
ALTER TABLE portal_rebuilt RENAME TO portal;
CREATE UNIQUE INDEX "portal_name_key" ON "portal" ("name");

CREATE TABLE portalcontent_rebuilt (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "portal_id" INTEGER NOT NULL DEFAULT 0,
  "name" TEXT NOT NULL DEFAULT '',
  "form" TEXT NOT NULL DEFAULT '',
  "active" INTEGER NOT NULL DEFAULT 1
);
INSERT INTO portalcontent_rebuilt SELECT * FROM portalcontent;
DROP TABLE portalcontent;
ALTER TABLE portalcontent_rebuilt RENAME TO portalcontent;
CREATE UNIQUE INDEX "portalcontent_name_key" ON "portalcontent" ("portal_id", "form", "name");
CREATE TRIGGER portalcontent_delete_content AFTER DELETE ON portalcontent BEGIN
    DELETE FROM content WHERE record_type = 'portalcontent' AND record_id = OLD.id;
END;

-- Each counter starts past the highest id the table or any row naming it holds, so an id a deleted row left
-- behind in another table is not given out either.
DELETE FROM sqlite_sequence WHERE name IN ('user', 'subcategory', 'bot', 'campaign', 'portal', 'portalcontent');
INSERT INTO sqlite_sequence (name, seq) VALUES
    ('user', max(
        coalesce((SELECT max(id) FROM "user"), 0),
        coalesce((SELECT max(user_id) FROM log), 0),
        coalesce((SELECT max(user_id) FROM user_category), 0),
        coalesce((SELECT max(user_id) FROM orderhd), 0),
        coalesce((SELECT max(status_user_id) FROM orderhd), 0),
        coalesce((SELECT max(bcc_user_id) FROM mailform), 0),
        coalesce((SELECT max(saved_by) FROM cms_revision), 0)
    )),
    ('subcategory', max(
        coalesce((SELECT max(id) FROM subcategory), 0),
        coalesce((SELECT max(subcategory_id) FROM inventory), 0),
        coalesce((SELECT max(subcategory_id) FROM xinventory), 0),
        coalesce((SELECT max(CAST(subcategory_id AS INTEGER)) FROM pricing), 0),
        coalesce((SELECT max(subcategory_id) FROM search), 0),
        coalesce((SELECT max(subcategory_id) FROM portalcategory), 0),
        coalesce((SELECT max(subcategory_id) FROM log), 0),
        coalesce((SELECT max(record_id) FROM content WHERE record_type = 'subcategory'), 0)
    )),
    ('bot', max(
        coalesce((SELECT max(id) FROM bot), 0),
        coalesce((SELECT max(bot_id) FROM log), 0)
    )),
    ('campaign', max(
        coalesce((SELECT max(id) FROM campaign), 0),
        coalesce((SELECT max(record_id) FROM content WHERE record_type = 'campaign'), 0)
    )),
    ('portal', max(
        coalesce((SELECT max(id) FROM portal), 0),
        coalesce((SELECT max(portal_id) FROM "user"), 0),
        coalesce((SELECT max(portal_id) FROM xuser), 0),
        coalesce((SELECT max(portal_id) FROM bot), 0),
        coalesce((SELECT max(portal_id) FROM hero), 0),
        coalesce((SELECT max(portal_id) FROM portalcategory), 0),
        coalesce((SELECT max(portal_id) FROM portalcontent), 0)
    )),
    ('portalcontent', max(
        coalesce((SELECT max(id) FROM portalcontent), 0),
        coalesce((SELECT max(headline_portalcontent_id) FROM portalcategory), 0),
        coalesce((SELECT max(image_portalcontent_id) FROM portalcategory), 0),
        coalesce((SELECT max(record_id) FROM content WHERE record_type = 'portalcontent'), 0)
    ));

COMMIT;
PRAGMA foreign_keys = ON;
BEGIN;
