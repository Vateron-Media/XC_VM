CREATE TABLE IF NOT EXISTS `vod_import_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(1) NOT NULL,
  `server_id` int(11) NOT NULL,
  `filename` varchar(4096) NOT NULL,
  `title` varchar(1024) DEFAULT NULL,
  `status` int(1) NOT NULL,
  `stream_id` int(11) NOT NULL DEFAULT 0,
  `dateadded` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  KEY `type` (`type`),
  KEY `status` (`status`),
  KEY `dateadded` (`dateadded`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
