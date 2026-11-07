-- The language a flag click chose this visit, which the visit's catalog pages keep to while the account is being
-- given it ($_SESSION['user']->language_code as PHP left it after a flag click had saved it to the account).
ALTER TABLE visit ADD COLUMN flag_language TEXT;
