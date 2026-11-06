-- The one-time token each checkout form carries, kept with the order it placed. A form submitted twice, or sent
-- again after its answer was lost, finds that order instead of placing another.

CREATE TABLE checkout_token (
    token_hash BLOB PRIMARY KEY CHECK (length(token_hash) = 32),
    order_id   INTEGER NOT NULL UNIQUE REFERENCES orderhd (id) ON DELETE CASCADE
) STRICT;
