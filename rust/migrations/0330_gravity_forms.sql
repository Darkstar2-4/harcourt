-- Gravity Forms' own tables, same names and columns as in WordPress, so tools/gf_dump_to_sqlite.py copies the form
-- definitions and the existing entries across as they are. The definitions (fields, notifications with their
-- recipients, confirmations) stay data; nothing about a form's recipients is in the program. Dates are UTC text,
-- as Gravity Forms writes them.

CREATE TABLE wp_gf_form (
    id           INTEGER PRIMARY KEY,
    title        TEXT NOT NULL,
    date_created TEXT NOT NULL,
    date_updated TEXT,
    is_active    INTEGER NOT NULL DEFAULT 1,
    is_trash     INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE wp_gf_form_meta (
    form_id           INTEGER PRIMARY KEY,
    display_meta      TEXT,
    entries_grid_meta TEXT,
    confirmations     TEXT,
    notifications     TEXT
);

CREATE TABLE wp_gf_entry (
    id               INTEGER PRIMARY KEY,
    form_id          INTEGER NOT NULL,
    post_id          INTEGER,
    date_created     TEXT NOT NULL,
    date_updated     TEXT,
    is_starred       INTEGER NOT NULL DEFAULT 0,
    is_read          INTEGER NOT NULL DEFAULT 0,
    ip               TEXT NOT NULL,
    source_url       TEXT NOT NULL DEFAULT '',
    user_agent       TEXT NOT NULL DEFAULT '',
    currency         TEXT,
    payment_status   TEXT,
    payment_date     TEXT,
    payment_amount   TEXT,
    payment_method   TEXT,
    transaction_id   TEXT,
    is_fulfilled     INTEGER,
    created_by       INTEGER,
    transaction_type INTEGER,
    status           TEXT NOT NULL DEFAULT 'active',
    source_id        INTEGER
);
CREATE INDEX wp_gf_entry_form_status ON wp_gf_entry (form_id, status);

CREATE TABLE wp_gf_entry_meta (
    id         INTEGER PRIMARY KEY,
    form_id    INTEGER NOT NULL DEFAULT 0,
    entry_id   INTEGER NOT NULL,
    meta_key   TEXT,
    meta_value TEXT,
    item_index TEXT
);
CREATE INDEX wp_gf_entry_meta_entry ON wp_gf_entry_meta (entry_id);
