<?php

namespace XcVm\Domain\Stream;

use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\UserRepository;

/**
 * CategoryTemplateService — Category Templates Business Logic.
 *
 * Provides full multi-tenancy RBAC business logic for category templates:
 * - Admin: manage all templates, define global system templates, and filter by owner.
 * - Reseller: manage own templates, clone admin templates, share with sub-resellers.
 * - Sub-reseller: use parent shared templates and global system templates.
 * - Instant application to lines and devices in XC standard custom_data JSON format.
 *
 * @package XC_VM_Domain_Stream
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CategoryTemplateService {
	use DatabaseAware;

	/**
	 * Get accessible category templates for a user based on RBAC permissions.
	 *
	 * @param array|int|null $user          Current user data or user ID
	 * @param bool           $isAdmin       Whether the user is an admin
	 * @param int|null       $filterOwnerId Filter by owner ID (admin only)
	 * @param string|null    $search        Search keyword in template name
	 * @return array List of templates
	 */
	public static function getTemplatesForUser($user = null, bool $isAdmin = false, ?int $filterOwnerId = null, ?string $search = null): array {
		$db = self::db();
		if (is_numeric($user)) {
			$userId = (int) $user;
			$user = ['id' => $userId];
		} elseif (is_array($user)) {
			$userId = (int) ($user['id'] ?? 0);
		} else {
			$user = $GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []);
			$userId = (int) ($user['id'] ?? 0);
		}
		$parentOwnerId = (int) ($user['owner_id'] ?? 0);

		$params = [];
		$where = [];

		if ($isAdmin) {
			if ($filterOwnerId !== null && $filterOwnerId > 0) {
				$where[] = "t.owner_id = ?";
				$params[] = $filterOwnerId;
			}
			$subResellerIds = [];
		} else {
			$orConditions = [];

			// 1. Current user's own templates
			$orConditions[] = "t.owner_id = ?";
			$params[] = $userId;

			// 2. All sub-resellers' templates (all recursive generations)
			$subUsers = UserRepository::getSubUsers($userId);
			$subResellerIds = !empty($subUsers) ? array_map('intval', array_keys($subUsers)) : [];
			if ($subResellerIds !== []) {
				$orConditions[] = "t.owner_id IN (" . implode(',', $subResellerIds) . ")";
			}

			// 3. System templates (admin global)
			$orConditions[] = "t.is_system = 1";

			// 4. Admin-shared templates (is_shared = 1 created by administrators)
			$orConditions[] = "(t.is_shared = 1 AND (t.owner_id = 1 OR u.member_group_id = 1))";

			// 5. Parent-shared templates (shared by direct parent reseller)
			if ($parentOwnerId > 0) {
				$orConditions[] = "(t.is_shared = 1 AND t.owner_id = ?)";
				$params[] = $parentOwnerId;
			}

			$where[] = '(' . implode(' OR ', $orConditions) . ')';
		}

		if (!empty($search)) {
			$where[] = "t.name LIKE ?";
			$params[] = '%' . trim($search) . '%';
		}

		$whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

		$sql = "SELECT t.*, u.username AS owner_name, u.member_group_id AS owner_group_id
                FROM `category_templates` t
                LEFT JOIN `users` u ON u.id = t.owner_id
                {$whereClause}
                ORDER BY t.is_system DESC, t.id DESC";

		$db->query($sql, ...$params);
		$templates = $db->get_rows() ?: [];
		foreach ($templates as &$tmpl) {
			$tmplId = (int) $tmpl['id'];
			$tmpl['subscriber_count'] = self::getSubscriberCount($tmplId);

			$ownerId = (int) $tmpl['owner_id'];
			$isMine = ($ownerId === $userId);
			$isSubReseller = in_array($ownerId, $subResellerIds, true);
			$isSystem = ((int) $tmpl['is_system'] === 1);
			$isAdminShared = ((int) $tmpl['is_shared'] === 1 && ($ownerId === 1 || (int) ($tmpl['owner_group_id'] ?? 0) === 1));

			$tmpl['is_mine'] = $isMine;
			$tmpl['is_subreseller'] = $isSubReseller;
			$tmpl['is_admin_shared'] = $isAdminShared;

			if ($isMine) {
				$tmpl['scope_type'] = 'mine';
			} elseif ($isSubReseller) {
				$tmpl['scope_type'] = 'subreseller';
			} elseif ($isSystem || $isAdminShared) {
				$tmpl['scope_type'] = 'admin';
			} else {
				$tmpl['scope_type'] = 'shared';
			}
		}
		unset($tmpl);
		return $templates;
	}

	/**
	 * Get template data by ID with owner information.
	 *
	 * @param int $id Template ID
	 */
	public static function getTemplateById(int $id): ?array {
		$db = self::db();
		$db->query(
			"SELECT t.*, u.username AS owner_name, u.member_group_id AS owner_group_id
             FROM `category_templates` t
             LEFT JOIN `users` u ON u.id = t.owner_id
             WHERE t.id = ? LIMIT 1",
			$id
		);
		$row = $db->get_row();
		if ($row) {
			$row['subscriber_count'] = self::getSubscriberCount($id);
		}
		return $row ?: null;
	}

	/**
	 * Get template items joined with streams_categories.
	 */
	public static function getTemplateItems(int $templateId): array {
		$db = self::db();
		$db->query(
			"SELECT ti.*, sc.category_name AS original_name, sc.is_adult
             FROM `category_template_items` ti
             LEFT JOIN `streams_categories` sc ON sc.id = ti.category_id
             WHERE ti.template_id = ?
             ORDER BY ti.sort_order ASC, ti.id ASC",
			$templateId
		);
		return $db->get_rows() ?: [];
	}

	/**
	 * Get all available categories on the server merged with template settings for the editor.
	 * Ensures any new categories added after template creation are included.
	 *
	 * @return array ['live' => [...], 'movie' => [...], 'series' => [...]]
	 */
	public static function getEditorCategories(int $templateId): array {
		$db = self::db();

		// 1. Fetch saved template items
		$items = self::getTemplateItems($templateId);
		$itemsByCatId = [];
		foreach ($items as $it) {
			$itemsByCatId[(int) $it['category_id']] = $it;
		}

		// 2. Fetch all categories from the server
		$db->query("SELECT id, category_type, category_name, cat_order, is_adult FROM `streams_categories` ORDER BY cat_order ASC, id ASC");
		$allCats = $db->get_rows() ?: [];

		$grouped = [
			'live'   => [],
			'movie'  => [],
			'series' => [],
			'radio'  => []
		];

		$maxSortOrder = [
			'live'   => 0,
			'movie'  => 0,
			'series' => 0,
			'radio'  => 0
		];

		// Normalize category type
		foreach ($allCats as $cat) {
			$cid = (int) $cat['id'];
			$type = $cat['category_type'];
			if ($type === 'radio') {
				$section = 'radio';
			} elseif ($type === 'movie') {
				$section = 'movie';
			} elseif ($type === 'series') {
				$section = 'series';
			} else {
				$section = 'live';
			}

			if (isset($itemsByCatId[$cid])) {
				$it = $itemsByCatId[$cid];
				$sort = (int) $it['sort_order'];
				$grouped[$section][] = [
					'category_id'   => $cid,
					'category_type' => $section,
					'original_name' => $cat['category_name'],
					'custom_name'   => $it['custom_name'] ?? '',
					'sort_order'    => $sort,
					'is_visible'    => (int) $it['is_visible'],
					'is_adult'      => (int) $cat['is_adult']
				];
				if ($sort > $maxSortOrder[$section]) {
					$maxSortOrder[$section] = $sort;
				}
			} else {
				// New category not yet saved in template
				$maxSortOrder[$section]++;
				$grouped[$section][] = [
					'category_id'   => $cid,
					'category_type' => $section,
					'original_name' => $cat['category_name'],
					'custom_name'   => '',
					'sort_order'    => $maxSortOrder[$section],
					'is_visible'    => 1,
					'is_adult'      => (int) $cat['is_adult']
				];
			}
		}

		// Reorder each section by sort_order
		foreach ($grouped as &$list) {
			usort($list, function ($a, $b) {
				return $a['sort_order'] <=> $b['sort_order'];
			});
			// Re-index sequentially from 1
			$idx = 1;
			foreach ($list as &$item) {
				$item['sort_order'] = $idx++;
			}
			unset($item);
		}
		unset($list);

		return $grouped;
	}

	/**
	 * Create a new template and clone default server category ordering into it.
	 *
	 * @param int    $ownerId  Owner user ID
	 * @param string $name     Template name
	 * @param bool   $isSystem Whether this is a global system template
	 * @param bool   $isShared Whether this template is shared with sub-resellers
	 * @return array ['success' => bool, 'id' => int, 'message' => string]
	 */
	public static function createTemplate(int $ownerId, string $name, bool $isSystem = false, bool $isShared = false): array {
		$db = self::db();
		$name = trim($name);
		if ($name === '') {
			return ['success' => false, 'message' => 'Template name is required.'];
		}

		$now = date('Y-m-d H:i:s');

		$db->query(
			"INSERT INTO `category_templates` (
                `owner_id`, `name`, `is_system`, `is_shared`,
                `live_count`, `vod_count`, `series_count`, `created_at`, `updated_at`
            ) VALUES (?, ?, ?, ?, 0, 0, 0, ?, ?)",
			$ownerId,
			$name,
			$isSystem ? 1 : 0,
			$isShared ? 1 : 0,
			$now,
			$now
		);

		$templateId = (int) $db->last_insert_id();
		if ($templateId <= 0) {
			return ['success' => false, 'message' => 'Failed to create template in database.'];
		}

		// Copy default categories from streams_categories
		$db->query("SELECT id, category_type, category_name, cat_order FROM `streams_categories` ORDER BY cat_order ASC, id ASC");
		$allCats = $db->get_rows() ?: [];

		$liveCount = 0;
		$vodCount = 0;
		$seriesCount = 0;
		$radioCount = 0;
		$orderCounters = ['live' => 0, 'movie' => 0, 'series' => 0, 'radio' => 0];

		foreach ($allCats as $cat) {
			$type = $cat['category_type'];
			if ($type === 'radio') {
				$section = 'radio';
				$radioCount++;
			} elseif ($type === 'movie') {
				$section = 'movie';
				$vodCount++;
			} elseif ($type === 'series') {
				$section = 'series';
				$seriesCount++;
			} else {
				$section = 'live';
				$liveCount++;
			}

			$orderCounters[$section]++;

			$db->query(
				"INSERT INTO `category_template_items` (
                    `template_id`, `category_id`, `category_type`, `sort_order`, `is_visible`, `custom_name`
                ) VALUES (?, ?, ?, ?, 1, NULL)",
				$templateId,
				(int) $cat['id'],
				$section,
				$orderCounters[$section]
			);
		}

		$db->query(
			"UPDATE `category_templates` SET `live_count` = ?, `vod_count` = ?, `series_count` = ?, `radio_count` = ? WHERE `id` = ?",
			$liveCount,
			$vodCount,
			$seriesCount,
			$radioCount,
			$templateId
		);

		return [
			'success' => true,
			'id'      => $templateId,
			'message' => 'Template created successfully.'
		];
	}

	/**
	 * Save template modifications and category items.
	 *
	 * @param array $data       [name, is_shared, is_system, categories: [...]]
	 * @param array $user       Current user
	 * @param bool  $isAdmin    Is admin
	 * @return array ['success' => bool, 'message' => string]
	 */
	public static function saveTemplate(int $templateId, array $data, array $user, bool $isAdmin): array {
		$db = self::db();
		$template = self::getTemplateById($templateId);

		if (!$template) {
			return ['success' => false, 'message' => 'Template not found.'];
		}

		// Permission check: allow admin, owner, or parent reseller of the owner
		$isOwner = ((int) $template['owner_id'] === (int) $user['id']);
		$isSubReseller = false;
		if (!$isAdmin && !$isOwner) {
			$subUsers = UserRepository::getSubUsers((int) $user['id']);
			$subResellerIds = !empty($subUsers) ? array_map('intval', array_keys($subUsers)) : [];
			$isSubReseller = in_array((int) $template['owner_id'], $subResellerIds, true);
		}

		if (!$isAdmin && !$isOwner && !$isSubReseller) {
			return ['success' => false, 'message' => 'You do not have permission to edit this template.'];
		}

		if (!$isAdmin && (int) $template['is_system'] === 1) {
			return ['success' => false, 'message' => 'System templates can only be edited by administrators.'];
		}

		$name = !empty($data['name']) ? trim($data['name']) : $template['name'];
		$isShared = isset($data['is_shared']) ? ((int) $data['is_shared'] ? 1 : 0) : (int) $template['is_shared'];
		$isSystem = ($isAdmin && isset($data['is_system'])) ? ((int) $data['is_system'] ? 1 : 0) : (int) $template['is_system'];

		$db->beginTransaction();
		try {
			// Fetch category type map
			$db->query("SELECT id, category_type FROM `streams_categories`");
			$catMap = [];
			foreach ($db->get_rows() ?: [] as $row) {
				$type = $row['category_type'];
				if ($type === 'radio') {
					$catMap[(int) $row['id']] = 'radio';
				} elseif ($type === 'movie') {
					$catMap[(int) $row['id']] = 'movie';
				} elseif ($type === 'series') {
					$catMap[(int) $row['id']] = 'series';
				} else {
					$catMap[(int) $row['id']] = 'live';
				}
			}

			// Remove old items
			$db->query("DELETE FROM `category_template_items` WHERE `template_id` = ?", $templateId);

			$categories = $data['categories'] ?? [];
			$liveCount = 0;
			$vodCount = 0;
			$seriesCount = 0;
			$radioCount = 0;

			if (is_array($categories)) {
				foreach ($categories as $cat) {
					$cid = (int) ($cat['category_id'] ?? 0);
					if ($cid <= 0) {
						continue;
					}

					$section = $cat['category_type'] ?? ($catMap[$cid] ?? 'live');
					if ($section === 'live') {
						$liveCount++;
					} elseif ($section === 'movie') {
						$vodCount++;
					} elseif ($section === 'series') {
						$seriesCount++;
					} elseif ($section === 'radio') {
						$radioCount++;
					}

					$sortOrder = (int) ($cat['sort_order'] ?? 0);
					$isVisible = isset($cat['is_visible']) && ((int) $cat['is_visible'] === 1 || $cat['is_visible'] === true) ? 1 : 0;
					$customName = !empty($cat['custom_name']) ? trim($cat['custom_name']) : null;

					$db->query(
						"INSERT INTO `category_template_items` (
                            `template_id`, `category_id`, `category_type`, `sort_order`, `is_visible`, `custom_name`
                        ) VALUES (?, ?, ?, ?, ?, ?)",
						$templateId,
						$cid,
						$section,
						$sortOrder,
						$isVisible,
						$customName
					);
				}
			}

			// Update template header and counts
			$db->query(
				"UPDATE `category_templates` SET
                    `name` = ?,
                    `is_shared` = ?,
                    `is_system` = ?,
                    `live_count` = ?,
                    `vod_count` = ?,
                    `series_count` = ?,
                    `radio_count` = ?,
                    `updated_at` = ?
                WHERE `id` = ?",
				$name,
				$isShared,
				$isSystem,
				$liveCount,
				$vodCount,
				$seriesCount,
				$radioCount,
				date('Y-m-d H:i:s'),
				$templateId
			);

			$db->commit();

			// Automatically sync updated layout to all lines using this template and dispatch signal updates
			$syncedLines = self::syncTemplateToLines($templateId);

			$msg = $syncedLines > 0
				? "Template saved successfully and synchronized with {$syncedLines} active subscriber(s)."
				: 'Template and category order saved successfully.';

			return [
				'success'      => true,
				'message'      => $msg,
				'synced_lines' => $syncedLines
			];
		} catch (\Throwable $e) {
			$db->rollback();
			return ['success' => false, 'message' => 'Error saving template: ' . $e->getMessage()];
		}
	}

	/**
	 * Delete a template with authorization check.
	 *
	 * @return array ['success' => bool, 'message' => string]
	 */
	public static function deleteTemplate(int $templateId, array $user, bool $isAdmin): array {
		$db = self::db();
		$template = self::getTemplateById($templateId);

		if (!$template) {
			return ['success' => false, 'message' => 'Template not found.'];
		}

		if (!$isAdmin) {
			$isOwner = ((int) $template['owner_id'] === (int) $user['id']);
			$isSubReseller = false;
			if (!$isOwner) {
				$subUsers = UserRepository::getSubUsers((int) $user['id']);
				$subResellerIds = !empty($subUsers) ? array_map('intval', array_keys($subUsers)) : [];
				$isSubReseller = in_array((int) $template['owner_id'], $subResellerIds, true);
			}

			if (!$isOwner && !$isSubReseller) {
				return ['success' => false, 'message' => 'You are not authorized to delete this template.'];
			}
			if ((int) $template['is_system'] === 1) {
				return ['success' => false, 'message' => 'You cannot delete a system template.'];
			}
		}

		$db->query("DELETE FROM `category_templates` WHERE `id` = ?", $templateId);
		return ['success' => true, 'message' => 'Template deleted successfully.'];
	}

	/**
	 * Clone an existing template for the current user.
	 *
	 * @param int $templateId Template ID to copy
	 * @param int $newOwnerId New owner ID
	 * @return array ['success' => bool, 'id' => int, 'message' => string]
	 */
	public static function cloneTemplate(int $templateId, int $newOwnerId): array {
		$db = self::db();
		$source = self::getTemplateById($templateId);

		if (!$source) {
			return ['success' => false, 'message' => 'Original template not found.'];
		}

		$now = date('Y-m-d H:i:s');
		$newName = $source['name'] . ' (Copy)';

		$db->beginTransaction();
		try {
			$db->query(
				"INSERT INTO `category_templates` (
                    `owner_id`, `name`, `is_system`, `is_shared`,
                    `live_count`, `vod_count`, `series_count`, `radio_count`, `created_at`, `updated_at`
                ) VALUES (?, ?, 0, 0, ?, ?, ?, ?, ?, ?)",
				$newOwnerId,
				$newName,
				(int) $source['live_count'],
				(int) $source['vod_count'],
				(int) $source['series_count'],
				(int) ($source['radio_count'] ?? 0),
				$now,
				$now
			);

			$newId = (int) $db->last_insert_id();

			// Copy items
			$db->query("SELECT * FROM `category_template_items` WHERE `template_id` = ?", $templateId);
			$items = $db->get_rows() ?: [];

			foreach ($items as $it) {
				$db->query(
					"INSERT INTO `category_template_items` (
                        `template_id`, `category_id`, `category_type`, `sort_order`, `is_visible`, `custom_name`
                    ) VALUES (?, ?, ?, ?, ?, ?)",
					$newId,
					(int) $it['category_id'],
					$it['category_type'],
					(int) $it['sort_order'],
					(int) $it['is_visible'],
					$it['custom_name']
				);
			}

			$db->commit();
			return [
				'success' => true,
				'id'      => $newId,
				'message' => 'Template cloned successfully.'
			];
		} catch (\Throwable $e) {
			$db->rollback();
			return ['success' => false, 'message' => 'Failed to clone template: ' . $e->getMessage()];
		}
	}

	/**
	 * Toggle system status of a template (Super Admin Only).
	 */
	public static function toggleSystem(int $templateId, bool $isSystem, bool $isAdmin): array {
		if (!$isAdmin) {
			return ['success' => false, 'message' => 'This action is available to Super Admin only.'];
		}

		$db = self::db();
		$db->query("UPDATE `category_templates` SET `is_system` = ?, `updated_at` = ? WHERE `id` = ?", $isSystem ? 1 : 0, date('Y-m-d H:i:s'), $templateId);
		return ['success' => true, 'message' => 'System template status updated successfully.'];
	}

	/**
	 * Build standard XC custom_data structure from a template.
	 */
	public static function buildCustomData(int $templateId): array {
		$db = self::db();
		$db->query(
			"SELECT * FROM `category_template_items` WHERE `template_id` = ? ORDER BY `sort_order` ASC, `id` ASC",
			$templateId
		);
		$items = $db->get_rows() ?: [];

		$grouped = ['live' => [], 'movie' => [], 'series' => [], 'radio' => []];
		foreach ($items as $it) {
			$type = $it['category_type'];
			if (!isset($grouped[$type])) {
				$type = 'live';
			}
			$grouped[$type][] = $it;
		}

		$typesMap = [
			'live'   => 'live_cat',
			'movie'  => 'vod_cat',
			'series' => 'series_cat',
			'radio'  => 'radio_cat'
		];

		$customData = [
			'template_id' => $templateId,
		];
		foreach ($typesMap as $type => $key) {
			$hideIds = [];
			$renamed = [];
			$order = [];

			foreach ($grouped[$type] as $row) {
				$cid = (int) $row['category_id'];
				if ((int) $row['is_visible'] === 0) {
					$hideIds[] = $cid;
				}
				if (!empty($row['custom_name'])) {
					$renamed[(string) $cid] = trim($row['custom_name']);
				}
				$order[] = $cid;
			}

			$customData[$key] = [
				'hide_ids' => implode(',', $hideIds),
				'renamed'  => $renamed === [] ? (object) [] : $renamed,
				'order'    => implode(',', $order)
			];
		}

		return $customData;
	}

	/**
	 * Count active subscriber lines attached to a specific template.
	 */
	public static function getSubscriberCount(int $templateId): int {
		$db = self::db();
		$db->query(
			"SELECT COUNT(*) AS total FROM `lines`
             WHERE `custom_data` IS NOT NULL
               AND (`custom_data` LIKE ? OR `custom_data` LIKE ? OR (JSON_VALID(`custom_data`) AND JSON_EXTRACT(`custom_data`, '$.template_id') = ?))",
			'%"template_id":' . $templateId . '%',
			'%"template_id": ' . $templateId . '%',
			$templateId
		);
		$row = $db->get_row();
		return (int) ($row['total'] ?? 0);
	}

	/**
	 * Synchronize a template layout to all subscriber lines bound to it,
	 * and broadcast cache update signals to live connections.
	 *
	 * @return int Number of updated lines
	 */
	public static function syncTemplateToLines(int $templateId): int {
		$db = self::db();
		$customData = self::buildCustomData($templateId);
		$customDataJson = json_encode($customData, JSON_UNESCAPED_UNICODE);

		$db->query(
			"SELECT `id` FROM `lines`
             WHERE `custom_data` IS NOT NULL
               AND (`custom_data` LIKE ? OR `custom_data` LIKE ? OR (JSON_VALID(`custom_data`) AND JSON_EXTRACT(`custom_data`, '$.template_id') = ?))",
			'%"template_id":' . $templateId . '%',
			'%"template_id": ' . $templateId . '%',
			$templateId
		);
		$lines = $db->get_rows() ?: [];

		if (empty($lines)) {
			return 0;
		}

		$lineIds = array_column($lines, 'id');
		$chunks = array_chunk($lineIds, 500);

		foreach ($chunks as $chunk) {
			$placeholders = implode(',', array_fill(0, count($chunk), '?'));
			$params = array_merge([$customDataJson], $chunk);
			$db->query("UPDATE `lines` SET `custom_data` = ? WHERE `id` IN ({$placeholders})", ...$params);
		}

		// Send signal updates for line caches
		foreach ($lineIds as $lineId) {
			LineService::updateLineSignal((int) $lineId);
		}

		return count($lineIds);
	}

	/**
	 * Synchronize all templates containing a specific category ID,
	 * and update all attached lines in real time.
	 *
	 * @return int Total lines synchronized
	 */
	public static function syncTemplatesForCategory(int $categoryId): int {
		$db = self::db();
		$db->query("SELECT DISTINCT `template_id` FROM `category_template_items` WHERE `category_id` = ?", $categoryId);
		$templates = $db->get_rows() ?: [];
		$totalSynced = 0;
		foreach ($templates as $t) {
			$tid = (int) $t['template_id'];
			if ($tid > 0) {
				$totalSynced += self::syncTemplateToLines($tid);
			}
		}
		return $totalSynced;
	}

	/**
	 * Apply template layout to all lines belonging to the reseller or admin in bulk.
	 *
	 * @param array    $user             Current user
	 * @param bool     $isAdmin          Is super admin
	 * @param int|null $targetResellerId Target reseller lines (for admin)
	 * @return array ['success' => bool, 'message' => string, 'updated_count' => int]
	 */
	public static function applyToAll(int $templateId, array $user, bool $isAdmin, ?int $targetResellerId = null): array {
		$db = self::db();
		$template = self::getTemplateById($templateId);

		if (!$template) {
			return ['success' => false, 'message' => 'Template not found.'];
		}

		// Build custom_data JSON
		$customData = self::buildCustomData($templateId);
		$customDataJson = json_encode($customData, JSON_UNESCAPED_UNICODE);

		// Determine target lines
		$params = [];
		if (!$isAdmin) {
			$where = "member_id = ?";
			$params[] = (int) $user['id'];
		} else {
			if ($targetResellerId !== null && $targetResellerId > 0) {
				$where = "member_id = ?";
				$params[] = $targetResellerId;
			} else {
				$where = "member_id = ?";
				$params[] = (int) $template['owner_id'];
			}
		}

		$db->query("SELECT `id` FROM `lines` WHERE {$where}", ...$params);
		$subscribers = $db->get_rows() ?: [];

		if (empty($subscribers)) {
			return [
				'success'       => false,
				'message'       => 'No active lines found to apply this template to.',
				'updated_count' => 0
			];
		}

		$subIds = array_column($subscribers, 'id');
		$chunks = array_chunk($subIds, 500);

		$db->beginTransaction();
		try {
			foreach ($chunks as $chunk) {
				$placeholders = implode(',', array_fill(0, count($chunk), '?'));
				$chunkParams = array_merge([$customDataJson], $chunk);
				$db->query("UPDATE `lines` SET `custom_data` = ? WHERE `id` IN ({$placeholders})", ...$chunkParams);
			}

			$db->commit();

			// Send signal updates for line caches
			foreach ($subIds as $lineId) {
				LineService::updateLineSignal((int) $lineId);
			}

			return [
				'success'       => true,
				'message'       => 'Template applied successfully to ' . count($subIds) . ' lines.',
				'updated_count' => count($subIds)
			];
		} catch (\Throwable $e) {
			$db->rollback();
			return [
				'success' => false,
				'message' => 'Database update failed: ' . $e->getMessage(),
				'updated_count' => 0
			];
		}
	}

	/**
	 * Parse custom_data field into array or null.
	 *
	 * @param mixed $customData
	 */
	public static function parseCustomData($customData): ?array {
		if (empty($customData)) {
			return null;
		}
		if (is_array($customData)) {
			return $customData;
		}
		$decoded = json_decode((string) $customData, true);
		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * Filter and reorder categories returned to the Player API.
	 *
	 * @param array             $outputCategories Raw list: [['category_id' => '...', 'category_name' => '...', 'parent_id' => 0], ...]
	 * @param string|array|null $customData       User line custom_data
	 * @param string            $section          'live_cat', 'vod_cat', or 'series_cat'
	 */
	public static function applyCustomDataToCategories(array $outputCategories, $customData, string $section): array {
		$parsed = self::parseCustomData($customData);
		if (!$parsed) {
			return $outputCategories;
		}

		$sec = (!empty($parsed[$section]) && is_array($parsed[$section])) ? $parsed[$section] : [];

		// Parse hide_ids: supports both array and comma-separated string
		$hideIds = [];
		if (!empty($sec['hide_ids'])) {
			if (is_array($sec['hide_ids'])) {
				$hideIds = array_map('intval', $sec['hide_ids']);
			} elseif (is_string($sec['hide_ids'])) {
				$hideIds = array_map('intval', array_filter(array_map('trim', explode(',', $sec['hide_ids'])), static fn(string $s): bool => $s !== ''));
			}
		}

		// Parse order: supports both array and comma-separated string
		$order = [];
		if (!empty($sec['order'])) {
			if (is_array($sec['order'])) {
				$order = array_map('intval', $sec['order']);
			} elseif (is_string($sec['order'])) {
				$order = array_map('intval', array_filter(array_map('trim', explode(',', $sec['order'])), static fn(string $s): bool => $s !== ''));
			}
		}

		// Parse renamed: supports array and stdClass
		$renamed = [];
		if (isset($sec['renamed'])) {
			$renamed = is_array($sec['renamed']) ? $sec['renamed'] : (is_object($sec['renamed']) ? (array) $sec['renamed'] : []);
		}

		// In standard XC Player API, get_live_categories contains both live & radio categories.
		// If radio_cat exists in template, merge its rules when applying live_cat.
		if ($section === 'live_cat' && !empty($parsed['radio_cat']) && is_array($parsed['radio_cat'])) {
			$rSec = $parsed['radio_cat'];
			if (!empty($rSec['hide_ids'])) {
				$rHide = is_array($rSec['hide_ids']) ? array_map('intval', $rSec['hide_ids']) : array_map('intval', array_filter(array_map('trim', explode(',', $rSec['hide_ids'])), static fn(string $s): bool => $s !== ''));
				$hideIds = array_unique(array_merge($hideIds, $rHide));
			}
			if (!empty($rSec['renamed'])) {
				$rRen = is_array($rSec['renamed']) ? $rSec['renamed'] : (is_object($rSec['renamed']) ? (array) $rSec['renamed'] : []);
				foreach ($rRen as $k => $v) {
					$renamed[$k] = $v;
				}
			}
			if (!empty($rSec['order'])) {
				$rOrd = is_array($rSec['order']) ? array_map('intval', $rSec['order']) : array_map('intval', array_filter(array_map('trim', explode(',', $rSec['order'])), static fn(string $s): bool => $s !== ''));
				$order = array_merge($order, $rOrd);
			}
		}

		// 1. Filter out hidden categories and apply custom names
		$filtered = [];
		foreach ($outputCategories as $cat) {
			$cid = (int) ($cat['category_id'] ?? 0);
			if (in_array($cid, $hideIds, true)) {
				continue;
			}
			if (isset($renamed[(string) $cid]) && trim((string) $renamed[(string) $cid]) !== '') {
				$cat['category_name'] = (string) $renamed[(string) $cid];
			}
			$filtered[] = $cat;
		}

		// 2. Reorder according to sort order if provided
		if ($order !== []) {
			$orderMap = array_flip($order);
			usort($filtered, function ($a, $b) use ($orderMap) {
				$idA = (int) ($a['category_id'] ?? 0);
				$idB = (int) ($b['category_id'] ?? 0);
				$posA = $orderMap[$idA] ?? PHP_INT_MAX;
				$posB = $orderMap[$idB] ?? PHP_INT_MAX;
				if ($posA === $posB) {
					return 0;
				}
				return ($posA < $posB) ? -1 : 1;
			});
		}

		return $filtered;
	}

	/**
	 * Extract custom category configuration for PlaylistGenerator.
	 *
	 * @param string|array|null $customData
	 * @param string            $type 'live', 'radio', 'movie', 'series'
	 * @return array ['hide_ids' => int[], 'renamed' => array<string, string>, 'order' => int[]]
	 */
	public static function getCustomCategoryConfig($customData, string $type): array {
		$section = match ($type) {
			'radio'         => 'radio_cat',
			'live'          => 'live_cat',
			'movie'         => 'vod_cat',
			'series'        => 'series_cat',
			default         => 'live_cat',
		};

		$parsed = self::parseCustomData($customData);
		if (!$parsed) {
			return ['hide_ids' => [], 'renamed' => [], 'order' => []];
		}

		// Fallback for legacy templates where radio was saved inside live_cat
		if ($type === 'radio' && (empty($parsed['radio_cat']) || !is_array($parsed['radio_cat']))) {
			$section = 'live_cat';
		}

		if (empty($parsed[$section]) || !is_array($parsed[$section])) {
			return ['hide_ids' => [], 'renamed' => [], 'order' => []];
		}

		$sec = $parsed[$section];

		$hideIds = [];
		if (!empty($sec['hide_ids'])) {
			if (is_array($sec['hide_ids'])) {
				$hideIds = array_map('intval', $sec['hide_ids']);
			} elseif (is_string($sec['hide_ids'])) {
				$hideIds = array_map('intval', array_filter(array_map('trim', explode(',', $sec['hide_ids'])), static fn(string $s): bool => $s !== ''));
			}
		}

		$order = [];
		if (!empty($sec['order'])) {
			if (is_array($sec['order'])) {
				$order = array_map('intval', $sec['order']);
			} elseif (is_string($sec['order'])) {
				$order = array_map('intval', array_filter(array_map('trim', explode(',', $sec['order'])), static fn(string $s): bool => $s !== ''));
			}
		}

		$renamed = [];
		if (isset($sec['renamed'])) {
			$renamed = is_array($sec['renamed']) ? $sec['renamed'] : (is_object($sec['renamed']) ? (array) $sec['renamed'] : []);
		}

		return [
			'hide_ids' => $hideIds,
			'renamed'  => $renamed,
			'order'    => $order,
		];
	}
}
