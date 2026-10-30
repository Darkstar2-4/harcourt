-- The display name on From. The address is always the configured sender; Gravity Forms notifications show the
-- visitor's name there, as WordPress did.
ALTER TABLE outbox ADD COLUMN from_name TEXT;
