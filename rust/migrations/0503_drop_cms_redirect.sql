-- The redirect table was never filled by the import and never read when routing; WordPress's and WPML's redirects
-- are computed from the pages (routes/wordpress.rs).
DROP TABLE cms_redirect;
