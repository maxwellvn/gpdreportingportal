SELECT
    user_id,
    report_month,
    COUNT(*) AS duplicate_count
FROM reports
GROUP BY user_id, report_month
HAVING COUNT(*) > 1;
