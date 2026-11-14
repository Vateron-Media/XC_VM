<?php

declare(strict_types=1);

namespace XcVm\Domain\Epg;

use InvalidArgumentException;
use RuntimeException;
use XcVm\Infrastructure\Database\DatabaseAware;
use Throwable;

/**
 * Combines ordinary XMLTV sources in priority order, retaining complete programmes.
 *
 * @package XC_VM_Domain_Epg
 */
final class EpgMerge {
	use DatabaseAware;

	/**
	 * Decode persisted configuration; ordinary sources have none.
	 *
	 * @param array $source
	 * @return array
	 */
	public static function config(array $source): array {
		$config = json_decode((string) ($source['merge_config'] ?? ''), true) ?: [];
		// Earlier local builds stored explicit selections under this flag.
		if (!empty($config['automatic'])) {
			$config['selection'] = true;
			unset($config['automatic']);
		}
		return $config;
	}

	/**
	 * Validate ordered inputs and explicit channel aliases submitted by the admin.
	 *
	 * @param array $form
	 * @param array $sources
	 * @param int $id
	 * @return array
	 */
	public static function fromForm(array $form, array $sources, int $id): array {
		$ids = array_values(array_unique(array_map('intval', (array) ($form['merge_sources'] ?? []))));
		if (count($ids) < 2 || count($ids) > 20) {
			throw new InvalidArgumentException('Select between two and twenty sources.');
		}
		foreach ($ids as $input) {
			if ($input === $id || !isset($sources[$input]) || self::config($sources[$input]) || empty($sources[$input]['epg_file']) || $sources[$input]['epg_name'] === CreatedChannelEpg::SOURCE_NAME) {
				throw new InvalidArgumentException('Merge inputs must be ordinary XMLTV sources.');
			}
		}
		$order = (array) ($form['merge_order'] ?? []);
		usort($ids, static fn($a, $b) => ((int) ($order[$a] ?? 0) <=> (int) ($order[$b] ?? 0)) ?: ($a <=> $b));
		$aliases = [];
		foreach ((array) ($form['merge_aliases'] ?? []) as $row) {
			$target = trim((string) ($row['target'] ?? ''));
			$channel = trim((string) ($row['channel'] ?? ''));
			if ($target === '' && $channel === '') {
				continue;
			}
			$input = (int) ($row['source'] ?? 0);
			if (!in_array($input, $ids, true) || $target === '' || $channel === '' || strlen($target) > 200 || strlen($channel) > 200 || preg_match('/[\x00-\x1f]/', $target . $channel)) {
				throw new InvalidArgumentException('Invalid channel mapping.');
			}
			$catalog = json_decode((string) ($sources[$input]['data'] ?? ''), true) ?: [];
			if (!isset($catalog[$channel])) {
				throw new InvalidArgumentException('The mapped input channel is unavailable.');
			}
			$key = $target . "\0" . $input;
			if (isset($aliases[$key]) || count($aliases) >= 1000) {
				throw new InvalidArgumentException('Duplicate or excessive channel mappings.');
			}
			$aliases[$key] = ['target' => $target, 'source' => $input, 'channel' => $channel];
		}
		return ['sources' => $ids, 'aliases' => array_values($aliases)];
	}

	/**
	 * Short, stable IDs prevent collisions with the original guides in XMLTV exports.
	 *
	 * @param int $id
	 * @param string $target
	 * @return string
	 */
	public static function channelId(int $id, string $target): string {
		return 'm' . $id . '.' . substr(hash('sha256', $target), 0, 32);
	}

	/**
	 * Canonical targets and their input channel IDs, in source priority order.
	 *
	 * @param array $config
	 * @param array $sources
	 * @return array
	 */
	public static function bindings(array $config, array $sources): array {
		if (!empty($config['selection'])) {
			return $config['bindings'] ?? [];
		}
		$targets = [];
		foreach ($config['sources'] as $id) {
			foreach (json_decode((string) ($sources[$id]['data'] ?? ''), true) ?: [] as $channel => $metadata) {
				$targets[(string) $channel] = true;
			}
		}
		foreach ($config['aliases'] as $alias) {
			$targets[$alias['target']] = true;
		}
		$result = [];
		foreach ($targets as $target => $_) {
			foreach ($config['sources'] as $id) {
				$result[$target][$id] = (string) $target;
			}
		}
		foreach ($config['aliases'] as $alias) {
			$result[$alias['target']][$alias['source']] = $alias['channel'];
		}
		return $result;
	}

