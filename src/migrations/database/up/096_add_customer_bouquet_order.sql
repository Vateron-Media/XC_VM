ALTER TABLE `lines` ADD COLUMN IF NOT EXISTS `bouquet_order_custom` TINYINT(1) NOT NULL DEFAULT 0;
UPDATE `lines` SET `bouquet_order_custom` = 1
WHERE CASE WHEN JSON_VALID(`custom_data`) THEN JSON_EXTRACT(`custom_data`, '$.bouquet_order') = TRUE ELSE FALSE END;
