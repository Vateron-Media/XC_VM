<?php

/**
 * UpdateLogger — dedicated log for system update operations.
 *
 * Writes to MAIN_HOME . 'update.log' (outside tmp/) so that cron
 * cleanup jobs never delete the file.
 *
 * Format: [2026-04-12 14:30:00] [INFO] Message text
 *
 * @package XC_VM_Core_Logging
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UpdateLogger {

	public static function getLogFile(): string {
		if (defined('MAIN_HOME')) {
			return MAIN_HOME . 'update.log';
		}
		return '/home/xc_vm/update.log';
	}

	public static function log(string $level, string $message): void {
		$rLine = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
		file_put_contents(self::getLogFile(), $rLine, FILE_APPEND | LOCK_EX);
	}

	public static function info(string $message): void {
		self::log('INFO', $message);
	}

	public static function error(string $message): void {
		self::log('ERROR', $message);
	}

	public static function reset(): void {
		file_put_contents(self::getLogFile(), '', LOCK_EX);
	}
}
