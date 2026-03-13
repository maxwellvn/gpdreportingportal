-- Add total_copies column to reports table if it doesn't exist
ALTER TABLE reports
ADD COLUMN IF NOT EXISTS total_copies INT NOT NULL DEFAULT 0 COMMENT 'Total copies overall' AFTER report_month; 