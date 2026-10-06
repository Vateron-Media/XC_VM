-- No two panel accounts (administrators, resellers) share a username. The
-- column's collation compares without case and trailing spaces, so 'Admin'
-- and 'admin ' are one name. Accounts without a name (NULL) are not counted.
--
-- A panel that has names used more than once keeps working as before: this
-- step fails, changes nothing and runs again on the next update. The update
-- prints every such name (the panel log leaves duplicate errors out); rename
-- all accounts of a name but one, then update again.
--
-- The message is raised by a procedure: SIGNAL cannot run as a prepared
-- statement before MariaDB 10.6.2, a CALL can.
SET @migration_068_names = (
    SELECT CONCAT('Panel account names used more than once, rename all but one and update again: ', GROUP_CONCAT(QUOTE(`d`.`name`) ORDER BY `d`.`name` SEPARATOR ', '))
    FROM (SELECT MIN(`username`) AS `name` FROM `users` WHERE `username` IS NOT NULL GROUP BY `username` HAVING COUNT(*) > 1) AS `d`
);
DROP PROCEDURE IF EXISTS `migration_068_refuse`;
CREATE PROCEDURE `migration_068_refuse`(IN `rMessage` varchar(512)) SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = `rMessage`;
EXECUTE IMMEDIATE IF(@migration_068_names IS NULL, 'DO 0', 'CALL `migration_068_refuse`(LEFT(@migration_068_names, 500))');
DROP PROCEDURE IF EXISTS `migration_068_refuse`;

ALTER TABLE `users`
      DROP INDEX IF EXISTS `username`,
      ADD UNIQUE KEY `username` (`username`);
