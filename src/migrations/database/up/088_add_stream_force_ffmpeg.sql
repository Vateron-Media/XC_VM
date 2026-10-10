-- Native remuxer (Phase 13): keep one copy-only channel on ffmpeg while the
-- node's source backend (auto or native) runs the others on `xc_fanout remux`.
ALTER TABLE `streams` ADD COLUMN IF NOT EXISTS `force_ffmpeg` tinyint(1) DEFAULT '0';
