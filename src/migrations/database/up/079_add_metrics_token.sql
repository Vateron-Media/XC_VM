-- The bearer token /metrics answers to (Public/Controllers/Api/MetricsController);
-- empty, /metrics answers 404.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `metrics_token` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL;
