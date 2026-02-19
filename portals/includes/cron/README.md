# cron/README.md

## Cron Jobs for Impact Digital Academy

### Required Cron Jobs Setup

Add these entries to your server's crontab (crontab -e):

```bash
# ===========================================
# FINANCIAL SYSTEM CRON JOBS
# ===========================================

# Daily at 2 AM - Check overdue payments and apply late fees
0 2 * * * php /path/to/impact-digital-academy/cron/check_overdue_payments.php >> /path/to/logs/cron_finance.log 2>&1

# Daily at 9 AM - Send payment reminders
0 9 * * * php /path/to/impact-digital-academy/cron/send_payment_reminders.php >> /path/to/logs/cron_reminders.log 2>&1

# Monthly on 1st at 6 AM - Generate invoices
0 6 1 * * php /path/to/impact-digital-academy/cron/generate_invoices.php >> /path/to/logs/cron_invoices.log 2>&1

# Monthly on 15th at 3 AM - Check block progression
0 3 15 * * php /path/to/impact-digital-academy/cron/block_progression_check.php >> /path/to/logs/cron_progression.log 2>&1

# ===========================================
# SYSTEM MAINTENANCE CRON JOBS
# ===========================================

# Daily at 3 AM - Update academic period statuses (EXISTING - KEEP)
0 3 * * * php /path/to/impact-digital-academy/cron/update_period_status.php >> /path/to/logs/cron_periods.log 2>&1

# Weekly on Sunday at 4 AM - Clean temporary files
0 4 * * 0 php /path/to/impact-digital-academy/cron/cleanup_temporary_files.php >> /path/to/logs/cron_cleanup.log 2>&1

# Daily at 1 AM - Database backup
0 1 * * * php /path/to/impact-digital-academy/cron/backup_database.php >> /path/to/logs/cron_backup.log 2>&1

# ===========================================
# TESTING (Run every minute for debugging)
# ===========================================
# * * * * * php /path/to/impact-digital-academy/cron/check_overdue_payments.php >> /path/to/logs/cron_test.log 2>&1