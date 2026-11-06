-- The address an account signed up with, on the account itself, so it is removed with the account and a later
-- account never takes it over. Accounts from before take the address of their latest sign-up log row.
ALTER TABLE "user" ADD COLUMN signup_email TEXT;
UPDATE "user" SET signup_email = (
    SELECT lower(trim(email)) FROM log
    WHERE log.user_id = "user".id AND type = 'User' AND subtype = 'Signup' AND trim(coalesce(email, '')) <> ''
    ORDER BY date_time DESC, id DESC LIMIT 1
);
CREATE INDEX user_signup_email ON "user" (signup_email);
