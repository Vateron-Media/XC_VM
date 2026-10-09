-- The segment gateway (Phase 12): xc_fanout answers /hls/ and /key/ without
-- PHP-FPM once a node's mode lets it. off, the default: PHP serves them as
-- before. shadow: PHP still serves, and the gateway judges a mirrored copy.
ALTER TABLE `settings` ADD COLUMN IF NOT EXISTS `gateway_mode` varchar(32) DEFAULT 'off';
