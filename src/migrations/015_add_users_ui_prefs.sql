-- Per-user admin UI customizer settings (Bootstrap 5 template customizer state).
-- Stored as a JSON blob and served back to the shell so the panel opens in the
-- user's own configuration; written by the save_ui_prefs admin API action.
ALTER TABLE `users`
      ADD COLUMN IF NOT EXISTS `ui_prefs` text COLLATE utf8_unicode_ci DEFAULT NULL AFTER `theme`;
