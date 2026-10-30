-- What a staff page kept in $_SESSION between two requests: an import's parsed rows between its review and its
-- update, the subcategory editor's content items between the form and the save. One row per staff session and
-- page; it goes when the session goes.
CREATE TABLE staff_draft (
    token_hash BLOB NOT NULL REFERENCES session (token_hash) ON DELETE CASCADE,
    page       TEXT NOT NULL,
    body       BLOB NOT NULL,
    updated_at INTEGER NOT NULL,
    PRIMARY KEY (token_hash, page)
) STRICT;
