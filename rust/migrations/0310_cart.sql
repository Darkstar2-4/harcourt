-- The shopping cart, which PHP kept in $_SESSION['cart']. One row per SKU per browser session; it goes with the
-- session. Prices are not stored: they are worked out by the pricing module whenever the cart is shown or ordered.

CREATE TABLE cart_item (
    session_hash BLOB NOT NULL REFERENCES session (token_hash) ON DELETE CASCADE,
    sku          TEXT NOT NULL,
    quantity     INTEGER NOT NULL CHECK (quantity > 0),
    comment      TEXT NOT NULL DEFAULT '',
    added_at     INTEGER NOT NULL,
    PRIMARY KEY (session_hash, sku)
) STRICT;
