-- What a visitor last typed into a sign-up form, as the JSON of the harcourt_profile cookie. It fills that form
-- again for the rest of the visit, and its names go to the person when the address is admitted; until then the
-- visit's profile stays whoever was last admitted.
ALTER TABLE visit ADD COLUMN typed_profile TEXT;
