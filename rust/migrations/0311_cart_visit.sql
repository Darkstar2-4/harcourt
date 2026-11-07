-- A cart belongs to the browser's visit. With a session that is the session, as before. An account signed in by
-- company address has no session row: its cart is keyed by the PHP session the browser carries, as PHP kept it.
-- So the key no longer always names a session. A session's cart still goes with the session (the trigger), and
-- the others expire with age (orders::shop::cart::put).

CREATE TABLE cart_item_visit (
    session_hash BLOB NOT NULL CHECK (length(session_hash) = 32),
    sku          TEXT NOT NULL,
    quantity     INTEGER NOT NULL CHECK (quantity > 0),
    comment      TEXT NOT NULL DEFAULT '',
    added_at     INTEGER NOT NULL,
    PRIMARY KEY (session_hash, sku)
) STRICT;

INSERT INTO cart_item_visit (session_hash, sku, quantity, comment, added_at)
SELECT session_hash, sku, quantity, comment, added_at FROM cart_item;

DROP TABLE cart_item;
ALTER TABLE cart_item_visit RENAME TO cart_item;

CREATE INDEX cart_item_added ON cart_item (added_at);

CREATE TRIGGER cart_item_session_gone AFTER DELETE ON session BEGIN
    DELETE FROM cart_item WHERE session_hash = OLD.token_hash;
END;
