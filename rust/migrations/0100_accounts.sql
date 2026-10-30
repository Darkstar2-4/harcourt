-- Accounts: who is asking. Lifetimes are unix seconds, as in 0001.

-- A person who proved an address at a company stays signed in on that browser for the registry's remote_days,
-- renewed on use, as `remote.expiry` did in classes/remote.php. 0 keeps the fixed 12-hour lifetime of 0001.
ALTER TABLE session ADD COLUMN renew_secs INTEGER NOT NULL DEFAULT 0 CHECK (renew_secs >= 0);

-- Sign-in by company address looks the visitor's address up on every request that has no session.
CREATE INDEX user_ip ON "user" (ip);

-- An Administrator seeing the site as an account or as an anonymous visitor (user_impersonate.php). It hangs off
-- the Administrator's own session, so signing out ends it. The view is stored, not trusted from a form: the role
-- is checked against the Administrator's when the row is written and again when it is read.
CREATE TABLE impersonation (
    session_hash  BLOB PRIMARY KEY REFERENCES session (token_hash) ON DELETE CASCADE,
    user_id       INTEGER REFERENCES "user" (id) ON DELETE CASCADE,
    "type"        TEXT NOT NULL DEFAULT 'Customer'
                  CHECK ("type" IN ('Customer', 'External', 'Internal', 'Executive', 'Administrator', 'Super User')),
    status        TEXT NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Active', 'Denied')),
    catalog       INTEGER NOT NULL DEFAULT 0,
    cad           INTEGER NOT NULL DEFAULT 0,
    ecommerce     INTEGER NOT NULL DEFAULT 0,
    price_type    TEXT NOT NULL DEFAULT '' CHECK (price_type IN ('', 'base', 'discount', 'plus')),
    price_pct     REAL NOT NULL DEFAULT 0,
    industry_id   INTEGER NOT NULL DEFAULT 0,
    portal_id     INTEGER NOT NULL DEFAULT 0,
    portal_legacy INTEGER NOT NULL DEFAULT 0,
    currency_code TEXT NOT NULL DEFAULT 'USD',
    language_code TEXT NOT NULL DEFAULT 'EN',
    campaign_code TEXT,
    created_at    INTEGER NOT NULL
) STRICT;
