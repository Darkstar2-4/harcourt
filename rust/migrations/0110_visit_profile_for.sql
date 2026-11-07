-- Whom the visit's profile was admitted or kept for: the account id, NULL for a visitor not signed in. A request
-- uses the visit's profile only when it comes as that account (or as no account); otherwise it reads the
-- harcourt_profile cookie, which reached the browser with the sign-in it belongs to.
ALTER TABLE visit ADD COLUMN profile_for INTEGER;
