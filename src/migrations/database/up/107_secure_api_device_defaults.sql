-- Preserve explicit upgrade choices, but make new keys and devices fail closed.
ALTER TABLE `settings` ALTER COLUMN `api_legacy_keys` SET DEFAULT 0;
ALTER TABLE `mag_devices` ALTER COLUMN `lock_device` SET DEFAULT 1;
ALTER TABLE `enigma2_devices` ALTER COLUMN `lock_device` SET DEFAULT 1;
