<?php

namespace XcVm\Domain\Epg;

use InvalidArgumentException;
use RuntimeException;

/** Materializes explicitly selected EPG entries using shared input groups. */
final class EpgSelectionGuide {
	public const PREFIX = '[Combined EPG] ';

	/** Only ordinary guides can supply explicit selections; input sets are stable. */
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

	/** One internal guide per input set, not one new guide per channel. */
	public static function group(object $db, array $ids): int {
		$name = self::PREFIX . substr(hash('sha256', implode(',', $ids)), 0, 20);
		if (!$db->query('SELECT * FROM `epg` WHERE `epg_name` = ? LIMIT 1', $name)) {
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
		if (!$db->query('INSERT INTO `epg` (`epg_name`,`epg_file`,`days_keep`,`merge_config`) VALUES(?,?,7,?)', $name, '', json_encode($config))) {
			throw new RuntimeException('Cannot create selection EPG group.');
		}
		return (int) $db->last_insert_id();
	}

	public static function target(int $stream): string {
		return 'stream.' . $stream;
	}

	/** Resolve only persisted explicit entries; never match or select channel IDs. */
	public static function resolve(array $sources, array $streams): array {
		$selection = array_filter($sources, static fn($s) => !empty(EpgMerge::config($s)['selection']));
		if (!$selection) {
			return $sources;
		}
		foreach ($selection as $id => $source) {
			$config = EpgMerge::config($source);
			$config['bindings'] = [];
			$config['input_sources'] = [];
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
					}
					$config['channel_names'][$target] = $stream['stream_display_name'];
				}
			}
			// Bindings are transient: persisted config stays small even for large channel lists.
			$sources[$id]['merge_config'] = json_encode($config);
		}
		return $sources;
	}
}