	/**
	 * Fill uncovered intervals; never clip a lower-priority programme into misleading fragments.
	 *
	 * @param array $feeds
	 * @return array
	 */
	public static function programmes(array $feeds): array {
		/** @var list<array> $accepted */
		$accepted = [];
		foreach ($feeds as $rows) {
			usort($rows, static fn($a, $b) => ((int) $a['start'] <=> (int) $b['start']) ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)));
			foreach ($rows as $row) {
				$start = (int) $row['start'];
				$end = (int) $row['end'];
				if ($start < 0 || $end <= $start || trim((string) $row['title']) === '') {
					continue;
				}
				$low = 0;
				$high = count($accepted);
				while ($low < $high) {
					$mid = intdiv($low + $high, 2);
					if ((int) $accepted[$mid]['start'] < $start) {
						$low = $mid + 1;
					} else {
						$high = $mid;
					}
				}
				if (($low > 0 && (int) $accepted[$low - 1]['end'] > $start) || (isset($accepted[$low]) && (int) $accepted[$low]['start'] < $end)) {
					continue;
				}
				array_splice($accepted, $low, 0, [$row]);
			}
		}
		return $accepted;
	}

	/**
	 * Required input channels for streams assigned to merged sources.
	 *
	 * @param array $sources
	 * @param array $streams
	 * @return array
	 */
	public static function dependencies(array $sources, array $streams): array {
		$result = [];
		$catalogs = self::catalogs($sources);
		foreach ($sources as $id => $source) {
			$config = self::config($source);
			if (!$config) {
				continue;
			}
			$wanted = [];
			foreach ($streams as $stream) {
				if ((int) $stream['epg_id'] === (int) $id) {
					$wanted[$stream['channel_id']] = true;
				}
			}
			foreach (self::bindings($config, $sources) as $target => $inputs) {
				if (!isset($wanted[self::channelId((int) $id, (string) $target)])) {
					continue;
				}
				foreach ($inputs as $input => $channel) {
					$lang = $config['input_languages'][$target][$input] ?? null;
					$input = $config['input_sources'][$target][$input] ?? $input;
					$metadata = $catalogs[$input] ?? [];
					$result[$input][$channel] = ['epg_lang' => $lang ?? ($metadata[$channel]['langs'][0] ?? '')];
				}
			}
		}
		return $result;
	}

	/**
	 * Atomically materialize a merged source so existing exports and player caches can use it.
	 *
	 * @param array $source
	 * @param array $sources
	 * @param array $streams
	 * @param array $failed
	 * @return void
	 */
	public static function rebuild(array $source, array $sources, array $streams, array $failed = []): void {
		EpgConfiguration::locked(static function () use ($source, $sources, $streams, $failed): void {
			self::rebuildLocked($source, $sources, $streams, $failed);
		});
	}

	/**
	 * Materialize one guide while its configuration remains unchanged.
	 *
	 * @param array $source
	 * @param array $sources
	 * @param array $streams
	 * @param array $failed
	 * @return void
	 */
	private static function rebuildLocked(array $source, array $sources, array $streams, array $failed): void {
		$db = self::db();
		$db->query('SELECT * FROM `epg` WHERE `id` = ?;', $source['id']);
		$current = $db->get_raw_row();
		if ($current === null || self::configurationKey(self::config($current)) !== self::configurationKey(self::config($source)) || (empty(self::config($source)['selection']) && (int) $current['days_keep'] !== (int) $source['days_keep'])) {
			throw new RuntimeException('Merge configuration changed; retry the latest configuration.');
		}
		$id = (int) $source['id'];
		$config = self::config($source);
		$catalog = [];
		$previousCatalog = json_decode((string) ($current['data'] ?? ''), true) ?: [];
		$catalogs = self::catalogs($sources);
		$coverage = [];
		$now = time();
		$cutoff = (int) $source['days_keep'] > 0 ? $now - (int) $source['days_keep'] * 86400 : 0;
		$mergeKey = self::configurationKey($config);
		$wanted = array_fill_keys(array_column(array_filter($streams, static fn($s) => (int) $s['epg_id'] === $id), 'channel_id'), true);
		$programmes = [];
		foreach (self::bindings($config, $sources) as $target => $inputs) {
			$key = self::channelId($id, (string) $target);
			$feeds = [];
			$inputIDs = [];
			foreach ($inputs as $input => $channel) {
				$input = $config['input_sources'][$target][$input] ?? $input;
				$inputIDs[] = (int) $input;
				$metadata = $catalogs[$input] ?? [];
				if (isset($metadata[$channel])) {
					$catalog[$key] ??= $metadata[$channel];
				}
				if (isset($wanted[$key])) {
					self::write('SELECT * FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = ? AND `end` > ? ORDER BY `start`, `id` DESC;', [$input, $channel, $cutoff]);
					$feeds[] = $db->get_raw_rows();
				}
			}
			$catalog[$key] ??= ['display_name' => (string) $target, 'langs' => []];
			if (isset($config['channel_names'][$target])) {
				$catalog[$key]['display_name'] = $config['channel_names'][$target];
			}
			if (!isset($wanted[$key])) {
				continue;
			}
			// A changed explicit selection must not inherit another channel's programmes.
			$catalog[$key]['merge_key'] = $mergeKey;
			$keepPrevious = ($previousCatalog[$key]['merge_key'] ?? null) === $mergeKey && array_intersect($inputIDs, $failed) !== [];
			if (!empty($config['selection'])) {
				$selectionKey = hash('sha256', json_encode([$inputs, $config['input_sources'][$target] ?? [], $config['input_languages'][$target] ?? []]));
				$catalog[$key]['selection_key'] = $selectionKey;
				$keepPrevious = $keepPrevious && ($previousCatalog[$key]['selection_key'] ?? null) === $selectionKey;
			}
			// Last successful merge is a final fallback during partial or complete outages.
			if ($keepPrevious) {
				self::write('SELECT * FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = ? AND `end` > ? ORDER BY `start`;', [$id, $key, $cutoff]);
				$feeds[] = $db->get_raw_rows();
			}
			$rows = self::programmes($feeds);
			$coverage[$key] = self::coverage($catalog[$key]['display_name'], $rows, $now);
			$programmes[$key] = $rows;
		}
		// A valid but incomplete input may omit a previously assigned channel entirely.
		foreach ($wanted as $key => $_) {
			if (isset($programmes[$key]) || !isset($previousCatalog[$key]) || ($previousCatalog[$key]['merge_key'] ?? null) !== $mergeKey || !empty($config['selection']) || array_intersect($config['sources'], $failed) === []) {
				continue;
			}
			self::write('SELECT * FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = ? AND `end` > ? ORDER BY `start`;', [$id, $key, $cutoff]);
			$rows = self::programmes([$db->get_raw_rows()]);
			$catalog[$key] = $previousCatalog[$key];
			$programmes[$key] = $rows;
			$coverage[$key] = self::coverage($catalog[$key]['display_name'], $rows, $now);
		}
		if (!$db->beginTransaction()) {
			throw new RuntimeException('Could not start EPG transaction.');
		}
		try {
			self::write('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', [$id]);
			foreach ($catalog as $key => $metadata) {
				self::write('INSERT INTO `epg_channels` (`epg_id`,`channel_id`,`name`,`langs`) VALUES(?,?,?,?);', [$id, $key, $metadata['display_name'], json_encode($metadata['langs'])]);
			}
			self::write('DELETE FROM `epg_data` WHERE `epg_id` = ?;', [$id]);
			foreach ($programmes as $key => $rows) {
				foreach ($rows as $row) {
					self::write('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`lang`,`title`,`description`) VALUES(?,?,?,?,?,?,?);', [$id, $key, $row['start'], $row['end'], $row['lang'], $row['title'], $row['description']]);
				}
			}
			$status = ['built_at' => $now, 'failed_sources' => array_values(array_intersect($config['sources'], $failed)), 'channels' => $coverage];
			self::write('UPDATE `epg` SET `data` = ?, `merge_status` = ?, `last_updated` = ? WHERE `id` = ?;', [json_encode($catalog), json_encode($status), $now, $id]);
			if (!$db->commit()) {
				throw new RuntimeException('Could not commit EPG transaction.');
			}
		} catch (Throwable $e) {
			$db->rollback();
			throw $e;
		}
	}

	/**
	 * Measure programme coverage for the next 24 hours.
	 *
	 * @param string $name
	 * @param array $rows
	 * @param int $now
	 * @return array
	 */
	private static function coverage(string $name, array $rows, int $now): array {
		$seconds = 0;
		$until = 0;
		foreach ($rows as $row) {
			$seconds += max(0, min($now + 86400, (int) $row['end']) - max($now, (int) $row['start']));
			$until = max($until, (int) $row['end']);
		}
		return ['name' => $name, 'percent' => round($seconds / 864, 1), 'until' => $until];
	}

	/**
	 * Decode each channel catalog once, rather than once per merged channel.
	 *
	 * @param array $sources
	 * @return array
	 */
	private static function catalogs(array $sources): array {
		$result = [];
		foreach ($sources as $id => $source) {
			$result[$id] = json_decode((string) ($source['data'] ?? ''), true) ?: [];
		}
		return $result;
	}

	/**
	 * Stable persisted input/mapping identity, independent of transient bindings.
	 *
	 * @param array $config
	 * @return string
	 */
	private static function configurationKey(array $config): string {
		return hash('sha256', json_encode([$config['sources'] ?? [], $config['aliases'] ?? [], !empty($config['selection'])]));
	}

	/**
	 * Run a checked statement on the shared database connection.
	 *
	 * @param string $sql
	 * @param array $parameters
	 * @return void
	 */
	private static function write(string $sql, array $parameters): void {
		$db = self::db();
		if (!$db->query($sql, ...$parameters)) {
			throw new RuntimeException('Could not save merged EPG.');
		}
	}
}
