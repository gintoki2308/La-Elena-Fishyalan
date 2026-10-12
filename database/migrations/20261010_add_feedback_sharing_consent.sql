-- Customer consent is separate from the administrator's display setting.
-- Legacy visibility cannot prove consent because admins could also enable it.
-- Keep legacy reviews hidden; newly submitted shared reviews record consent.
ALTER TABLE customer_feedback
    ADD COLUMN IF NOT EXISTS customer_shared TINYINT(1) NOT NULL DEFAULT 0;

-- Safe to rerun: consented reviews retain their current display setting.
UPDATE customer_feedback SET is_public = 0 WHERE customer_shared <> 1;
