-- Streams: a per-stream "Maximum Resolution" for sources resolved through
-- yt-dlp (StreamUtils::parseStreamURL()). Its category is neither `fetch` nor
-- `transcode`, so it is never part of an ffmpeg command.
INSERT IGNORE INTO `streams_arguments` (`id`, `argument_cat`, `argument_name`, `argument_description`, `argument_wprotocol`, `argument_key`, `argument_cmd`, `argument_type`, `argument_default_value`) VALUES
(22, 'resolve', 'Maximum Resolution', 'For a source resolved through yt-dlp (YouTube, Twitch and the like): the tallest video to ask the platform for, in pixels (e.g. 720). Empty: the best it offers.', 'http', 'max_height', '', 'text', NULL);
