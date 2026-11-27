ALTER TABLE `vod_import_logs`
  ADD COLUMN `payload` longtext COLLATE utf8_unicode_ci DEFAULT NULL AFTER `stream_id`;
