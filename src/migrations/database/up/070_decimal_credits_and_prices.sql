-- Balances and prices are kept exactly, at four decimals: a FLOAT held a
-- whole amount exactly only up to 16,777,216 and a fraction never (10.9 was
-- 10.8999996). Each value is converted to the nearest four-decimal value
-- (10.8999996 becomes 10.9000): the conversion reads the FLOAT as a double
-- and rounds it half up to the column's four decimals.
ALTER TABLE `users`
      MODIFY `credits` decimal(16,4) DEFAULT '0.0000';
ALTER TABLE `users_credits_logs`
      MODIFY `amount` decimal(16,4) DEFAULT NULL;
ALTER TABLE `users_groups`
      MODIFY `create_sub_resellers_price` decimal(16,4) DEFAULT '0.0000';
ALTER TABLE `users_packages`
      MODIFY `trial_credits` decimal(16,4) DEFAULT '0.0000',
      MODIFY `official_credits` decimal(16,4) DEFAULT '0.0000';
-- What an activation code was paid for is what deleting it unused refunds:
-- at two decimals a price of 10.1234 refunded 10.12.
ALTER TABLE `activation_codes`
      MODIFY `purchase_cost` decimal(16,4) NOT NULL DEFAULT '0.0000';
