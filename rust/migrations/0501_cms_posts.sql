-- WordPress's other public post types (blog posts, FAQs, case studies, testimonials) live with the pages: each has
-- a path, translations, a title and content the same way. `post_type` is WordPress's name for the type.
ALTER TABLE cms_page ADD COLUMN post_type TEXT NOT NULL DEFAULT 'page'
    CHECK (post_type IN ('page', 'post', 'faq', 'case_study', 'testimonials'));
-- The slug (post_name). The path ends with it, except the front page's ('/') and a draft's (?page_id=N).
ALTER TABLE cms_page ADD COLUMN name TEXT NOT NULL DEFAULT '';

-- Attachments as WordPress looks them up by name: the slug, the post they are attached to (their URL is under
-- its URL), and their WPML language and translation group.
ALTER TABLE cms_media ADD COLUMN name TEXT NOT NULL DEFAULT '';
ALTER TABLE cms_media ADD COLUMN parent_id INTEGER;
ALTER TABLE cms_media ADD COLUMN lang TEXT CHECK (lang IN ('en', 'fr'));
ALTER TABLE cms_media ADD COLUMN group_id INTEGER;
