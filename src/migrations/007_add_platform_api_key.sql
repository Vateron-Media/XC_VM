-- Add `platform_api_key` to settings: the shared XC_VM platform (SaaS) API key.
-- Used by MAIN and all LB servers to authenticate against platform.xcvm.io when
-- downloading store modules. Each server still registers its own install_id.
ALTER TABLE `settings`
  ADD COLUMN IF NOT EXISTS `platform_api_key` VARCHAR(128) DEFAULT NULL AFTER `live_streaming_pass`;
