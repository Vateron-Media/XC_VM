<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Reference\GeoReference;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * ResellerDashboardController — Reseller dashboard.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerDashboardController extends BaseResellerController {
	public function index() {
		$this->setTitle('Dashboard');

		$rUserInfo = (array) ($GLOBALS['rUserInfo'] ?? []);
		$rPermissions = (array) ($GLOBALS['rPermissions'] ?? []);
		$rUserId = intval($rUserInfo['id'] ?? 0);

		$rRegisteredUsers = $rUserId > 0 ? UserRepository::getResellers($rUserId, true) : [];
		$rGroups = GroupService::getAll();

		// Sanitize notice HTML
		$rMemberGroupId = intval($rUserInfo['member_group_id'] ?? 0);
		$rNotice = html_entity_decode($rGroups[$rMemberGroupId]['notice_html'] ?? '');
		$rNotice = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $rNotice);
		$rNotice = preg_replace('#</*\\w+:\\w[^>]*+>#i', '', $rNotice);
		$rNotice = str_replace(['&amp;', '&lt;', '&gt;'], ['&amp;amp;', '&amp;lt;', '&amp;gt;'], $rNotice);
		$rNotice = preg_replace('/(&#*\\w+)[\\x00-\\x20]+;/u', '$1;', $rNotice);
		$rNotice = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $rNotice);
		$rNotice = html_entity_decode($rNotice, ENT_COMPAT, 'UTF-8');
		$rNotice = preg_replace("#(<[^>]+?[\\x00-\\x20\"'])(?:on|xmlns)[^>]*+[>\\b]?#iu", '$1>', $rNotice);
		$rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=[\\x00-\\x20]*([`'\"]*)[\\x00-\\x20]*j[\\x00-\\x20]*a[\\x00-\\x20]*v[\\x00-\\x20]*a[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2nojavascript...', $rNotice);
		$rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*v[\\x00-\\x20]*b[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2novbscript...', $rNotice);
		$rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*-moz-binding[\\x00-\\x20]*:#u", '$1=$2nomozbinding...', $rNotice);
		$rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?expression[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
		$rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?behaviour[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
		$rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:*[^>]*+>#iu", '$1>', $rNotice);

		// Reseller sub-users and reports
		$rAllReports = array_merge([$rUserId], (array) ($rPermissions['all_reports'] ?? []));
		$rReportIds = array_filter(array_map('intval', $rAllReports));
		if (empty($rReportIds)) {
			$rReportIds = [$rUserId];
		}
		$rReportIdsSql = implode(',', $rReportIds);

		global $db;

		// Ensure jsvectormap and datatables bundles are loaded
		$GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
			(array) ($GLOBALS['xmNewuiVendors'] ?? []),
			['jsvectormap', 'datatables']
		)));

		// Line IDs belonging to this reseller and sub-resellers
		$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . $rReportIdsSql . ');');
		$rLineIds = [];
		foreach ($db->get_rows() as $rRow) {
			$rLineIds[] = (int) $rRow['id'];
		}

		$rColourMap = [
			['#23b397', 'bg-success'],
			['#56c2d6', 'bg-info'],
			['#5089de', 'bg-primary'],
			['#675db7', 'bg-purple'],
			['#e36498', 'bg-pink'],
			['#ff9f43', 'bg-warning'],
			['#98a6ad', 'bg-secondary'],
		];
		$rCountryCodes = GeoReference::countryCodes();
		$rConnectionMap = [];
		$rConnectionCount = 0;
		$xmMapValues = [];
		$rLiveConnections = [];
		$rCountryCounts = [];

		$rRedisEnabled = SettingsManager::getBool('redis_handler');
		if ($rRedisEnabled && !empty($rLineIds)) {
			$rRawConns = ConnectionTracker::getUserConnections($rLineIds, false);
			$rActiveConns = [];
			$rStreamIds = [];
			$rUserIds = [];
			if (is_array($rRawConns)) {
				foreach ($rRawConns as $rUId => $rConns) {
					if (!is_array($rConns)) {
						continue;
					}
					foreach ($rConns as $rConn) {
						if (!is_array($rConn)) {
							continue;
						}
						if (empty($rConn['hls_end'])) {
							$rActiveConns[] = $rConn;
							if (!empty($rConn['stream_id'])) {
								$rStreamIds[] = (int) $rConn['stream_id'];
							}
							if (!empty($rConn['user_id'])) {
								$rUserIds[] = (int) $rConn['user_id'];
							}
						}
					}
				}
			}

			$rStreamNames = [];
			if (!empty($rStreamIds)) {
				$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_unique($rStreamIds)) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rStreamNames[$rRow['id']] = $rRow['stream_display_name'];
				}
			}

			$rLineDetails = [];
			if (!empty($rUserIds)) {
				$db->query('SELECT `lines`.`id`, `lines`.`username`, `lines`.`is_mag`, `lines`.`is_e2`, `lines`.`member_id`,
								   `users`.`username` AS `reseller_owner`,
								   `mag_devices`.`mac` AS `mag_mac`, `mag_devices`.`mag_id`,
								   `enigma2_devices`.`mac` AS `e2_mac`, `enigma2_devices`.`device_id`
							FROM `lines`
							LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id`
							LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id`
							LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id`
							WHERE `lines`.`id` IN (' . implode(',', array_unique($rUserIds)) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rLineDetails[$rRow['id']] = $rRow;
				}
			}

			$rServers = ServerRepository::getAll();

			foreach ($rActiveConns as $rC) {
				$rLine = $rLineDetails[$rC['user_id']] ?? [];
				$rClientName = $rLine['username'] ?? ('Line #' . $rC['user_id']);
				if (!empty($rLine['is_mag']) && !empty($rLine['mag_mac'])) {
					$rClientName = $rLine['mag_mac'];
				} elseif (!empty($rLine['is_e2']) && !empty($rLine['e2_mac'])) {
					$rClientName = $rLine['e2_mac'];
				}

				$rCode = strtoupper((string) ($rC['geoip_country_code'] ?? ''));
				if (empty($rCode) && !empty($rC['user_ip'])) {
					$rCode = 'EG';
				}

				if (!empty($rCode)) {
					$rCountryCounts[$rCode] = ($rCountryCounts[$rCode] ?? 0) + 1;
				}

				$rLiveConnections[] = [
					'activity_id'         => $rC['uuid'] ?? ($rC['activity_id'] ?? ''),
					'uuid'                => $rC['uuid'] ?? '',
					'user_id'             => $rC['user_id'],
					'client_name'         => $rClientName,
					'is_mag'              => $rLine['is_mag'] ?? 0,
					'is_e2'               => $rLine['is_e2'] ?? 0,
					'mag_id'              => $rLine['mag_id'] ?? null,
					'device_id'           => $rLine['device_id'] ?? null,
					'stream_id'           => $rC['stream_id'],
					'stream_display_name' => $rStreamNames[$rC['stream_id']] ?? ('Stream #' . $rC['stream_id']),
					'geoip_country_code'  => $rCode,
					'user_ip'             => $rC['user_ip'] ?? '',
					'user_agent'          => $rC['user_agent'] ?? '',
					'container'           => $rC['container'] ?? '',
					'divergence'          => $rC['divergence'] ?? 0,
					'date_start'          => $rC['date_start'] ?? time(),
					'reseller_owner'      => $rLine['reseller_owner'] ?? '-',
					'server_name'         => $rServers[$rC['server_id']]['server_name'] ?? '',
				];
			}
		}

		// Fallback to lines_live in MySQL if Redis yielded no connections
		if (empty($rLiveConnections)) {
			$db->query('SELECT `lines_live`.*, `streams`.`stream_display_name`,
							   IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `client_name`,
							   `lines`.`is_mag`, `lines`.`is_e2`, `lines`.`member_id`, `users`.`username` AS `reseller_owner`,
							   `mag_devices`.`mag_id`, `enigma2_devices`.`device_id`
						FROM `lines_live`
						LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id`
						LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id`
						LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id`
						LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id`
						LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id`
						WHERE `lines`.`member_id` IN (' . $rReportIdsSql . ') AND `lines_live`.`hls_end` = 0
						ORDER BY `lines_live`.`date_start` DESC LIMIT 100;');
			foreach ($db->get_rows() as $rRow) {
				$rCode = strtoupper((string) ($rRow['geoip_country_code'] ?? ''));
				if (!empty($rCode)) {
					$rCountryCounts[$rCode] = ($rCountryCounts[$rCode] ?? 0) + 1;
				}
				$rLiveConnections[] = $rRow;
			}
		}

		// Build Connection Map and Top Countries
		if (!empty($rCountryCounts)) {
			arsort($rCountryCounts);
			$i = 0;
			foreach ($rCountryCounts as $rCode => $rCount) {
				$rColour = $rColourMap[$i % count($rColourMap)];
				$rConnectionMap[] = [
					'geoip_country_code' => $rCode,
					'count'              => $rCount,
					'name'               => $rCountryCodes[$rCode] ?? $rCode,
					'colour'             => $rColour,
				];
				$rConnectionCount += $rCount;
				if (preg_match('/^[A-Z]{2}$/', $rCode) && !in_array($rCode, ['A1', 'A2', 'O1', 'AP', 'EU', 'AN'], true)) {
					$xmMapValues[$rCode] = $rColour[0];
				}
				$i++;
			}
		} else {
			// Fallback: query lines_activity so map and country statistics always show meaningful data
			$db->query('SELECT `lines_activity`.`geoip_country_code`, COUNT(`lines_activity`.`activity_id`) AS `count`
						FROM `lines_activity`
						LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id`
						WHERE `lines`.`member_id` IN (' . $rReportIdsSql . ')
						GROUP BY `lines_activity`.`geoip_country_code`
						ORDER BY `count` DESC LIMIT 10;');
			$rActRows = $db->get_rows();
			if (empty($rActRows)) {
				$db->query('SELECT `geoip_country_code`, COUNT(`activity_id`) AS `count`
							FROM `lines_activity`
							WHERE `geoip_country_code` IS NOT NULL AND `geoip_country_code` != ""
							GROUP BY `geoip_country_code`
							ORDER BY `count` DESC LIMIT 7;');
				$rActRows = $db->get_rows();
			}
			$i = 0;
			foreach ($rActRows as $rRow) {
				$rCode = strtoupper((string) ($rRow['geoip_country_code'] ?? ''));
				if (empty($rCode)) {
					$rCode = 'EG';
				}
				$rColour = $rColourMap[$i % count($rColourMap)];
				$rRow['colour'] = $rColour;
				$rRow['geoip_country_code'] = $rCode;
				$rRow['name'] = $rCountryCodes[$rCode] ?? $rCode;
				$rConnectionCount += (int) $rRow['count'];
				$rConnectionMap[] = $rRow;
				if (preg_match('/^[A-Z]{2}$/', $rCode) && !in_array($rCode, ['A1', 'A2', 'O1', 'AP', 'EU', 'AN'], true)) {
					$xmMapValues[$rCode] = $rColour[0];
				}
				$i++;
			}
		}

		// Recent media (Movies, Live Streams, Series, Episodes)
		$rCanVod = true;
		$rLatestMovies = [];
		$rLatestStreams = [];
		$rLatestSeries = [];
		$rLatestEpisodes = [];
		$rCategories = [];
		if (class_exists(CategoryService::class) && method_exists(CategoryService::class, 'getFromDatabase')) {
			$rCategories = CategoryService::getFromDatabase();
		} else {
			$db->query('SELECT `id`, `category_name` FROM `streams_categories`;');
			foreach ($db->get_rows() as $rCat) {
				$rCategories[$rCat['id']] = $rCat;
			}
		}

		$rScopeSql = '';
		if (!empty($rPermissions['stream_ids'])) {
			$rScopeSql = ' AND `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
		}

		// Latest Movies (type = 2)
		$db->query('SELECT `id`, `stream_display_name`, `stream_icon`, `category_id`, `added` FROM `streams` WHERE `type` = 2' . $rScopeSql . ' ORDER BY `id` DESC LIMIT 15;');
		$rLatestMovies = $db->get_rows();
		if (empty($rLatestMovies)) {
			$db->query('SELECT `id`, `stream_display_name`, `stream_icon`, `category_id`, `added` FROM `streams` WHERE `type` = 2 ORDER BY `id` DESC LIMIT 15;');
			$rLatestMovies = $db->get_rows();
		}

		// Latest Live Streams (type = 1)
		$db->query('SELECT `id`, `stream_display_name`, `stream_icon`, `category_id`, `added` FROM `streams` WHERE `type` = 1' . $rScopeSql . ' ORDER BY `id` DESC LIMIT 15;');
		$rLatestStreams = $db->get_rows();
		if (empty($rLatestStreams)) {
			$db->query('SELECT `id`, `stream_display_name`, `stream_icon`, `category_id`, `added` FROM `streams` WHERE `type` = 1 ORDER BY `id` DESC LIMIT 15;');
			$rLatestStreams = $db->get_rows();
		}

		// Latest Series
		$db->query('SELECT `id`, `title`, `cover`, `category_id`, `rating` FROM `streams_series` ORDER BY `id` DESC LIMIT 15;');
		$rLatestSeries = $db->get_rows();

		// Latest Episodes
		$db->query('SELECT `streams_episodes`.`id`, `streams_episodes`.`season_num`, `streams_episodes`.`episode_num`,
						   `streams_episodes`.`series_id`, `streams_episodes`.`stream_id`,
						   `streams`.`stream_display_name`, `streams`.`stream_icon`, `streams`.`added`,
						   `streams_series`.`title` AS `series_title`, `streams_series`.`cover`
					FROM `streams_episodes`
					LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id`
					LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id`
					ORDER BY `streams_episodes`.`id` DESC LIMIT 15;');
		$rLatestEpisodes = $db->get_rows();

		// Recent activity
		$rPackages = PackageService::getAll();
		$db->query('SELECT `users`.`username`, `users_logs`.`owner`, `users_logs`.`type`, `users_logs`.`action`, `users_logs`.`log_id`, `users_logs`.`package_id`, `users_logs`.`cost`, `users_logs`.`date`, `users_logs`.`deleted_info` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` WHERE `users_logs`.`owner` IN (' . $rReportIdsSql . ') ORDER BY `users_logs`.`date` DESC LIMIT 250;');
		$rActivityRows = [];
		$rDeviceMap = ['line' => 'User Line', 'mag' => 'MAG Device', 'enigma' => 'Enigma2 Device', 'user' => 'Reseller'];
		foreach ($db->get_rows() as $rRow) {
			$rDevice = $rDeviceMap[$rRow['type']] ?? '';
			$rText = '';
			switch ($rRow['action']) {
				case 'new':
					$rText = 'Created New ' . $rDevice . ($rRow['package_id'] && isset($rPackages[$rRow['package_id']]) ? ' with Package:<br/>' . $rPackages[$rRow['package_id']]['package_name'] : '');
					break;
				case 'extend':
					$rText = 'Extended ' . $rDevice . ($rRow['package_id'] && isset($rPackages[$rRow['package_id']]) ? ' with Package:<br/>' . $rPackages[$rRow['package_id']]['package_name'] : '');
					break;
				case 'convert':
					$rText = 'Converted Device to User Line';
					break;
				case 'edit':
					$rText = 'Edited ' . $rDevice;
					break;
				case 'enable':
					$rText = 'Enabled ' . $rDevice;
					break;
				case 'disable':
					$rText = 'Disabled ' . $rDevice;
					break;
				case 'delete':
					$rText = 'Deleted ' . $rDevice;
					break;
				case 'send_event':
					$rText = 'Sent Event to ' . $rDevice;
					break;
				case 'adjust_credits':
					$rText = 'Adjusted Credits by ' . $rRow['cost'];
					break;
			}
			$rTargetHtml = '';
			$rTargetId = intval($rRow['log_id'] ?? 0);
			switch ($rRow['type']) {
				case 'line':
					$rTarget = UserRepository::getLineById($rTargetId);
					if ($rTarget) {
						$rTargetHtml = "<a class='text-body' href='line?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['username'], ENT_QUOTES, 'UTF-8') . '</a>';
					}
					break;
				case 'user':
					$rTarget = UserRepository::getRegisteredUserById($rTargetId);
					if ($rTarget) {
						$rTargetHtml = "<a class='text-body' href='user?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['username'], ENT_QUOTES, 'UTF-8') . '</a>';
					}
					break;
				case 'mag':
					$rTarget = MagService::getById($rTargetId);
					if ($rTarget) {
						$rTargetHtml = "<a class='text-body' href='mag?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['mac'], ENT_QUOTES, 'UTF-8') . '</a>';
					}
					break;
				case 'enigma':
					$rTarget = EnigmaService::getById($rTargetId);
					if ($rTarget) {
						$rTargetHtml = "<a class='text-body' href='enigma?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['mac'], ENT_QUOTES, 'UTF-8') . '</a>';
					}
					break;
			}
			if ($rTargetHtml === '') {
				$rDeletedInfo = json_decode((string) ($rRow['deleted_info'] ?? ''), true);
				$rTargetName = is_array($rDeletedInfo)
					? (string) ($rDeletedInfo['mac'] ?? $rDeletedInfo['username'] ?? '')
					: '';
				$rTargetHtml = $rTargetName !== ''
					? "<span class='text-body-secondary'>" . htmlspecialchars($rTargetName, ENT_QUOTES, 'UTF-8') . '</span>'
					: "<span class='text-body-secondary'>-</span>";
			}
			$rActivityRows[] = [
				'owner_id'    => $rRow['owner'],
				'username'    => $rRow['username'],
				'text'        => $rText,
				'target_html' => $rTargetHtml,
				'date'        => $rRow['date'],
			];
		}

		// Expiring lines
		$rExpiringLines = LineService::getExpiring() ?: [];

		$rSettings = SettingsManager::getAll();

		$this->render('dashboard', [
			'rRegisteredUsers' => $rRegisteredUsers,
			'rNotice'          => $rNotice,
			'rActivityRows'    => $rActivityRows,
			'rExpiringLines'   => $rExpiringLines,
			'rConnectionMap'   => $rConnectionMap,
			'rConnectionCount' => $rConnectionCount,
			'xmMapValues'      => $xmMapValues,
			'rLiveConnections' => $rLiveConnections,
			'rLatestMovies'    => $rLatestMovies,
			'rLatestStreams'   => $rLatestStreams,
			'rLatestSeries'    => $rLatestSeries,
			'rLatestEpisodes'  => $rLatestEpisodes,
			'rCategories'      => $rCategories,
			'rCountryCodes'    => $rCountryCodes,
			'rSettings'        => $rSettings,
			'rCanVod'          => $rCanVod,
		]);
	}
}
