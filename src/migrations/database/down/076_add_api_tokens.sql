-- Reverse 076_add_api_tokens.sql.
DROP TABLE IF EXISTS `api_tokens`;
ALTER TABLE `settings` DROP COLUMN IF EXISTS `api_legacy_keys`;
