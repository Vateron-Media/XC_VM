ALTER TABLE `settings` MODIFY COLUMN `fanout_source_insecure` tinyint(1) DEFAULT '0';
UPDATE `settings` SET `fanout_source_insecure` = 0;
