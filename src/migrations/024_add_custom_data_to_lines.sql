-- Migration 024: Add custom_data column to lines table
-- Supports category template customizations and active code subscriber pairing
ALTER TABLE `lines`
    ADD COLUMN IF NOT EXISTS `custom_data` mediumtext COLLATE utf8_unicode_ci DEFAULT NULL;
