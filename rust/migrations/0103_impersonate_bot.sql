-- bot_impersonate.php: an Administrator's session may view the site as one of the known bots.
ALTER TABLE impersonation ADD COLUMN bot_id INTEGER REFERENCES bot (id) ON DELETE CASCADE;
