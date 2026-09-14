-- Disable DataTables Responsive (column collapsing) panel-wide. When 1, admin and
-- reseller tables no longer fold overflowing columns into an expandable child row
-- on narrow screens — the .table-responsive wrapper shows a horizontal scrollbar
-- instead. 0 (default) keeps the responsive collapse behaviour.
ALTER TABLE `settings`
      ADD COLUMN IF NOT EXISTS `disable_table_responsive` tinyint(1) DEFAULT '0' AFTER `js_navigate`;
