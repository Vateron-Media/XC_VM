-- Rename the "unstable" update channel to "beta". The stored value is now
-- 'beta' everywhere (settings UI, GitHubReleases, binary/fanout update commands,
-- module channel logic). 'unstable' stays accepted as a legacy alias in code
-- (GitHubReleases normalizes it), but migrate existing rows so the settings
-- dropdown reflects the current selection after the rename.
UPDATE `settings`
SET `update_channel` = 'beta'
WHERE `update_channel` = 'unstable';
