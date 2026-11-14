<?php

declare(strict_types=1);

namespace XcVm\Domain\Epg;

use InvalidArgumentException;

/**
 * Ordered, explicit EPG entries picked from the panel's own XMLTV sources.
 *
 * @package XC_VM_Domain_Epg
 */
final class EpgBindingSelection {
	/**
	 * Validate exact entries; do not silently replace an ID selected by the administrator.
	 *
	 * @param string $json
	 * @param array $sources
	 * @return array
	 */
	public static function validate(string $json, array $sources): array {
		if (strlen($json) > 16384) {
			throw new InvalidArgumentException('EPG selection is too large.');
		}
		$items = json_decode($json, true);
		if (!is_array($items) || !$items || count($items) > 20 || !array_is_list($items)) {
			throw new InvalidArgumentException('Select between one and twenty EPG entries.');
		}
		$result = [];
		$seen = [];
		foreach ($items as $item) {
			if (!is_array($item)) {
				throw new InvalidArgumentException('Invalid EPG entry.');
			}
			$id = (int) ($item['source'] ?? 0);
			$channel = (string) ($item['channel'] ?? '');
			$source = $sources[$id] ?? [];
			$catalog = json_decode((string) ($source['data'] ?? ''), true) ?: [];
			if (empty($source['epg_file']) || EpgMerge::config($source) || !isset($catalog[$channel]) || strlen($channel) > 50) {
				throw new InvalidArgumentException('The selected EPG entry is unavailable.');
			}
			$key = $id . ':' . $channel;
			if (isset($seen[$key])) {
				throw new InvalidArgumentException('Duplicate EPG entry.');
			}
			$seen[$key] = true;
			$langs = $catalog[$channel]['langs'] ?? [];
			$lang = (string) ($item['lang'] ?? ($langs[0] ?? ''));
			if ($lang !== '' && !in_array($lang, $langs, true)) {
				throw new InvalidArgumentException('Invalid EPG language.');
			}
			$result[] = ['source' => $id, 'channel' => $channel, 'lang' => $lang];
		}
		return $result;
	}

	/**
	 * Search all channel variants without excluding other countries from the result list.
	 *
	 * @param string $query
	 * @param array $sources
	 * @param int $onlySource
	 * @return array
	 */
	public static function search(string $query, array $sources, int $onlySource = 0): array {
		$profile = EpgSearchProfile::profile($query);
		$key = $profile['key'];
		$raw = mb_strtolower(trim($query), 'UTF-8');
		if ($raw === '' || strlen($raw) > 500) {
			return ['items' => [], 'more' => false];
		}
		$items = [];
		foreach ($sources as $id => $source) {
			if (empty($source['epg_file']) || EpgMerge::config($source) || ($onlySource && $onlySource !== (int) $id)) {
				continue;
			}
			foreach (json_decode((string) ($source['data'] ?? ''), true) ?: [] as $channel => $metadata) {
				if (strlen((string) $channel) > 50) {
					continue;
				}
				$name = (string) ($metadata['display_name'] ?? $channel);
				$p = EpgSearchProfile::profile($name);
				if ($p['country'] === '') {
					$p['country'] = EpgSearchProfile::profile((string) $channel)['country'];
				}
				$score = 0;
				if ($key !== '' && $key === $p['key']) {
					$score = 100;
				} elseif ($key !== '' && str_contains($p['key'], $key)) {
					$score = 70;
				} elseif (str_contains(mb_strtolower($name . ' ' . $channel, 'UTF-8'), $raw)) {
					$score = 60;
				} elseif ($key === '' && $profile['country'] !== '' && $profile['country'] === $p['country']) {
					$score = 50;
				}
				if (!$score) {
					continue;
				}
				if ($profile['country'] !== '' && $profile['country'] === $p['country']) {
					$score += 10;
				}
				$items[] = ['source' => (int) $id, 'source_name' => $source['epg_name'], 'channel' => (string) $channel, 'name' => $name, 'country' => $p['country'], 'lang' => $metadata['langs'][0] ?? '', 'score' => $score];
			}
		}
		usort($items, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['name'], $b['name']) ?: ($a['source'] <=> $b['source']) ?: strcmp($a['channel'], $b['channel']));
		return ['items' => array_slice($items, 0, 100), 'more' => count($items) > 100];
	}
}
