-- =============================================================================
-- MIGRATION: Skip FFProbe Feature for Corrupted PMT Streams
-- =============================================================================
--
-- PROBLEM SOLVED:
-- Some MPEG-TS streams have corrupted PMT (Program Map Table) where ffprobe
-- incorrectly reports the audio codec as AC3 with sample_rate=0, channels=0
-- when the actual audio is AAC ADTS. This causes streams to fail to start.
--
-- SOLUTION:
-- 1. skip_ffprobe: Skip codec detection via ffprobe and assume h264/aac
-- 2. force_input_acodec: Force FFmpeg to interpret audio as a specific codec
--
-- ADDITIONAL REQUIREMENT:
-- Streams using skip_ffprobe MUST have enable_transcode=0 in the streams
-- table, as the transcoding path has a different flow.
--
-- APPLY WITH:
--   mysql -u root -p xc_vm < migrations/2024_skip_ffprobe_feature.sql
--
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. Add arguments to streams_arguments
-- -----------------------------------------------------------------------------

-- Check if arguments already exist before inserting
INSERT INTO streams_arguments (
    argument_cat,
    argument_name,
    argument_description,
    argument_wprotocol,
    argument_key,
    argument_cmd,
    argument_type,
    argument_default_value
)
SELECT
    'fetch',
    'Force Input Audio Codec',
    'Force FFmpeg to interpret the input audio stream as a specific codec (e.g., aac, ac3). Useful for streams with corrupted PMT metadata.',
    NULL,
    'force_input_acodec',
    '-acodec %s',
    'text',
    NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM streams_arguments WHERE argument_key = 'force_input_acodec'
);

INSERT INTO streams_arguments (
    argument_cat,
    argument_name,
    argument_description,
    argument_wprotocol,
    argument_key,
    argument_cmd,
    argument_type,
    argument_default_value
)
SELECT
    'fetch',
    'Skip FFProbe',
    'Skip codec detection via ffprobe. Assumes h264 video and aac audio. Use for streams with corrupted PMT where ffprobe misdetects codecs.',
    NULL,
    'skip_ffprobe',
    '',
    'radio',
    '0'
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM streams_arguments WHERE argument_key = 'skip_ffprobe'
);

-- -----------------------------------------------------------------------------
-- 2. Example: Apply to a specific stream (adjust IDs as needed)
-- -----------------------------------------------------------------------------

-- NOTE: The following commands are examples. Adjust stream_id according to your
-- configuration. The argument_id values are obtained from the previous step.

-- To get the IDs of the newly created arguments:
-- SELECT id, argument_key FROM streams_arguments WHERE argument_key IN ('skip_ffprobe', 'force_input_acodec');

-- Generic example to apply skip_ffprobe to a stream:
-- INSERT INTO streams_options (stream_id, argument_id, value)
-- VALUES (
--     [STREAM_ID],
--     (SELECT id FROM streams_arguments WHERE argument_key = 'skip_ffprobe'),
--     '1'
-- );

-- Generic example to apply force_input_acodec to a stream:
-- INSERT INTO streams_options (stream_id, argument_id, value)
-- VALUES (
--     [STREAM_ID],
--     (SELECT id FROM streams_arguments WHERE argument_key = 'force_input_acodec'),
--     'aac'
-- );

-- -----------------------------------------------------------------------------
-- 3. IMPORTANT: Disable transcoding for streams using skip_ffprobe
-- -----------------------------------------------------------------------------

-- If you already applied skip_ffprobe to specific streams, make sure they have
-- enable_transcode = 0. Example:
-- UPDATE streams SET enable_transcode = 0 WHERE id IN ([STREAM_IDS]);

-- =============================================================================
-- END OF MIGRATION
-- =============================================================================
