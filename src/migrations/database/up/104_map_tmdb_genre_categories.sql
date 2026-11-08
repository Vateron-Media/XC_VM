-- Backfill genre mappings for categories imported before addCategories()
-- started linking them. Keep every explicit administrator mapping.
UPDATE `watch_categories` AS `wc`
INNER JOIN `streams_categories` AS `sc`
  ON `sc`.`category_name` = `wc`.`genre`
 AND `sc`.`category_type` = CASE `wc`.`type`
   WHEN 1 THEN 'movie'
   WHEN 2 THEN 'series'
 END
SET `wc`.`category_id` = `sc`.`id`
WHERE `wc`.`type` IN (1, 2)
  AND `wc`.`category_id` = 0;
