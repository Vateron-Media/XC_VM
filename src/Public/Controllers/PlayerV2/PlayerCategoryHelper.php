<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * PlayerCategoryHelper — Category Processing & Formatting Engine for Web Player V2.
 *
 * Honors Category Templates (custom renames, hidden categories, custom ordering)
 * and enriches category titles with distinctive styling (tags, emojis, contextual icons, stream counts).
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerCategoryHelper {
	/**
	 * Get processed, template-aware, distinctively formatted categories for a subscriber.
	 *
	 * @param array  $userInfo Authenticated user info (contains 'category_ids' and optionally 'custom_data')
	 * @param string $type     'live', 'movie', or 'series'
	 * @return array List of enriched category items
	 */
	public static function getCategories(array|string $userInfoOrType, array|string $typeOrUserInfo = 'movie'): array {
		if (is_string($userInfoOrType)) {
			$type = $userInfoOrType;
			$userInfo = is_array($typeOrUserInfo) ? $typeOrUserInfo : [];
		} else {
			$userInfo = $userInfoOrType;
			$type = is_string($typeOrUserInfo) ? $typeOrUserInfo : 'movie';
		}

		// Support External Xtream Codes Account Categories
		if (!empty($userInfo['is_external_xc'])) {
			$extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
			if ($extService instanceof \XcVm\Domain\External\ExternalXtreamService) {
				$rawCats = match ($type) {
					'live' => $extService->getLiveCategories(),
					'series' => $extService->getSeriesCategories(),
					default => $extService->getVodCategories(),
				};

				$formatted = [];
				foreach ($rawCats as $cat) {
					$catId = (int) $cat['id'];
					$rawName = $cat['category_name'] ?? ('Category #' . $catId);
					$parsed = self::parseCategoryDisplay($rawName, $type);
					$formatted[] = [
						'id' => $catId,
						'category_id' => $catId,
						'name' => $rawName,
						'clean_name' => $parsed['clean_name'],
						'emoji' => $parsed['emoji'],
						'tag' => $parsed['tag'],
						'color' => $parsed['color'],
						'icon' => $parsed['icon'],
						'count' => 0,
					];
				}
				return $formatted;
			}
		}

		$allowedIds = !empty($userInfo['category_ids']) ? array_map('intval', $userInfo['category_ids']) : [];

		// 1. Fetch raw categories from database
		$rawCategories = CategoryService::getFromDatabase($type);
		if (empty($rawCategories)) {
			return [];
		}

		// Check if allowedIds has any category matching this type
		$hasTypeInAllowed = false;
		if ($allowedIds !== []) {
			foreach ($rawCategories as $cat) {
				if (in_array((int) $cat['id'], $allowedIds, true)) {
					$hasTypeInAllowed = true;
					break;
				}
			}
		}

		// 2. Filter to allowed categories (or all if not explicitly restricted) and normalize
		$userCategories = [];
		foreach ($rawCategories as $cat) {
			$catId = (int) $cat['id'];
			if (!$hasTypeInAllowed || in_array($catId, $allowedIds, true)) {
				$rawName = $cat['category_name'] ?? 'Category #' . $catId;
				$userCategories[] = [
					'id'            => $catId,
					'category_id'   => $catId,
					'category_name' => $rawName,
					'original_name' => $rawName,
					'cat_order'     => (int) ($cat['cat_order'] ?? 0),
					'is_adult'      => (int) ($cat['is_adult'] ?? 0),
				];
			}
		}

		// 3. Apply Category Template if user has custom_data
		$section = match ($type) {
			'movie'  => 'vod_cat',
			'series' => 'series_cat',
			'radio'  => 'radio_cat',
			default  => 'live_cat',
		};

		$customData = $userInfo['custom_data'] ?? null;
		if (!empty($customData)) {
			$userCategories = CategoryTemplateService::applyCustomDataToCategories($userCategories, $customData, $section);
		}

		// 4. Calculate stream counts per category if stream IDs are present
		$streamCategoryMap = self::calculateStreamCounts($userInfo, $type);

		// 5. Enrich each category with parsed formatting, tags, emojis, and styling
		$finalCategories = [];
		foreach ($userCategories as $cat) {
			$name = (string) ($cat['category_name'] ?? $cat['title'] ?? '');
			$originalName = (string) ($cat['original_name'] ?? $name);
			$isCustom = ($name !== $originalName);

			$cid = (int) ($cat['id'] ?? $cat['category_id'] ?? 0);
			$parsed = self::parseCategoryDisplay($name, $type);

			$finalCategories[] = [
				'id'            => $cid,
				'category_id'   => $cid,
				'name'          => $name,
				'category_name' => $name,
				'title'         => $name,
				'original_name' => $originalName,
				'clean_name'    => $parsed['clean_name'],
				'tag'           => $parsed['tag'],
				'tag_color'     => $parsed['tag_color'],
				'emoji'         => $parsed['emoji'],
				'icon'          => $parsed['icon'],
				'is_custom'     => $isCustom,
				'stream_count'  => $streamCategoryMap[$cid] ?? null,
			];
		}

		return $finalCategories;
	}

	/**
	 * Resolve a single category name considering subscriber category template.
	 *
	 * @param int    $categoryId Category ID
	 * @param array  $userInfo   Authenticated user info
	 * @param string $type       'live', 'movie', 'series'
	 * @return string Display category name
	 */
	public static function resolveCategoryName(int $categoryId, array $userInfo, string $type = 'movie'): string {
		$all = self::getCategories($userInfo, $type);
		foreach ($all as $c) {
			if ((int) $c['id'] === $categoryId) {
				return $c['clean_name'] ?? $c['name'];
			}
		}

		$fromDb = CategoryService::getFromDatabase($type);
		return $fromDb[$categoryId]['category_name'] ?? 'Category #' . $categoryId;
	}

	/**
	 * Parse raw category string into emoji, tag prefix, clean title, and Sneat badge styling.
	 *
	 * @param string $name Raw category name
	 * @param string $type 'live', 'movie', 'series'
	 */
	public static function parseCategoryDisplay(string $name, string $type = 'movie'): array {
		$name = trim($name);
		$title = $name;
		$emoji = '';
		$tag = '';
		$color = 'primary';
		$icon = $type === 'live' ? 'bx bx-tv' : ($type === 'series' ? 'bx bx-movie-play' : ($type === 'radio' ? 'bx bx-broadcast' : 'bx bx-film'));

		// 1. Extract leading emoji or symbols
		if (preg_match('/^([\x{1F300}-\x{1F9FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F1E6}-\x{1F1FF}]{1,3})\s*(.*)$/u', $title, $m)) {
			$emoji = $m[1];
			$title = trim($m[2]);
		}

		// 2. Check for Pipe delimiter: 'PREFIX | TITLE'
		if (str_contains($title, '|')) {
			$parts = explode('|', $title, 2);
			$candidateTag = trim($parts[0], " \t\n\r\0\x0B[]():-★*");
			if (mb_strlen($candidateTag) >= 2 && mb_strlen($candidateTag) <= 15) {
				$tag = $candidateTag;
				$title = trim($parts[1], " \t\n\r\0\x0B★*");
			}
		} elseif (preg_match('/^[\[\(]([A-Za-z0-9\+\s]{2,10})[\]\)]\s*[-:\s]?\s*(.*)$/u', $title, $m)) {
			// 3. Check for [TAG], (TAG) at start
			$tag = trim($m[1]);
			$title = trim($m[2], " \t\n\r\0\x0B★*");
		} elseif (preg_match('/^([A-Za-z0-9\+]{2,8})\s*[:\-]\s*(.+)$/u', $title, $m)) {
			// 4. Check for 'TAG : TITLE' or 'TAG - TITLE'
			$tag = trim($m[1]);
			$title = trim($m[2], " \t\n\r\0\x0B★*");
		}

		// Determine badge color and contextual icon
		if ($tag) {
			$upperTag = strtoupper($tag);
			if (str_contains($upperTag, 'VIP') || str_contains($upperTag, 'PREMIUM') || str_contains($upperTag, 'EXCLUSIVE')) {
				$color = 'danger';
				$icon = 'bx bx-crown';
			} elseif (str_contains($upperTag, '4K') || str_contains($upperTag, 'UHD') || str_contains($upperTag, '8K') || str_contains($upperTag, 'HDR')) {
				$color = 'warning';
				$icon = 'bx bx-badge-check';
			} elseif (str_contains($upperTag, 'SPORT') || str_contains($upperTag, 'BEIN') || str_contains($upperTag, 'SSC') || str_contains($upperTag, 'ESPN')) {
				$color = 'danger';
				$icon = 'bx bx-football';
			} elseif (str_contains($upperTag, 'FHD') || str_contains($upperTag, 'HD') || str_contains($upperTag, 'HEVC')) {
				$color = 'success';
			} elseif (str_contains($upperTag, 'KIDS') || str_contains($upperTag, 'ANIM')) {
				$color = 'info';
				$icon = 'bx bx-happy-beaming';
			} elseif (str_contains($upperTag, 'DOC') || str_contains($upperTag, 'NEWS')) {
				$color = 'secondary';
				$icon = 'bx bx-news';
			} else {
				$color = 'primary';
			}
		}

		$cleanTitle = trim($title, " \t\n\r\0\x0B★*~-:");
		if (empty($cleanTitle)) {
			$cleanTitle = $name;
		}

		return [
			'raw_name'   => $name,
			'clean_name' => $cleanTitle,
			'tag'        => $tag ? strtoupper($tag) : null,
			'tag_color'  => $color,
			'emoji'      => $emoji,
			'icon'       => $icon,
		];
	}

	/**
	 * Calculate streams per category map for authorized subscriber streams.
	 *
	 * @return array<int, int> Map of [categoryId => streamCount]
	 */
	private static function calculateStreamCounts(array $userInfo, string $type): array {
		$streamIds = match ($type) {
			'live'   => $userInfo['live_ids'] ?? [],
			'movie'  => $userInfo['vod_ids'] ?? [],
			'series' => $userInfo['series_ids'] ?? [],
			'radio'  => $userInfo['radio_ids'] ?? [],
			default  => [],
		};

		try {
			$db = DatabaseFactory::get();
			$tableName = $type === 'series' ? 'streams_series' : 'streams';
			if (empty($streamIds) || !is_array($streamIds)) {
				if ($type === 'series') {
					$db->query("SELECT id, category_id FROM `streams_series` LIMIT 5000");
				} elseif ($type === 'movie') {
					$db->query("SELECT id, category_id FROM `streams` WHERE `type` = 2 LIMIT 5000");
				} elseif ($type === 'radio') {
					$db->query("SELECT id, category_id FROM `streams` WHERE `type` = 4 LIMIT 5000");
				} else {
					$db->query("SELECT id, category_id FROM `streams` WHERE `type` = 1 LIMIT 5000");
				}
			} else {
				$cleanIds = implode(',', array_slice(array_map('intval', $streamIds), 0, 5000));
				$db->query("SELECT id, category_id FROM `{$tableName}` WHERE `id` IN ({$cleanIds})");
			}
			$rows = $db->get_rows() ?: [];

			$map = [];
			foreach ($rows as $r) {
				$rawCat = $r['category_id'] ?? null;
				if (!$rawCat) {
					continue;
				}
				if (is_numeric($rawCat)) {
					$cid = (int) $rawCat;
					$map[$cid] = ($map[$cid] ?? 0) + 1;
				} else {
					$decoded = json_decode($rawCat, true);
					if (is_array($decoded)) {
						foreach ($decoded as $c) {
							$cid = (int) $c;
							$map[$cid] = ($map[$cid] ?? 0) + 1;
						}
					}
				}
			}
			return $map;
		} catch (\Throwable $e) {
			return [];
		}
	}
}
