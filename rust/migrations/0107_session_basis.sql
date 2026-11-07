-- A session keeps what let the browser in, so each use can ask again (session::Basis): an address at the domain of
-- the company signed in to, the address that asked for a pending account, or a remote grant and the Administrator
-- who minted it. Sessions from before carry none and end here; their carts go with them.
--
-- The table is altered in place rather than rebuilt: a fresh database runs this before 0311 adds its trigger on
-- `session`, a live one after. `via` has no valid default, so every insert names it.
DELETE FROM session;
ALTER TABLE session ADD COLUMN via TEXT NOT NULL DEFAULT '' CHECK (via IN ('domain', 'registration', 'grant'));
ALTER TABLE session ADD COLUMN granted_by INTEGER REFERENCES "user" (id) ON DELETE CASCADE
    CHECK ((via = 'grant') = (granted_by IS NOT NULL) AND (via = 'grant') = (email IS NULL));
CREATE INDEX session_user ON session (user_id);
CREATE INDEX session_granted_by ON session (granted_by);

-- A Denied account is signed out everywhere, whichever page or import denied it. Deleting one cascades.
CREATE TRIGGER user_denied_ends_sessions AFTER UPDATE OF status ON "user" WHEN NEW.status = 'Denied' BEGIN
    DELETE FROM session WHERE user_id = NEW.id;
END;
