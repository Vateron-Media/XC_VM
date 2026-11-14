<?php

declare(strict_types=1);

namespace XcVm\Domain\Epg;

use RuntimeException;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Serializes EPG configuration validation and persistence across requests.
 *
 * @package XC_VM_Domain_Epg
 */
final class EpgConfiguration {
	use DatabaseAware;

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public static function locked(callable $operation): mixed {
		$db = self::db();
		$db->query('SELECT DATABASE();');
		$name = 'xcvm_epg_' . substr(hash('sha256', (string) $db->get_col()), 0, 32);
		if (!$db->query('SELECT GET_LOCK(?, 10);', $name) || (int) $db->get_col() !== 1) {
			throw new RuntimeException('EPG configuration is busy. Please retry.');
		}
		try {
			return $operation();
		} finally {
			$db->query('SELECT RELEASE_LOCK(?);', $name);
		}
	}
}
