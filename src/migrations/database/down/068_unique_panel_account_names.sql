-- Reverse 068_unique_panel_account_names.sql: the plain index the older
-- version has.
ALTER TABLE `users`
      DROP INDEX IF EXISTS `username`,
      ADD KEY `username` (`username`);
