<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Logging\FileLogger;

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

	/** The tail of the panel error log the page may attach: at most these lines and bytes. */
	private const LOG_MAX_LINES = 40;

	private const LOG_MAX_BYTES = 4000;

	public function index(): void {
		$this->requirePermission();

		// The error log is server data and may hold secrets: only an admin who
		// may already read it (panel_logs) can attach a tail, and that tail is
		// redacted and capped. Everyone else gets the version/environment only.
		$rCanAttachLog = Authorization::check('adv', 'panel_logs');

		$this->setTitle('Report an Issue');
		$this->render('report_issue', [
			'rRepo' => self::REPO,
			'rEnvironment' => self::environment(),
			'rCanAttachLog' => $rCanAttachLog,
			'rLog' => $rCanAttachLog ? self::errorLogTail() : '',
		]);
	}

	/**
	 * The last lines of the panel error log (FileLogger), with obvious secrets
	 * redacted and the whole thing capped — it rides in a URL to a public issue,
	 * and the administrator reviews it on the page before it goes.
	 */
	public static function errorLogTail(): string {
		$rFile = FileLogger::getLogFile();
		if (!is_file($rFile) || !is_readable($rFile)) {
			return '';
		}
		// The end of the file only: a log can be large, so read the last chunk.
		$rSize = (int) filesize($rFile);
		$rChunk = 64 * 1024;
		$rText = (string) @file_get_contents($rFile, false, null, max(0, $rSize - $rChunk));
		if ($rText === '') {
			return '';
		}
		$rLines = preg_split('/\r?\n/', rtrim($rText, "\r\n")) ?: [];
		$rLines = array_slice($rLines, -self::LOG_MAX_LINES);
		$rOut = self::redact(implode("\n", $rLines));

		if (strlen($rOut) > self::LOG_MAX_BYTES) {
			// Keep the most recent bytes; the rest is on the server for the admin.
			$rOut = "… (older lines left out — attach the full log on GitHub if needed)\n" . substr($rOut, -self::LOG_MAX_BYTES);
		}
		return $rOut;
	}

	/**
	 * Redact the obvious secrets from a log excerpt: a value after a password,
	 * secret, token, key or authorization label, and the password in a DSN. The
	 * administrator still reviews the result; this is the floor, not the ceiling.
	 */
	public static function redact(string $rText): string {
		// `Bearer <token>` / `Basic <token>` first: the secret is the next word, which
		// the keyed rule below would otherwise skip over (it stops at "Bearer").
		$rText = (string) preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._\-\/+=]+/i', '$1 [redacted]', $rText);
		// key = value / key: value / key"=>"value, the value to the next space, quote, comma or separator.
		$rText = (string) preg_replace(
			'/((?:pass(?:word)?|passwd|pwd|secret|token|api[_-]?key|authorization|bearer|access[_-]?key)["\']?\s*(?:=>|[:=])\s*["\']?)[^\s"\',;)&]+/i',
			'$1[redacted]',
			$rText
		);
		// user:password@host in a URL/DSN.
		$rText = (string) preg_replace('#(://[^:/\s@]+:)[^@\s/]+(@)#', '$1[redacted]$2', $rText);
		return $rText;
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
