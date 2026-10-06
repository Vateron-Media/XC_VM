<?php

namespace XcVm\Core\Audit;

use XcVm\Core\Database\Database;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The admin action trail (`admin_audit`): who changed what, from the panel and
 * the Admin API. Three entry points record (start()): the admin forms
 * (post.php), the admin panel's Ajax actions (the front controller's `api`
 * route) and the Admin API (AdminApiController). Reads are left out.
 *
 * An entry names the account, its address, the action and, from the request,
 * only identifying fields (DETAIL_KEYS: ids, names, the sub-action), never a
 * password, key or other body. The outcome is read from the answer: the
 * request's output passes through a buffer whose first bytes are kept, and the
 * entry is written at shutdown with `result` 1 or 0 when the answer says, or
 * NULL when it is not one the panel's JSON shapes.
 *
 * There is no way to clear the trail from the panel.
 *
 * @package XC_VM_Core_Audit
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class AdminAudit {
	use DatabaseAware;

	/**
	 * The request fields an entry keeps, what was acted on and never what was
	 * sent with it: these names, and every id (ID_KEY).
	 */
	public const DETAIL_KEYS = ['sub', 'type', 'username', 'stream_display_name', 'server_name', 'group_name', 'bouquet_name', 'package_name', 'category_name', 'title', 'name'];

	/** An id field: `id`, `ids`, `edit`, `pid`, or a name ending in `_id` / `_ids` (user_id, code_id, …). */
	public const ID_KEY = '/^(id|ids|edit|pid)$|_ids?$/';

	/** Fields an entry keeps at most. */
	private const MAX_FIELDS = 20;

	/** The admin panel's Ajax actions that only read. */
	public const PANEL_READS = [
		'stats', 'graph_stats', 'header_stats', 'server_stats', 'server_view', 'fpm_status', 'install_status', 'search', 'get_epg', 'get_programme',
		'get_package', 'get_package_trial', 'epglist', 'epg_categories', 'ip_whois', 'tmdb_search', 'tmdb', 'userlist', 'reguserlist', 'rollback_versions',
		'active_code_details', 'category_template_get', 'provider_streams', 'save_ui_prefs', 'session',
	];

	/** Bytes of the answer kept to read its outcome. */
	private const HEAD = 2048;

	/** Off on the command line: admin actions come from web requests (tests turn it on). */
	private static bool $rEnabled = PHP_SAPI !== 'cli';

	/** @var array<string, mixed>|null the entry the current request records at shutdown */
	private static ?array $rEntry = null;

	private static string $rHead = '';

	/**
	 * Record the current request as an admin action: written when the request
	 * ends, with its outcome. A second call in the same request is ignored.
	 *
	 * @param 'panel'|'api' $rSource
	 * @param array<string, mixed> $rUser the acting account (id, username)
	 * @param array<string, mixed> $rRequest the request fields
	 */
	public static function start(string $rSource, string $rAction, array $rUser, array $rRequest): void {
		if (!self::$rEnabled || self::$rEntry !== null || $rAction === '') {
			return;
		}
		self::$rEntry = [
			'date' => time(),
			'user_id' => isset($rUser['id']) ? (int) $rUser['id'] : null,
			'username' => isset($rUser['username']) ? substr((string) $rUser['username'], 0, 50) : null,
			'ip' => substr((string) NetworkUtils::getUserIP(), 0, 64),
			'source' => $rSource,
			'action' => substr($rAction, 0, 64),
			'detail' => self::detail($rRequest),
		];
		self::$rHead = '';
		// Pass every byte through at once (chunk size 1): downloads stream as
		// before; only the start of the answer is kept, to read its outcome.
		ob_start(static function (string $rBuffer): string {
			if (strlen(self::$rHead) < self::HEAD) {
				self::$rHead .= substr($rBuffer, 0, self::HEAD - strlen(self::$rHead));
			}
			return $rBuffer;
		}, 1);
		register_shutdown_function([self::class, 'finish']);
	}

	/** Add to the current entry's detail (e.g. the names of the settings a save changed). */
	public static function note(array $rDetail): void {
		if (self::$rEntry !== null) {
			self::$rEntry['detail'] = array_merge(self::$rEntry['detail'], $rDetail);
		}
	}

	/** Write the current entry (at shutdown). */
	public static function finish(): void {
		$rEntry = self::$rEntry;
		if ($rEntry === null) {
			return;
		}
		self::$rEntry = null;
		try {
			$db = self::db();
			// The admin bootstrap closes the connection in an earlier shutdown
			// function (AdminShutdownStage): open it again, on the panel's database.
			if ($db instanceof Database && !$db->ping()) {
				$db->db_connect(false, true);
			}
			$db->query(
				'INSERT INTO `admin_audit` (`date`, `user_id`, `username`, `ip`, `source`, `action`, `result`, `detail`) VALUES (?, ?, ?, ?, ?, ?, ?, ?);',
				$rEntry['date'],
				$rEntry['user_id'],
				$rEntry['username'],
				$rEntry['ip'],
				$rEntry['source'],
				$rEntry['action'],
				self::outcome(self::$rHead),
				$rEntry['detail'] === [] ? null : json_encode($rEntry['detail'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			);
		} catch (\Throwable) {
			// The trail never breaks the action it records (a panel before migration 077).
		}
	}

	/**
	 * The outcome an answer states: the panel's JSON answers carry `result`
	 * (true/false) or `status` (STATUS_SUCCESS…); anything else is unknown.
	 */
	public static function outcome(string $rHead): ?int {
		if (preg_match('/^\s*\{\s*"result"\s*:\s*(true|false|1|0)\b/', $rHead, $rMatch)) {
			return in_array($rMatch[1], ['true', '1'], true) ? 1 : 0;
		}
		if (preg_match('/"status"\s*:\s*"(STATUS_[A-Z_]+)"/', $rHead, $rMatch)) {
			return str_starts_with($rMatch[1], 'STATUS_SUCCESS') ? 1 : 0;
		}
		return null;
	}

	/**
	 * The identifying fields of a request (DETAIL_KEYS, ID_KEY), in its order,
	 * each value cut to 100 characters and a list to its first 50 values.
	 *
	 * @param array<string, mixed> $rRequest
	 * @return array<string, string>
	 */
	public static function detail(array $rRequest): array {
		$rOut = [];
		foreach ($rRequest as $rKey => $rValue) {
			if (!is_string($rKey) || (!in_array($rKey, self::DETAIL_KEYS, true) && !preg_match(self::ID_KEY, $rKey)) || count($rOut) >= self::MAX_FIELDS) {
				continue;
			}
			if (is_array($rValue)) {
				$rValue = implode(',', array_slice(array_filter($rValue, 'is_scalar'), 0, 50));
			}
			if (is_scalar($rValue) && (string) $rValue !== '') {
				$rOut[$rKey] = mb_substr((string) $rValue, 0, 100);
			}
		}
		return $rOut;
	}

	/** Record on the command line too (tests). */
	public static function enable(bool $rEnabled): void {
		self::$rEnabled = $rEnabled;
	}

	/** Forget the current entry without writing it (tests). */
	public static function reset(): void {
		self::$rEntry = null;
		self::$rHead = '';
	}
}
