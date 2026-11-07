-- Core tables for the Rust-owned part of www.harcourt.co.
-- `user` and `log` mirror the MySQL tables documented in classes/user.php and classes/log.php, column for column,
-- so the cut-over import is a copy. Rust never writes `user` yet; it is filled by the import.
-- Times are unix seconds, as in the PHP app.

CREATE TABLE "user" (
    id                  INTEGER PRIMARY KEY,
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
    recaptcha_score     REAL NOT NULL DEFAULT 1.0
) STRICT;
CREATE INDEX user_domain ON "user" (lower(domain));

CREATE TABLE log (
    id             INTEGER PRIMARY KEY,
    ip             TEXT NOT NULL DEFAULT '',
    date_time      INTEGER NOT NULL DEFAULT 0,
    user_id        INTEGER NOT NULL DEFAULT 0,
    bot_id         INTEGER NOT NULL DEFAULT 0,
    email          TEXT,
    type           TEXT NOT NULL DEFAULT '',
    subtype        TEXT,
    sku            TEXT NOT NULL DEFAULT '',
    subcategory_id INTEGER NOT NULL DEFAULT 0,
    comment        TEXT,
    record_type    TEXT,
    bot_code       TEXT NOT NULL DEFAULT '',
    cad_code       TEXT NOT NULL DEFAULT ''
) STRICT;
CREATE INDEX log_user_id ON log (user_id);
CREATE INDEX log_date_time ON log (date_time);
CREATE INDEX log_type ON log (type, subtype);

-- A signed-in browser. The cookie holds a random 256-bit token; only its SHA-256 is stored, so a copy of this
-- table cannot be replayed as cookies.
CREATE TABLE session (
    token_hash BLOB PRIMARY KEY CHECK (length(token_hash) = 32),
    user_id    INTEGER NOT NULL REFERENCES "user" (id) ON DELETE CASCADE,
    email      TEXT,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
) STRICT;
CREATE INDEX session_expires ON session (expires_at);

-- The emailed sign-in code. One live code per address. The attempt counter lives here, not in a browser session,
-- so opening a new session does not reset it. Only an HMAC of the code is stored.
CREATE TABLE access_code (
    email      TEXT PRIMARY KEY,
    code_mac   BLOB NOT NULL CHECK (length(code_mac) = 32),
    sent_at    INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    attempts   INTEGER NOT NULL DEFAULT 0
) STRICT;

-- A one-time credential that lets an Administrator hand a signed-in view of another account to a person
-- (support, staff). Replaces scs_remote.php, which minted and accepted its codes without any sign-in.
CREATE TABLE remote_grant (
    token_hash BLOB PRIMARY KEY CHECK (length(token_hash) = 32),
    user_id    INTEGER NOT NULL REFERENCES "user" (id) ON DELETE CASCADE,
    granted_by INTEGER NOT NULL REFERENCES "user" (id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    used_at    INTEGER
) STRICT;

-- Mail waiting to go out. Requests only queue; a sender drains this table, so a mail outage never fails a request.
CREATE TABLE outbox (
    id         INTEGER PRIMARY KEY,
    to_email   TEXT NOT NULL,
    subject    TEXT NOT NULL,
    body       TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    sent_at    INTEGER
) STRICT;
CREATE INDEX outbox_unsent ON outbox (created_at) WHERE sent_at IS NULL;
