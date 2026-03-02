<?php

/**
 * StreamConfigRepository — аргументы потоков и профили транскодирования (§7.3).
 *
 * Извлечено из admin_proxies.php (getStreamArguments / getTranscodeProfiles).
 */
class StreamConfigRepository {
	/**
	 * Получить все аргументы потоков (streams_arguments), индексированные по argument_key.
	 */
	public static function getStreamArguments() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `streams_arguments` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['argument_key']] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Получить все профили транскодирования (profiles).
	 */
	public static function getTranscodeProfiles() {
		global $db;
		$rReturn = array();
		$db->query('SELECT * FROM `profiles` ORDER BY `profile_id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}
}
