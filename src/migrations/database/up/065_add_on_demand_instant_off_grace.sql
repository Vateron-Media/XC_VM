-- How long a freshly started on-demand stream is kept before Instant Off may
-- stop it for having no viewers (#270). Was a hardcoded 30 seconds.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `on_demand_instant_off_grace` int(11) DEFAULT 30 AFTER `on_demand_instant_off`;
