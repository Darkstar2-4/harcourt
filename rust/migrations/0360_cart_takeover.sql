-- How many times a session has taken over each cart kept by company address, and when it last did. A request that
-- found the cart before a takeover writes nothing to it afterwards; a row goes when its cart would have.

CREATE TABLE cart_takeover (
    session_hash BLOB PRIMARY KEY CHECK (length(session_hash) = 32),
    takeovers    INTEGER NOT NULL,
    taken_at     INTEGER NOT NULL
) STRICT;

CREATE INDEX cart_takeover_taken ON cart_takeover (taken_at);
