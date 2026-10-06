-- What MAIN does with a node's connection record that does not prove MAIN
-- minted the viewer's token (ADR 0004, "The line a node names"): `observe`
-- counts it and stores it as before, `enforce` refuses it on a node whose
-- records prove their mints.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `cluster_conn_binding` varchar(8) DEFAULT 'observe' AFTER `cluster_kill_on_line_disable`;
