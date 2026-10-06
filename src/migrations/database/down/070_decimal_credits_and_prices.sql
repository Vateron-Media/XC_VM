-- Reverse 070_decimal_credits_and_prices.sql. Amounts go back to the types
-- the older version reads: a FLOAT keeps about seven significant digits, a
-- code's price two decimals.
ALTER TABLE `users`
      MODIFY `credits` float DEFAULT '0';
ALTER TABLE `users_credits_logs`
      MODIFY `amount` float DEFAULT NULL;
ALTER TABLE `users_groups`
      MODIFY `create_sub_resellers_price` float DEFAULT '0';
ALTER TABLE `users_packages`
      MODIFY `trial_credits` float DEFAULT '0',
      MODIFY `official_credits` float DEFAULT '0';
ALTER TABLE `activation_codes`
      MODIFY `purchase_cost` decimal(10,2) NOT NULL DEFAULT '0.00';
