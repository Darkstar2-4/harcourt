-- A cart without a session lasts a day from its shopper's last use of it, as the PHP session that held it did, and
-- goes as a whole. Every line of a cart carries that time; `added_at` keeps the order of the lines.
ALTER TABLE cart_item ADD COLUMN touched_at INTEGER NOT NULL DEFAULT 0;
UPDATE cart_item SET touched_at = (SELECT max(c.added_at) FROM cart_item c WHERE c.session_hash = cart_item.session_hash);

DROP INDEX cart_item_added;
CREATE INDEX cart_item_touched ON cart_item (touched_at);
