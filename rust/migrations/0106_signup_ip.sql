-- The address a self-registered account asked from, for staff reviewing the request. Sign-in by company address
-- reads `ip` only, and only staff set that. Rows from before keep their `ip`: nothing tells a registration address
-- from one staff set.
ALTER TABLE "user" ADD COLUMN signup_ip TEXT;
