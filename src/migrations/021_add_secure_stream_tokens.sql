-- Stream-link tokens sealed with AES-256-GCM (Encryption::seal) instead of the
-- legacy AES-CBC format, which can be read or forged through padding errors.
-- 1 = mint sealed tokens and refuse legacy ones wherever the server trusts a
-- token's contents as they stand. Servers on an older version cannot read sealed
-- tokens, so a panel with other servers starts at 0: turn it on in Settings once
-- every server has been updated.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `secure_stream_tokens` tinyint(1) DEFAULT '1' AFTER `fanout_supervise`;
UPDATE `settings` SET `secure_stream_tokens` = 0 WHERE EXISTS (SELECT 1 FROM `servers` WHERE `is_main` = 0);
