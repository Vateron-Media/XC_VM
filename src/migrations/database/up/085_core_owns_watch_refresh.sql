-- The TMDb refresh queue moved from the watch module into core: core's VOD code
-- queues movies, series and episodes in it (MovieService, SeriesService,
-- StreamProcess, post.php) and cron:tmdb works it off, with or without the
-- module. Installs that had the module keep their table and queue.
-- No down file on purpose: the table may predate this migration (watch module),
-- so dropping it on rollback would destroy that install's queue.
CREATE TABLE IF NOT EXISTS `watch_refresh` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(1) DEFAULT '0',
  `stream_id` int(16) DEFAULT '0',
  `status` int(8) DEFAULT '0',
  `dateadded` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
