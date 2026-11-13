<?php

declare(strict_types=1);

namespace XcVm\Domain\Epg;

use InvalidArgumentException;
use RuntimeException;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Materializes explicitly selected EPG entries using shared input groups.
 *
 * @package XC_VM_Domain_Epg
 */
final class EpgSelectionGuide {
	use DatabaseAware;

	public const PREFIX = '[Combined EPG] ';

	/**
	 * Only ordinary guides can supply explicit selections; input sets are stable.
	 *
	 * @param array $posted
	 * @param array $sources
	 * @return array
	 */
	public static function selected(array $posted, array $sources): array {
		$ids = array_values(array_unique(array_map('intval', $posted)));
		sort($ids);
		if (!$ids || count($ids) > 20) {
			throw new InvalidArgumentException('Select between one and twenty EPG sources.');
		}
		foreach ($ids as $id) {
			if (!isset($sources[$id]) || empty($sources[$id]['epg_file']) || EpgMerge::config($sources[$id])) {
				throw new InvalidArgumentException('Select ordinary XMLTV inputs.');
			}
		}
		return $ids;
	}

	/**
	 * One internal guide per input set, not one new guide per channel.
	 *
	 * @param array $ids
	 * @return int
	 */
	public static function group(array $ids): int {
		return EpgConfiguration::locked(static fn(): int => self::createGroup($ids));
	}

	/**
	 * Create or reuse the internal guide after revalidating its input set.
	 *
	 * @param array $ids
	 * @return int
	 */
	private static function createGroup(array $ids): int {
		$db = self::db();
		$db->query('SELECT * FROM `epg`;');
		$sources = array_column($db->get_raw_rows(), null, 'id');
		$ids = self::selected($ids, $sources);
		$name = self::PREFIX . substr(hash('sha256', implode(',', $ids)), 0, 20);
		if (!$db->query('SELECT * FROM `epg` WHERE `epg_name` = ? LIMIT 1;', $name)) {
			throw new RuntimeException('Cannot read selection EPG group.');
		}
		$old = $db->get_raw_row();
		if ($old) {
			$config = EpgMerge::config($old);
			if (empty($config['selection']) || $config['sources'] !== $ids) {
				throw new RuntimeException('Combined EPG group name is occupied.');
			}
			return (int) $old['id'];
		}
		$config = ['sources' => $ids, 'aliases' => [], 'selection' => true];
		if (!$db->query('INSERT INTO `epg` (`epg_name`,`epg_file`,`days_keep`,`merge_config`) VALUES(?,?,?,?);', $name, '', self::retention($ids, $sources), json_encode($config))) {
			throw new RuntimeException('Cannot create selection EPG group.');
		}
		return (int) $db->last_insert_id();
	}

	/**
	 * Return the stable per-stream materialization target.
	 *
	 * @param int $stream
	 * @return string
	 */
	public static function target(int $stream): string {
		return 'stream.' . $stream;
	}

	/**
	 * Resolve only persisted explicit entries; never match or select channel IDs.
	 *
	 * @param array $sources
	 * @param array $streams
	 * @return array
	 */
	public static function resolve(array $sources, array $streams): array {
		$selection = array_filter($sources, static fn($s) => !empty(EpgMerge::config($s)['selection']));
		if (!$selection) {
			return $sources;
		}
		foreach ($selection as $id => $source) {
			$config = EpgMerge::config($source);
			$config['bindings'] = [];
			$config['input_sources'] = [];
			$config['input_languages'] = [];
			$config['channel_names'] = [];
			foreach ($streams as $stream) {
				if ((int) $stream['epg_id'] !== (int) $id) {
					continue;
				}
				$target = self::target((int) $stream['id']);
				$picks = json_decode((string) ($stream['epg_bindings'] ?? ''), true) ?: [];
				if ($picks) {
					$config['bindings'][$target] = [];
					foreach ($picks as $position => $pick) {
						if (!in_array((int) $pick['source'], $config['sources'], true)) {
							continue;
						}
						$key = 'pick.' . $position;
						$config['bindings'][$target][$key] = $pick['channel'];
						$config['input_sources'][$target][$key] = (int) $pick['source'];
						$config['input_languages'][$target][$key] = (string) ($pick['lang'] ?? '');
					}
					$config['channel_names'][$target] = $stream['stream_display_name'];
				}
			}
			// Bindings are transient: persisted config stays small even for large channel lists.
			$sources[$id]['merge_config'] = json_encode($config);
			$sources[$id]['days_keep'] = self::retention($config['sources'], $sources);
		}
		return $sources;
	}

	/**
	 * Retain every input's configured history; zero means unbounded.
	 *
	 * @param array $ids
	 * @param array $sources
	 * @return int
	 */
	private static function retention(array $ids, array $sources): int {
		$days = array_map(static fn($id): int => (int) ($sources[$id]['days_keep'] ?? 7), $ids);
		return in_array(0, $days, true) ? 0 : max($days);
	}

	/**
	 * Remove only internal guides with no assigned stream, while configuration is locked.
	 *
	 * @return void
	 */
	public static function cleanupUnused(): void {
		$db = self::db();
		$db->query('SELECT e.* FROM `epg` e WHERE NOT EXISTS (SELECT 1 FROM `streams` s WHERE s.epg_id = e.id);');
		foreach ($db->get_raw_rows() as $source) {
			if (!empty(EpgMerge::config($source)['selection'])) {
				foreach (['epg_data', 'epg_channels', 'epg'] as $table) {
					$db->query('DELETE FROM `' . $table . '` WHERE `' . ($table === 'epg' ? 'id' : 'epg_id') . '` = ?;', $source['id']);
				}
			}
		}
	}
}
