-- The authors of the imported posts: the slug of their archive URL, the name bylines print, and when Yoast last
-- recorded a change to their profile (the author sitemap's lastmod, unix seconds).
CREATE TABLE cms_author (
    id              INTEGER PRIMARY KEY,
    nicename        TEXT NOT NULL,
    display_name    TEXT NOT NULL,
    profile_updated INTEGER NOT NULL DEFAULT 0
) STRICT;

ALTER TABLE cms_page ADD COLUMN author_id INTEGER;

-- Blog categories and FAQ types, with their WPML language, and the posts in each.
CREATE TABLE cms_term (
    id          INTEGER PRIMARY KEY,
    taxonomy    TEXT NOT NULL CHECK (taxonomy IN ('category', 'faq_type')),
    slug        TEXT NOT NULL,
    name        TEXT NOT NULL,
    lang        TEXT CHECK (lang IN ('en', 'fr')),
    group_id    INTEGER,
    -- The newest post_modified_gmt of anything published or inherited in the term, whatever its type (WordPress
    -- files ACF field groups under categories too): the term's lastmod in Yoast's sitemap.
    modified_at TEXT NOT NULL DEFAULT ''
) STRICT;

CREATE TABLE cms_term_page (
    term_id INTEGER NOT NULL REFERENCES cms_term (id) ON DELETE CASCADE,
    page_id INTEGER NOT NULL REFERENCES cms_page (id) ON DELETE CASCADE,
    PRIMARY KEY (term_id, page_id)
) STRICT, WITHOUT ROWID;
