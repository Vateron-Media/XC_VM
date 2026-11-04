ALTER TABLE `lines_live` ADD COLUMN IF NOT EXISTS `retire_token` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL;
CREATE TABLE IF NOT EXISTS `connection_retirements` (
 `id` bigint unsigned NOT NULL AUTO_INCREMENT,
 `server_id` int NOT NULL,
 `uuid` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `generation` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `date_start` int NOT NULL,
 `retired_at` int NOT NULL,
 `retire_token` char(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
 `command_id` char(32) DEFAULT NULL,
 `notify_done` tinyint NOT NULL DEFAULT 0,
 `retry_at` int NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 UNIQUE KEY `lifecycle` (`server_id`,`uuid`,`generation`),
 KEY `pending` (`notify_done`,`retry_at`,`id`),
 KEY `retired_done` (`notify_done`,`retired_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
