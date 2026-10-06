-- A reseller is charged a package's or a group's price as it is stored, with
-- its fraction, and its balance keeps four decimals (UserCredits). The
-- reseller log kept the cost and the credits after as whole numbers, so 10.9
-- read as 11. A whole amount reads as it always did.
ALTER TABLE `users_logs`
      MODIFY `cost` double DEFAULT NULL,
      MODIFY `credits_after` double DEFAULT NULL;
