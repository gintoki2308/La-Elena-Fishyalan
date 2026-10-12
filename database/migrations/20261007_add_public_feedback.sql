-- Existing feedback starts hidden. Apply the consent migration before publishing customer reviews.
ALTER TABLE customer_feedback
    ADD COLUMN IF NOT EXISTS is_public TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_feedback_public_date
    ON customer_feedback (is_public, feedback_date, feedback_id);
