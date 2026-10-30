-- One browser's trip through Microsoft or Google sign-in (oauth/*.php kept this in the PHP session). The browser
-- holds a random token in a short-lived cookie and only its SHA-256 keys the row. State, PKCE verifier and nonce
-- never leave the server. After the callback the row holds only the outcome, until the sign-up page shows it once.
CREATE TABLE oauth_login (
    token_hash BLOB PRIMARY KEY CHECK (length(token_hash) = 32),
    provider   TEXT NOT NULL CHECK (provider IN ('microsoft', 'google')),
    state      TEXT,
    verifier   TEXT,
    nonce      TEXT,
    created_at INTEGER NOT NULL,
    outcome    TEXT CHECK (outcome IN ('error', 'no_email', 'blacklisted', 'granted', 'pending', 'submitted', 'failed')),
    message    TEXT,
    CHECK ((outcome IS NULL) = (state IS NOT NULL AND verifier IS NOT NULL AND nonce IS NOT NULL))
) STRICT;
CREATE INDEX oauth_login_created ON oauth_login (created_at);
