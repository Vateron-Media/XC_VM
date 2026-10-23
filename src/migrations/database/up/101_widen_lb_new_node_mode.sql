-- The lb_new_node_mode enum gained a third value, `serve_only` (10 chars), which
-- did not fit the original varchar(8): a strict-SQL save was rejected and a
-- non-strict one truncated it, so the mode could never be stored. Widen it.
ALTER TABLE `settings`
  MODIFY COLUMN `lb_new_node_mode` varchar(16) DEFAULT 'legacy';
