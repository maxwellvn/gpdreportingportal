-- Add month column to reports table
ALTER TABLE reports
ADD COLUMN report_month DATE DEFAULT NULL AFTER user_id;
