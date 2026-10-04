-- Mass Edit used to insert an empty selected id as `(0, server_id, ...)` (#156).
-- The leftover row makes every later grouped INSERT toward that server fail on
-- the (stream_id, server_id) unique key, so mass edits silently wrote nothing.
DELETE FROM `streams_servers` WHERE `stream_id` = 0;
