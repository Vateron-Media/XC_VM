<?php

namespace XcVm\Core\Config;

/**
 * Maintenance mode (Settings → General): while it is on, the client APIs
 * (player_api, playlists, XMLTV, Enigma2, the Ministra portal) answer a
 * maintenance message and resellers cannot use their panel or REST API;
 * admins work as usual and viewers already watching keep watching. It ends by
 * itself at `maintenance_until` when one is set. The settings reach load
 * balancers with the node replica, which serve the client APIs too.
 *
 * @package XC_VM_Core_Config
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class Maintenance {
	/** @param array<string, mixed> $rSettings */
	public static function active(array $rSettings, ?int $rNow = null): bool {
		if (empty($rSettings['maintenance_mode'])) {
			return false;
		}
		$rUntil = (int) ($rSettings['maintenance_until'] ?? 0);
		return $rUntil <= 0 || ($rNow ?? time()) < $rUntil;
	}

	/** @param array<string, mixed> $rSettings */
	public static function message(array $rSettings): string {
		$rText = trim((string) ($rSettings['maintenance_message'] ?? ''));
		return $rText !== '' ? $rText : 'The service is under maintenance. Please try again later.';
	}

	/** Seconds until it ends, for Retry-After; null without an end time. */
	public static function retryAfter(array $rSettings, ?int $rNow = null): ?int {
		$rUntil = (int) ($rSettings['maintenance_until'] ?? 0);
		return $rUntil > 0 ? max(60, $rUntil - ($rNow ?? time())) : null;
	}

	/**
	 * Answer 503 with the message and stop: a player shows an error and
	 * retries, and Retry-After says when.
	 *
	 * @param array<string, mixed> $rSettings
	 */
	public static function refuse(array $rSettings): never {
		http_response_code(503);
		if (($rRetry = self::retryAfter($rSettings)) !== null) {
			header('Retry-After: ' . $rRetry);
		}
		header('Content-Type: text/plain; charset=utf-8');
		echo self::message($rSettings);
		exit();
	}
}
