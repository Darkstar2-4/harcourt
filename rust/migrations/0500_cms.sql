-- The content WordPress used to hold, rendered by rust/src/cms. Rows keep their WordPress ids (post, attachment and
-- menu item ids) so a re-import converges on the same rows and the markup keeps ids such as menu-item-140.
-- Text and markup values are stored exactly as the page shows them.

CREATE TABLE cms_page (
    id           INTEGER PRIMARY KEY,
    -- Translations of one page share a group (the WPML trid).
    group_id     INTEGER NOT NULL,
    lang         TEXT NOT NULL CHECK (lang IN ('en', 'fr')),
    -- '/product/alignment/'. A French page is served at its path with ?lang=fr.
    path         TEXT NOT NULL,
    -- The theme template file WordPress recorded, e.g. 'layouts/template-product-single.php'; '' is page.php.
    template     TEXT NOT NULL,
    status       TEXT NOT NULL CHECK (status IN ('publish', 'draft')),
    title        TEXT NOT NULL,
    parent_id    INTEGER,
    menu_order   INTEGER NOT NULL DEFAULT 0,
    -- GMT, 'YYYY-MM-DD HH:MM:SS', as WordPress stores post_date_gmt and post_modified_gmt.
    published_at TEXT NOT NULL,
    modified_at  TEXT NOT NULL,
    UNIQUE (lang, path)
) STRICT;
CREATE INDEX cms_page_group ON cms_page (group_id);

-- What a page shows: ACF fields under their ACF names (repeater rows as name_0_sub) and the post content as
-- 'content'. `kind` says how the theme uses the value.
CREATE TABLE cms_field (
    page_id INTEGER NOT NULL REFERENCES cms_page (id) ON DELETE CASCADE,
    name    TEXT NOT NULL,
    kind    TEXT NOT NULL CHECK (kind IN ('html', 'flag', 'count', 'media', 'choice', 'link')),
    value   TEXT NOT NULL,
    PRIMARY KEY (page_id, name)
) STRICT, WITHOUT ROWID;

-- Head and footer values of one page: the Yoast title, description and schema, robots, and the scripts and
-- styles the page's plugins printed.
CREATE TABLE cms_page_meta (
    page_id INTEGER NOT NULL REFERENCES cms_page (id) ON DELETE CASCADE,
    name    TEXT NOT NULL,
    value   TEXT NOT NULL,
    PRIMARY KEY (page_id, name)
) STRICT, WITHOUT ROWID;

-- Site-wide content per language (ACF options): the header notice banner, the call-to-action block, footer texts.
CREATE TABLE cms_option (
    lang  TEXT NOT NULL CHECK (lang IN ('en', 'fr')),
    name  TEXT NOT NULL,
    kind  TEXT NOT NULL CHECK (kind IN ('html', 'flag', 'count', 'media', 'choice', 'link')),
    value TEXT NOT NULL,
    PRIMARY KEY (lang, name)
) STRICT, WITHOUT ROWID;

CREATE TABLE cms_menu_item (
    id        INTEGER PRIMARY KEY,
    menu      TEXT NOT NULL CHECK (menu IN ('main', 'footer')),
    lang      TEXT NOT NULL CHECK (lang IN ('en', 'fr')),
    parent_id INTEGER REFERENCES cms_menu_item (id) ON DELETE CASCADE,
    position  INTEGER NOT NULL,
    label     TEXT NOT NULL,
    url       TEXT NOT NULL,
    -- The page the item links to, for the current-menu-item classes. NULL for custom links.
    page_id   INTEGER,
    classes   TEXT NOT NULL DEFAULT '',
    target    TEXT NOT NULL DEFAULT ''
) STRICT;
CREATE INDEX cms_menu_item_menu ON cms_menu_item (menu, lang, position);

-- Uploaded images and files that fields and menus reference (WordPress attachments).
CREATE TABLE cms_media (
    id     INTEGER PRIMARY KEY,
    -- Path under wp-content/uploads, e.g. '2020/05/tools.png'.
    file   TEXT NOT NULL,
    mime   TEXT NOT NULL,
    width  INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    alt    TEXT NOT NULL DEFAULT '',
    -- JSON array of the generated sizes: [{"name": "medium", "file": "x-300x200.png", "width": 300, "height": 200}].
    sizes  TEXT NOT NULL DEFAULT '[]'
) STRICT;

CREATE TABLE cms_redirect (
    path   TEXT PRIMARY KEY,
    target TEXT NOT NULL,
    status INTEGER NOT NULL CHECK (status IN (301, 302, 307, 308))
) STRICT, WITHOUT ROWID;

-- Every save of a page's fields or of a language's options, as the full set of values after the save.
-- The newest id is also the content version: a renderer that has loaded an older one reloads.
CREATE TABLE cms_revision (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id  INTEGER REFERENCES cms_page (id) ON DELETE CASCADE,
    lang     TEXT CHECK (lang IN ('en', 'fr')),
    saved_at INTEGER NOT NULL,
    -- The user id of the editor; 0 for the importer.
    saved_by INTEGER NOT NULL,
    note     TEXT NOT NULL DEFAULT '',
    -- JSON object of name -> value.
    fields   TEXT NOT NULL,
    CHECK ((page_id IS NULL) <> (lang IS NULL))
) STRICT;
CREATE INDEX cms_revision_page ON cms_revision (page_id, id);
CREATE INDEX cms_revision_lang ON cms_revision (lang, id);
