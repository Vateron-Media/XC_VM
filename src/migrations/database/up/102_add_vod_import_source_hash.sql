-- Stable original-source identity survives local download paths and title changes.
ALTER TABLE `streams`
  ADD COLUMN IF NOT EXISTS `import_source_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  ADD UNIQUE KEY IF NOT EXISTS `vod_import_source` (`type`, `import_source_hash`);
