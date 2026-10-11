<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Cluster\NodeRole;

/**
 * ReportIssueController — Report an Issue (admin/report_issue.php): a short form
 * that opens the right GitHub issue form, signed in as the administrator's own
 * GitHub account, with the panel version and environment already filled in.
 *
 * The panel creates nothing on GitHub itself — it has no token, and an
 * open-source panel could not keep one secret. The page builds a link to
 * github.com/Vateron-Media/XC_VM/issues/new?template=... and the browser opens
 * it; the administrator reviews and submits there. Nothing is sent anywhere
 * else. Blank issues are disabled on the repository, so each category links to
 * its issue form (the fields the form asks for are prefilled by their id).
 *
 * Security vulnerabilities are not filed here: the repository routes them to a
 * private advisory, and the page links there instead.
 *
 * Route: GET /admin/report_issue → index()
 *
 * @renders Views/admin/report_issue.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ReportIssueController extends BaseAdminController {
	/** The repository the issues belong to. */
	public const REPO = 'Vateron-Media/XC_VM';

	public function index(): void {
		$this->requirePermission();

		$this->setTitle('Report an Issue');
		$this->render('report_issue', [
			'rRepo' => self::REPO,
			'rEnvironment' => self::environment(),
		]);
	}

	/**
	 * What the panel knows about its own install, for the GitHub forms: the
	 * version, the build type as the Bug form's dropdown spells it, and a free
	 * text block for the form's "Environment" field.
	 *
	 * @return array{version: string, build_type: string, environment: string}
	 */
	private static function environment(): array {
		$rVersion = defined('XC_VM_VERSION') ? (string) XC_VM_VERSION : '';
		$rBuild = NodeRole::isMain() ? 'MAIN (single server)' : 'LoadBalancer (multi-server)';

		$rLines = [
			'XC_VM: ' . ($rVersion !== '' ? $rVersion : 'unknown') . ' (' . $rBuild . ')',
			'OS: ' . trim(php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m')),
			'PHP: ' . PHP_VERSION,
		];
		$rDbVersion = self::dbVersion();
		if ($rDbVersion !== '') {
			$rLines[] = 'Database: ' . $rDbVersion;
		}

		return [
			'version' => $rVersion,
			'build_type' => $rBuild,
			'environment' => implode("\n", $rLines),
		];
	}

	/** The database server's version, or '' when it cannot be read. */
	private static function dbVersion(): string {
		$rDb = $GLOBALS['db'] ?? null;
		if (!is_object($rDb) || !method_exists($rDb, 'query') || !method_exists($rDb, 'get_row')) {
			return '';
		}
		try {
			if ($rDb->query('SELECT VERSION() AS `v`') === false) {
				return '';
			}
			$rRow = $rDb->get_row();
		} catch (\Throwable) {
			return '';
		}
		return is_array($rRow) ? (string) ($rRow['v'] ?? '') : '';
	}
}
