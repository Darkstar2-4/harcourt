-- What PHP kept in its session for a visit and pages here read or change: whether the visit is inside the WordPress
-- frame ($_SESSION['scscpq_wp']), a language chosen this visit, and the campaign it arrived from. The browser's
-- PHPSESSID names the visit and only its SHA-256 keys the row. It holds preferences, never a sign-in.
CREATE TABLE visit (
    id            BLOB PRIMARY KEY CHECK (length(id) = 32),
    framed        INTEGER NOT NULL DEFAULT 0 CHECK (framed IN (0, 1)),
    language_code TEXT,
    campaign_code TEXT,
    seen_at       INTEGER NOT NULL
) STRICT;
CREATE INDEX visit_seen ON visit (seen_at);
