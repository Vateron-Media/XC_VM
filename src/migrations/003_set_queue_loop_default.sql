ALTER TABLE `settings`
	MODIFY `queue_loop` int(11) DEFAULT '5';

UPDATE `settings`
SET `queue_loop` = 5
WHERE `queue_loop` IS NULL OR `queue_loop` IN (0, 1);
