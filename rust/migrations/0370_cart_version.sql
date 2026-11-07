-- One row per cart key: when its shopper last used the cart, and how many times the cart has been written since. A
-- request finds the count with the cart and writes only while it still holds, counting its own write, so a request
-- that found the cart before another request, a takeover or a checkout changed it writes nothing. The row lives as
-- long as the key: a session's goes with the session, any other a day after its last use, its lines with it.
-- It replaces the lines' own use times and the takeover count.

CREATE TABLE cart (
    session_hash BLOB PRIMARY KEY CHECK (length(session_hash) = 32),
    version      INTEGER NOT NULL,
    touched_at   INTEGER NOT NULL
) STRICT;

CREATE INDEX cart_touched ON cart (touched_at);

INSERT INTO cart (session_hash, version, touched_at)
SELECT session_hash, max(version), max(touched_at) FROM (
    SELECT session_hash, 0 AS version, touched_at FROM cart_item
    UNION ALL
    SELECT session_hash, takeovers, taken_at FROM cart_takeover
) GROUP BY session_hash;

DROP TABLE cart_takeover;
DROP INDEX cart_item_touched;
ALTER TABLE cart_item DROP COLUMN touched_at;

CREATE TRIGGER cart_gone AFTER DELETE ON cart BEGIN
    DELETE FROM cart_item WHERE session_hash = OLD.session_hash;
END;

DROP TRIGGER cart_item_session_gone;
CREATE TRIGGER cart_session_gone AFTER DELETE ON session BEGIN
    DELETE FROM cart WHERE session_hash = OLD.token_hash;
    DELETE FROM cart_item WHERE session_hash = OLD.token_hash;
END;
