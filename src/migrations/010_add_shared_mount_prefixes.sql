ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `shared_mount_prefixes` mediumtext COLLATE utf8_unicode_ci AFTER `api_ips`;
UPDATE `settings` SET `shared_mount_prefixes` = '/opt/arr/media/' WHERE `shared_mount_prefixes` IS NULL OR `shared_mount_prefixes` = '';