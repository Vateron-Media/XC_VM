<?php

namespace XcVm\Core\Config;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Database\LazyDatabaseHandler;

/**
 * SettingsRepository — settings repository
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsRepository {
	/**
	 * Load all panel settings, decoding JSON fields and caching the result.
	 *
	 * On a node whose replica owns the settings cache (CONFIG on, and an apply
	 * built it from the `settings` and `secrets` sections: ReplicaApply::owns),
	 * every caller gets that cache however old it is, even when forced, never
	 * MAIN's database; should it be gone, it is rebuilt from the replica on
	 * disk. The database's row is never written over a cache the replica owns.
	 *
	 * A process booted from the replica (ReplicaBoot) does not read MAIN's
	 * database either: without a cache the replica owns, it gets the settings
	 * cache however old, or nothing (cluster:apply at boot, before it built
	 * one). The exception is a process on a node in mode 1
	 * (ReplicaBoot::hybrid), which reads MAIN's row as before once the
	 * replica no longer owns the cache (CONFIG went off, a refused section),
	 * on the lazy handle's first use, counted.
	 *
	 * A read the database did not answer (a MariaDB restart or OOM kill) never
	 * caches its empty row as the panel's settings. The caller gets the cache
	 * however old; with $rOrNull it gets null instead, and keeps the settings
	 * it holds (a daemon's refresh, DaemonTrait::refreshSettings()), and a
	 * handle not opened yet is opened without ending the process.
	 *
	 * @param bool $rForce  Bypass the file cache and re-read from the database.
	 * @param bool $rOrNull Answer null when the database did not answer, or a
	 *                      process booted from the replica has no cache left.
	 * @return array|null Settings map (with normalized array fields); null only with $rOrNull.
	 */
	public static function getAll(bool $rForce = false, bool $rOrNull = false) {
		global $db;
		$rReplica = self::replicaOwns();
		if (!$rForce || $rReplica) {
			$rCache = $rReplica ? FileCache::getCache('settings') : FileCache::getCache('settings', 20);
			if (empty($rCache) && $rReplica) {
				ReplicaApply::settings(true);
				$rCache = self::replicaOwns() ? FileCache::getCache('settings') : false;
			}
			if (!empty($rCache)) {
				return $rCache;
			}
		}
		if (ReplicaBoot::active() && !ReplicaBoot::hybrid()) {
			// No cache left to answer from, and no database to ask: nothing was
			// read, which a caller that asked for null is told.
			$rCached = ReplicaBoot::cached('settings');
			return $rOrNull && $rCached === [] ? null : $rCached;
		}

		// A lazy handle's first use connects, and ends the process when the
		// server is unreachable: a caller that asked for null gets it instead.
		if ($rOrNull && $db instanceof LazyDatabaseHandler && !$db->tryOpen()) {
			return null;
		}
		// The database did not answer: an empty row decoded as the panel's
		// settings was cached for every reader, and switched off whatever the
		// caller reads a setting for.
		if ($db->query('SELECT * FROM `settings`') === false) {
			if ($rOrNull) {
				return null;
			}
			$rCache = FileCache::getCache('settings');
			return !empty($rCache) ? $rCache : self::decode([]);
		}
		$rOutput = self::decode($db->get_row() ?: []);

		// Asked again: an apply may have built the replica's cache meanwhile.
		if (!self::replicaOwns()) {
			FileCache::setCache('settings', $rOutput);
		}

		return $rOutput;
	}

	/**
	 * The settings this process loaded (SettingsManager), or while it has not
	 * loaded them yet, the settings cache: the servers read to rule out MAIN
	 * (NodeFlows) can come first, and must not build their URLs without them.
	 *
	 * @return array<string, mixed>
	 */
	public static function loaded(): array {
		$rSettings = SettingsManager::getAll();
		if ($rSettings === []) {
			$rCache = FileCache::getCache('settings');
			$rSettings = is_array($rCache) ? $rCache : [];
		}
		return $rSettings;
	}

	/**
	 * Does the node replica own the settings cache? The apply's record first:
	 * without it (MAIN, a legacy node, CONFIG off) nothing asks NodeFlows,
	 * which on a node with an agent reads the servers, before the settings
	 * are loaded.
	 */
	private static function replicaOwns(): bool {
		return ReplicaApply::built(ReplicaSections::SETTINGS) && ReplicaApply::owns(ReplicaSections::SETTINGS);
	}

	/**
	 * The settings row as the panel reads it, with its array fields decoded.
	 * Shared with the node replica, whose `settings` section carries raw rows.
	 *
	 * @param array<string, mixed> $rRow the raw `settings` row
	 * @return array<string, mixed>
	 */
	public static function decode(array $rRow): array {
		$rOutput = $rRow;
		$rOutput['allow_countries'] = json_decode($rOutput['allow_countries'] ?? '', true);

		$decodedAllowedSTB = json_decode($rOutput['allowed_stb_types'] ?? '', true);
		$rOutput['allowed_stb_types'] = [];
		if (is_array($decodedAllowedSTB)) {
			// Drop blank entries so an "empty" selection (an unset multiselect is
			// commonly stored as [""]) collapses to a truly empty array. An empty
			// Allowed STB Types list means every STB type is accepted — see the
			// get_profile gate in Ministra/portal.php, which allows when this is empty.
			$rOutput['allowed_stb_types'] = array_values(array_filter(
				array_map(static fn ($rType) => strtolower(trim((string) $rType)), $decodedAllowedSTB),
				static fn ($rType) => $rType !== ''
			));
		}

		$rOutput['stalker_lock_images'] = json_decode($rOutput['stalker_lock_images'] ?? '', true);
		if (array_key_exists('bouquet_name', $rOutput)) {
			$rOutput['bouquet_name'] = str_replace(' ', '_', $rOutput['bouquet_name']);
		}
		$rOutput['api_ips'] = !empty($rOutput['api_ips']) ? explode(',', $rOutput['api_ips']) : [];

		$rDecodedPrefixes = json_decode($rOutput['shared_mount_prefixes'] ?? '', true);
		if (!is_array($rDecodedPrefixes)) {
			// Legacy CSV format from before this became a select2 tags field; self-heals to JSON on next save.
			$rDecodedPrefixes = !empty($rOutput['shared_mount_prefixes']) ? explode(',', $rOutput['shared_mount_prefixes']) : [];
		}
		$rOutput['shared_mount_prefixes'] = array_values(array_filter(array_map('trim', $rDecodedPrefixes)));

		return $rOutput;
	}
}
