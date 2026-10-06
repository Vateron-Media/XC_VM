<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\SessionManager;

/**
 * A login needs an enabled account (`users`.`status` = 1), and so does every
 * request of the session that login opened: disabling an administrator or a
 * reseller ends the session it has, on its next request.
 */
final class AuditAdminAuthzSessionTest extends TestCase {
	/** A `users` row as the session guards load it. */
	private const USER = ['id' => 9, 'username' => 'panel_user', 'password' => 'hash', 'member_group_id' => 2, 'status' => 1];

	/** Every status but 1: disabled, the same from the driver as a string, any other value, and none. */
	private const NOT_ENABLED = [0, '0', 2, null];

	protected function setUp(): void {
		$_SESSION['ip'] = $_SESSION['rip'] = '10.0.0.5';
		$_SESSION['verify'] = $_SESSION['rverify'] = md5('panel_user||hash');
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
	}

	protected function tearDown(): void {
		unset($_SESSION['ip'], $_SESSION['rip'], $_SESSION['verify'], $_SESSION['rverify'], $_SERVER['REMOTE_ADDR']);
	}

	public function testADisabledAdministratorLosesItsSession(): void {
		$rSettings = ['ip_logout' => 0, 'ip_subnet_match' => 0];
		$this->assertTrue(SessionManager::adminSessionValid(self::USER, ['is_admin' => 1], $rSettings), 'an enabled account');
		$this->assertTrue(SessionManager::adminSessionValid(['status' => '1'] + self::USER, ['is_admin' => 1], $rSettings), 'the status as the driver returns it');

		foreach (self::NOT_ENABLED as $rStatus) {
			$this->assertFalse(SessionManager::adminSessionValid(['status' => $rStatus] + self::USER, ['is_admin' => 1], $rSettings), 'status ' . var_export($rStatus, true));
		}
	}

	public function testADisabledResellerLosesItsSession(): void {
		// The reseller bootstrap decides it in one condition, after a framework
		// boot: that condition is run here as it stands there.
		$rSource = (string) file_get_contents(MAIN_HOME . 'Infrastructure/Bootstrap/ResellerScopeBootstrap.php');
		$this->assertSame(1, preg_match('/^\t+if \((!\$rUserInfo \|\| !\$rPermissions\[\'is_reseller\'\] .*)\) \{$/m', $rSource, $rGuard));

		$rEnds = static function (array $rUserInfo, array $rPermissions = ['is_reseller' => 1]) use ($rGuard): bool {
			$rIPMatch = true;
			$rSettings = ['ip_logout' => 0, 'ip_subnet_match' => 0];
			return (bool) eval('return ' . $rGuard[1] . ';');
		};

		$this->assertFalse($rEnds(self::USER), 'an enabled account');
		$this->assertFalse($rEnds(['status' => '1'] + self::USER), 'the status as the driver returns it');
		$this->assertTrue($rEnds(self::USER, ['is_reseller' => 0]), 'the checks it already made still end it');

		foreach (self::NOT_ENABLED as $rStatus) {
			$this->assertTrue($rEnds(['status' => $rStatus] + self::USER), 'status ' . var_export($rStatus, true));
		}
	}
}
