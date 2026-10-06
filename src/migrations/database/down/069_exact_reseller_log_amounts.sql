-- Reverse 069_exact_reseller_log_amounts.sql. An amount with a fraction is
-- rounded to the whole number the older version shows.
ALTER TABLE `users_logs`
      MODIFY `cost` int(16) DEFAULT NULL,
      MODIFY `credits_after` int(16) DEFAULT NULL;
