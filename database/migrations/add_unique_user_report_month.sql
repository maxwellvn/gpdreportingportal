ALTER TABLE reports
ADD UNIQUE KEY uniq_reports_user_month (user_id, report_month);
