-- What the outbox sender needs to deliver a message the way the PHP mailer did (HTML bodies, names, copies) and to
-- retry with backoff. A row is queued while sent_at and failed_at are both NULL; next_attempt_at doubles as the
-- lease a worker takes while it is sending, so a crashed send is retried after the lease runs out.

ALTER TABLE outbox ADD COLUMN to_name TEXT NOT NULL DEFAULT '';
ALTER TABLE outbox ADD COLUMN reply_to TEXT;
ALTER TABLE outbox ADD COLUMN content_type TEXT NOT NULL DEFAULT 'text' CHECK (content_type IN ('text', 'html'));
ALTER TABLE outbox ADD COLUMN attempts INTEGER NOT NULL DEFAULT 0;
ALTER TABLE outbox ADD COLUMN next_attempt_at INTEGER NOT NULL DEFAULT 0;
ALTER TABLE outbox ADD COLUMN last_error TEXT;
ALTER TABLE outbox ADD COLUMN failed_at INTEGER;

DROP INDEX outbox_unsent;
CREATE INDEX outbox_due ON outbox (next_attempt_at) WHERE sent_at IS NULL AND failed_at IS NULL;

-- Recipients beyond the first To address.
CREATE TABLE outbox_recipient (
    outbox_id INTEGER NOT NULL REFERENCES outbox (id) ON DELETE CASCADE,
    kind      TEXT NOT NULL CHECK (kind IN ('to', 'cc', 'bcc')),
    email     TEXT NOT NULL,
    name      TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (outbox_id, kind, email)
) STRICT;
