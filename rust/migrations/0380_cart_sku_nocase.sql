-- A part number is one key in a cart whatever its case, as it is in the catalog (0906): adding BLP-3 to a cart
-- holding blp-3 changes that line, and moving one cart into another adds the two to one line. Lines of a cart that
-- differ only by case become one line holding their summed quantity, as checkout would have ordered them, in the
-- place of the earliest, with the first comment and the latest spelling; a request that found the cart before
-- writes nothing to it.

UPDATE cart SET version = version + 1 WHERE session_hash IN (
    SELECT session_hash FROM cart_item GROUP BY session_hash, sku COLLATE NOCASE HAVING count(*) > 1
);

DROP TRIGGER cart_gone;
DROP TRIGGER cart_session_gone;

CREATE TABLE cart_item_nocase (
    session_hash BLOB NOT NULL CHECK (length(session_hash) = 32),
    sku          TEXT NOT NULL COLLATE NOCASE,
    quantity     INTEGER NOT NULL CHECK (quantity > 0),
    comment      TEXT NOT NULL DEFAULT '',
    added_at     INTEGER NOT NULL,
    PRIMARY KEY (session_hash, sku)
) STRICT;

INSERT INTO cart_item_nocase (session_hash, sku, quantity, comment, added_at)
SELECT g.session_hash,
       (SELECT l.sku FROM cart_item l WHERE l.session_hash = g.session_hash AND l.sku = g.sku COLLATE NOCASE
        ORDER BY l.added_at DESC, l.rowid DESC LIMIT 1),
       min(sum(g.quantity), 4294967295),
       coalesce((SELECT c.comment FROM cart_item c WHERE c.session_hash = g.session_hash AND c.sku = g.sku COLLATE NOCASE
                 AND c.comment <> '' ORDER BY c.added_at, c.rowid LIMIT 1), ''),
       min(g.added_at)
FROM cart_item g
GROUP BY g.session_hash, g.sku COLLATE NOCASE
ORDER BY min(g.added_at), min(g.rowid);

DROP TABLE cart_item;
ALTER TABLE cart_item_nocase RENAME TO cart_item;

CREATE TRIGGER cart_gone AFTER DELETE ON cart BEGIN
    DELETE FROM cart_item WHERE session_hash = OLD.session_hash;
END;

CREATE TRIGGER cart_session_gone AFTER DELETE ON session BEGIN
    DELETE FROM cart WHERE session_hash = OLD.token_hash;
    DELETE FROM cart_item WHERE session_hash = OLD.token_hash;
END;
