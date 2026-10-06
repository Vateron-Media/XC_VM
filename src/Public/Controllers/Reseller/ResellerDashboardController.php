<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Enum\Theme;
use XcVm\Core\Reference\GeoReference;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;

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

		// A session that lost its user (expired mid-request, API-key path) reaches
		// here with the globals unset; render an empty dashboard instead of a fatal.
		$rUserInfo = (array) ($GLOBALS['rUserInfo'] ?? []);
		$rPermissions = (array) ($GLOBALS['rPermissions'] ?? []);
		$rUserId = intval($rUserInfo['id'] ?? 0);

		$rRegisteredUsers = $rUserId > 0 ? UserRepository::getResellers($rUserId, true) : [];
		$rGroups = GroupService::getAll();

		// Sanitize notice HTML
		$rNotice = html_entity_decode($rGroups[intval($rUserInfo['member_group_id'] ?? 0)]['notice_html'] ?? '');
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

		// Recent activity
		$rPackages = PackageService::getAll();
		global $db;
		$rReportIds = array_map('intval', array_merge([$rUserId], (array) ($rPermissions['all_reports'] ?? [])));
		$rReportIdsSql = implode(',', $rReportIds);
		$db->query('SELECT `users`.`username`, `users_logs`.`owner`, `users_logs`.`type`, `users_logs`.`action`, `users_logs`.`log_id`, `users_logs`.`package_id`, `users_logs`.`cost`, `users_logs`.`date`, `users_logs`.`deleted_info` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` WHERE `users_logs`.`owner` IN (' . $rReportIdsSql . ') ORDER BY `users_logs`.`date` DESC LIMIT 250;');
		$rActivityRows = [];
		foreach ($db->get_rows() as $rRow) {
			// The view prints it as HTML: escaped, with the package name on its own line.
			$rText = str_replace("\n", '<br/>', htmlspecialchars(UserService::logText($rRow, $rPackages, "\n"), ENT_QUOTES, 'UTF-8'));
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
				'owner_id'  => $rRow['owner'],
				'username'  => $rRow['username'],
				'text'        => $rText,
				'target_html' => $rTargetHtml,
				'date'        => $rRow['date'],
			];
		}

		// Expiring lines
		$rExpiringLines = LineService::getExpiring() ?: [];

		// Connections by location. `lines_activity` is global, so the join onto
		// `lines` is what keeps a reseller inside its own report tree — there is
		// deliberately no unscoped fallback when the tree has no activity yet.
		// Only run when the map is drawn, and kept five minutes per reseller: it
		// aggregates every closed connection.
		$rCountryRows = [];
		if (SettingsManager::get('save_closed_connection') && SettingsManager::get('dashboard_map')) {
			$rCountryRows = (new FileCache(CACHE_TMP_PATH))->remember('reseller_map_' . $rUserId, 300, static function () use ($db, $rReportIdsSql): array|false {
				$rOk = $db->query('SELECT `lines_activity`.`geoip_country_code`, COUNT(`lines_activity`.`activity_id`) AS `count`
							FROM `lines_activity`
							LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id`
							WHERE `lines`.`member_id` IN (' . $rReportIdsSql . ')
							GROUP BY `lines_activity`.`geoip_country_code`
							ORDER BY `count` DESC LIMIT 10;');
				return $rOk ? ($db->get_rows() ?: []) : false;
			}) ?: [];
		}
		[$rConnectionMap, $rConnectionCount] = self::buildConnectionMap(
			$rCountryRows,
			Theme::fromId($rUserInfo['theme'] ?? 0)->isDark()
		);

		// jsvectormap is only worth its payload once there is something to paint.
		if ($rConnectionCount > 0) {
			$GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
				(array) ($GLOBALS['xmNewuiVendors'] ?? []),
				['jsvectormap']
			)));
		}

		$this->render('dashboard', [
			'rRegisteredUsers' => $rRegisteredUsers,
			'rNotice'          => $rNotice,
			'rActivityRows'    => $rActivityRows,
			'rExpiringLines'   => $rExpiringLines,
			'rConnectionMap'   => $rConnectionMap,
			'rConnectionCount' => $rConnectionCount,
		]);
	}

	/**
	 * Decorate grouped `lines_activity` rows for the top-countries panel.
	 *
	 * Each row gains a display `name` and a `colour` pair — [hex, bg class] — so
	 * the world map and the progress bars stay in step. Rows GeoIP could not
	 * resolve are dropped rather than pinned to an arbitrary country.
	 *
	 * @param array $rRows   Rows of ['geoip_country_code' => string, 'count' => int].
	 * @param bool  $rIsDark Whether the reseller's theme is dark.
	 * @return array{0: array<int, array>, 1: int} Decorated rows and their total.
	 */
	public static function buildConnectionMap(array $rRows, bool $rIsDark): array {
		$rColourMap = $rIsDark
			? [['#7e8e9d', 'bg-map-dark-1'], ['#6c7b8a', 'bg-map-dark-2'], ['#5a6977', 'bg-map-dark-3'], ['#485765', 'bg-map-dark-4'], ['#374654', 'bg-map-dark-5'], ['#273643', 'bg-map-dark-6']]
			: [['#23b397', 'bg-success'], ['#56c2d6', 'bg-info'], ['#5089de', 'bg-primary'], ['#675db7', 'bg-purple'], ['#e36498', 'bg-pink'], ['#98a6ad', 'bg-secondary']];
		$rCountryCodes = GeoReference::countryCodes();

		$rMap = [];
		$rTotal = 0;
		foreach ($rRows as $rRow) {
			$rCode = strtoupper((string) ($rRow['geoip_country_code'] ?? ''));
			if ($rCode === '') {
				continue;
			}
			$rRow['geoip_country_code'] = $rCode;
			$rRow['name'] = $rCountryCodes[$rCode] ?? 'Unknown Country';
			$rRow['colour'] = $rColourMap[min(count($rMap), count($rColourMap) - 1)];
			$rTotal += intval($rRow['count']);
			$rMap[] = $rRow;
		}

		return [$rMap, $rTotal];
	}
}
