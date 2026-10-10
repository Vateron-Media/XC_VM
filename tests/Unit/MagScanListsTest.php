<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\BruteforceGuard;

/**
 * MAGSCAN Settings: the three lists of the page, kept in the
 * `magscan_settings` setting and read by the guard that counts MAC guesses.
 * A whitelisted MAC or address is never counted, and a blacklisted MAC blocks
 * the address that asks for it at its first request. The page saved nothing,
 * and nothing read the lists.
 */
final class MagScanListsTest extends TestCase {
	private const LISTS = ['whitelist_macs' => ['00:1A:79:AA:BB:CC'], 'blacklist_macs' => ['00:1A:79:00:00:01'], 'whitelist_ips' => ['203.0.113.9']];

	private static function settings(): array {
		return ['magscan_settings' => json_encode(self::LISTS)];
	}

	public function testWhatIsSavedIsValidUppercaseAndOnce(): void {
		$rClean = BruteforceGuard::magscanClean([
			'whitelist_macs' => ['00:1a:79:aa:bb:cc', '00:1A:79:AA:BB:CC', 'not a mac', ''],
			'blacklist_macs' => ['00-1A-79-00-00-01', ['x']],
			'whitelist_ips' => ['203.0.113.9', '999.1.1.1', '2001:db8::1', '203.0.113.9'],
			'something_else' => ['x'],
		]);
		$this->assertSame(['whitelist_macs' => ['00:1A:79:AA:BB:CC'], 'blacklist_macs' => ['00:1A:79:00:00:01'], 'whitelist_ips' => ['203.0.113.9', '2001:db8::1']], $rClean);
		$this->assertSame(['whitelist_macs' => [], 'blacklist_macs' => [], 'whitelist_ips' => []], BruteforceGuard::magscanClean([]), 'every list emptied');
	}

	public function testTheListsAreReadFromTheSetting(): void {
		$this->assertSame(self::LISTS, BruteforceGuard::magscanLists(self::settings()));
		foreach ([[], ['magscan_settings' => ''], ['magscan_settings' => 'not json'], ['magscan_settings' => '{"whitelist_macs":"x"}']] as $rSettings) {
			$this->assertSame(['whitelist_macs' => [], 'blacklist_macs' => [], 'whitelist_ips' => []], BruteforceGuard::magscanLists($rSettings));
		}
	}

	/** The portal hands the guard a digest of the MAC, the plugin API the MAC itself: both are recognised. */
	public function testAGuessIsBypassedBlockedOrCounted(): void {
		$rSettings = self::settings();
		$this->assertSame('bypass', BruteforceGuard::magscanVerdict($rSettings, '198.51.100.7', hash('sha256', '00:1A:79:AA:BB:CC')), 'a whitelisted MAC, as the portal names it');
		$this->assertSame('bypass', BruteforceGuard::magscanVerdict($rSettings, '198.51.100.7', '00:1A:79:AA:BB:CC'), 'as the plugin API names it');
		$this->assertSame('bypass', BruteforceGuard::magscanVerdict($rSettings, '198.51.100.7', hash('sha256', '00:1a:79:aa:bb:cc')), 'sent in lower case');
		$this->assertSame('bypass', BruteforceGuard::magscanVerdict($rSettings, '203.0.113.9', hash('sha256', '00:1A:79:00:00:01')), 'a whitelisted address, whatever it asks for');
		$this->assertSame('block', BruteforceGuard::magscanVerdict($rSettings, '198.51.100.7', hash('sha256', '00:1A:79:00:00:01')), 'a blacklisted MAC');
		$this->assertSame('count', BruteforceGuard::magscanVerdict($rSettings, '198.51.100.7', hash('sha256', '00:1A:79:12:34:56')), 'any other guess');
		$this->assertSame('count', BruteforceGuard::magscanVerdict([], '198.51.100.7', hash('sha256', '00:1A:79:00:00:01')), 'no lists');
	}

	/** The page takes the navbar's permission: no rule named it, and a page no rule names is open to every group. */
	public function testThePageNeedsItsPermission(): void {
		$rBefore = [$GLOBALS['db'] ?? null, $GLOBALS['rUserInfo'] ?? null, $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['db'] = new stdClass();
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
		try {
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['manage_tickets']];
			$this->assertFalse(\XcVm\Core\Auth\PageAuthorization::checkPermissions('magscan_settings', false));
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['manage_mag']];
			$this->assertTrue(\XcVm\Core\Auth\PageAuthorization::checkPermissions('magscan_settings', false));
		} finally {
			[$GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']] = $rBefore;
		}
	}

	/** The column the lists are kept in exists on a new panel and on an updated one. */
	public function testTheSettingHasItsColumn(): void {
		$this->assertStringContainsString('`magscan_settings` mediumtext', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'));
		$this->assertStringContainsString('ADD COLUMN IF NOT EXISTS `magscan_settings`', (string) file_get_contents(MAIN_HOME . 'migrations/database/up/089_add_magscan_settings.sql'));
	}
}
