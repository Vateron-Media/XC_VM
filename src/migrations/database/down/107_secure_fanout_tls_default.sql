ALTER TABLE `settings` MODIFY COLUMN `fanout_source_insecure` tinyint(1) DEFAULT '1';
UPDATE `settings` SET `fanout_source_insecure` = 1;
