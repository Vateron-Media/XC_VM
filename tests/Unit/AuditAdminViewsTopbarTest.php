<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Module\TopbarRegistry;
use XcVm\Core\Reference\PermissionReference;
use XcVm\Core\Util\Topbar;

/**
 * The per-page topbar offers an administrator whose group lists advanced
 * permissions what that group may use: the link to the cache page with the
 * permission that opens the page, and the export buttons with the permission
 * the export itself asks for (BackupAjaxController::report). Every entry names
 * a permission a group can be given: a key no group can hold shows its entry
 * to group 1 alone.
 */
final class AuditAdminViewsTopbarTest extends TestCase {
	/** The pages whose topbar links to the cache page. */
	private const LINKING_TO_CACHE = ['backups', 'settings', 'modules'];

	/** Log pages that offer the export, each with the permission that opens it. */
	private const EXPORT_PAGES = ['login_logs' => 'login_logs', 'client_logs' => 'client_request_log', 'live_connections' => 'live_connections'];

	private const EXPORT = ['Export as CSV', 'Export as JSON'];

	protected function setUp(): void {
		$GLOBALS['db'] = new stdClass();
	}

	protected function tearDown(): void {
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['db']);
	}

	/**
	 * Signs in an administrator whose group lists $rAdvanced.
	 *
	 * @param array<string> $rAdvanced
	 */
	private function signIn(array $rAdvanced, int $rGroup = 5): void {
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => $rGroup];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => array_values($rAdvanced)];
	}

	/** @return list<string> the labels the page's topbar shows the signed-in administrator */
	private function labels(string $rPage): array {
		return array_column(Topbar::items($rPage), 'label');
	}

	public function testTheLinkToTheCachePageTakesThePermissionOfThePage(): void {
		foreach (self::LINKING_TO_CACHE as $rPage) {
			$this->signIn(['database', 'settings']);
			$this->assertTrue(PageAuthorization::checkPermissions('cache', false));
			$this->assertContains('Cache Settings', $this->labels($rPage), $rPage . ' with database');

			$this->signIn(array_diff(PermissionReference::keys(), ['database']));
			$this->assertFalse(PageAuthorization::checkPermissions('cache', false));
			$this->assertNotContains('Cache Settings', $this->labels($rPage), $rPage . ' with every permission but database');

			$this->signIn(['ticket'], 1);
			$this->assertContains('Cache Settings', $this->labels($rPage), $rPage . ' for group 1');
			$this->signIn([]);
			$this->assertContains('Cache Settings', $this->labels($rPage), $rPage . ' for a group that lists no permissions');
		}
	}

	public function testTheExportButtonsTakeThePermissionOfTheExport(): void {
		foreach (self::EXPORT_PAGES as $rPage => $rKey) {
			$this->signIn([$rKey, 'database']);
			$this->assertSame(self::EXPORT, array_values(array_intersect($this->labels($rPage), self::EXPORT)), $rPage . ' with database');

			$this->signIn(array_diff(PermissionReference::keys(), ['database']));
			$this->assertSame([], array_intersect($this->labels($rPage), self::EXPORT), $rPage . ' with every permission but database');

			$this->signIn(['ticket'], 1);
			$this->assertSame(self::EXPORT, array_values(array_intersect($this->labels($rPage), self::EXPORT)), $rPage . ' for group 1');
		}

		// A content table offers no export, whatever the group holds.
		$this->signIn(PermissionReference::keys());
		$this->assertSame([], array_intersect($this->labels('streams'), self::EXPORT));
	}

	public function testEveryEntryNamesAPermissionAGroupCanHold(): void {
		$rUnknown = [];
		$rChecked = 0;
		foreach (Topbar::config() as $rPage => $rEntries) {
			// The core list: what a module added for the page is the module's own.
			foreach (array_diff_key($rEntries, TopbarRegistry::forPage($rPage)) as $rLabel => $rEntry) {
				if (!empty($rEntry[1])) {
					$rChecked++;
					if (!in_array($rEntry[1], PermissionReference::keys(), true)) {
						$rUnknown[] = $rPage . ' / ' . $rLabel . ': ' . $rEntry[1];
					}
				}
			}
		}

		preg_match_all('/[\'"]adv[\'"]\s*,\s*[\'"](\w+)[\'"]/', (string) file_get_contents(MAIN_HOME . 'Core/Util/Topbar.php'), $rPairs);
		foreach (array_diff($rPairs[1], PermissionReference::keys()) as $rKey) {
			$rUnknown[] = 'the export buttons: ' . $rKey;
		}

		$this->assertGreaterThan(200, $rChecked);
		$this->assertNotSame([], $rPairs[1]);
		$this->assertSame([], $rUnknown);
	}
}
