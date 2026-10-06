-- Reverse 066_hold_unredeemed_activation_codes.sql. The older version starts a
-- code's term without switching its line on, and keeps the line of a code
-- nobody redeemed on: such a line goes back on, as that version creates it.
-- A suspended code's line stays off. A line or device paired with a line
-- switched on here follows when that line is next saved.
-- What each code had cost is not kept, so no purchase cost comes back.
-- A rollback also forgets the step: the older version generates codes the old
-- way again, and the next update applies it to them.
UPDATE `lines` l
INNER JOIN `activation_codes` c ON c.`subscriber_id` = l.`id`
SET l.`enabled` = 1
WHERE l.`is_activecode` = 1 AND l.`exp_date` IS NULL AND l.`enabled` = 0
  AND c.`activated_at` IS NULL AND c.`status` <> 0;
