ALTER TABLE `settings`
  DROP COLUMN IF EXISTS `dashboard_alerts`,
  DROP COLUMN IF EXISTS `dashboard_alerts_count`,
  DROP COLUMN IF EXISTS `dashboard_alert_history`,
  DROP COLUMN IF EXISTS `dashboard_alert_history_count`;
