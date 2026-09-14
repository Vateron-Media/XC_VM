<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Answers the statements a panel login issues, from fixed rows; never connects. */
class LoginScriptedDb extends DatabaseHandler {
	public array $user;
	public array $group;
	private array $rows = [];

	public function __construct() {
		$this->dbh = true;
	}

	public function query($query, $buffered = false) {
		$this->rows = [];
		if (str_contains($query, 'FROM `users` WHERE `username` = ?')) {
			$this->rows = [$this->user];
		} elseif (str_contains($query, 'FROM `access_codes` WHERE `code` = ?')) {
			$this->rows = [['id' => 1, 'code' => 'panel', 'groups' => json_encode([$this->user['member_group_id']])]];
		} elseif (str_contains($query, 'COUNT(*) AS `count` FROM `access_codes`')) {
			$this->rows = [['count' => 1]];
		} elseif (str_contains($query, 'FROM `users_groups` WHERE `group_id` = ?')) {
			$this->rows = [$this->group];
		}
		return true;
	}

	public function num_rows() {
		return count($this->rows);
	}

	public function get_row() {
		return $this->rows[0] ?? [];
	}
}

/**
 * A session id the visitor brought to the login form must not be the one that
 * carries the signed-in session: whoever planted it (a sibling-subdomain
 * cookie, a shared kiosk) would be signed in too.
 *
 * Each test runs in its own process: PHP refuses to set a session id once
 * output has started, and a runner prints between tests.
 */
#[RunTestsInSeparateProcesses]
class LoginSessionFixationTest extends TestCase {
	private LoginScriptedDb $db;

	protected function setUp(): void {
		foreach (['STATUS_FAILURE' => 0, 'STATUS_SUCCESS' => 1, 'STATUS_DISABLED' => 5, 'STATUS_NOT_ADMIN' => 6, 'STATUS_INVALID_CAPTCHA' => 12, 'STATUS_INVALID_CODE' => 13, 'STATUS_NOT_RESELLER' => 35] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		$_SERVER['XC_CODE'] = 'panel';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$GLOBALS['rSettings'] = ['recaptcha_enable' => 0, 'save_login_logs' => 0];

		$this->db = new LoginScriptedDb();
		$this->db->user = ['id' => 4, 'username' => 'boss', 'password' => Authenticator::hashPassword('s3cret', 'fixedsalt', 1000), 'member_group_id' => 1, 'status' => 1];
		$GLOBALS['db'] = $this->db;
		DatabaseFactory::set($this->db);

		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		ini_set('session.save_path', sys_get_temp_dir());
		session_id('plantedbyattacker0123456789');
		@session_start();
	}

	protected function tearDown(): void {
		@session_destroy();
	}

	public function testAdminLoginIssuesAFreshSessionId(): void {
		$this->db->group = ['group_id' => 1, 'is_admin' => 1, 'is_reseller' => 0, 'subresellers' => ''];

		$rRes = Authenticator::login(['username' => 'boss', 'password' => 's3cret'], true);

		$this->assertSame(STATUS_SUCCESS, $rRes['status']);
		$this->assertSame(4, $_SESSION['hash']);
		$this->assertTrue(session_id() !== 'plantedbyattacker0123456789', 'the planted session id survived the login');
	}

	public function testResellerLoginIssuesAFreshSessionId(): void {
		$this->db->group = ['group_id' => 1, 'is_admin' => 0, 'is_reseller' => 1, 'subresellers' => ''];

		$rRes = Authenticator::resellerLogin(['username' => 'boss', 'password' => 's3cret']);

		$this->assertSame(STATUS_SUCCESS, $rRes['status']);
		$this->assertSame(4, $_SESSION['reseller']);
		$this->assertTrue(session_id() !== 'plantedbyattacker0123456789', 'the planted session id survived the login');
	}

	/** A failed login changes nothing about the session. */
	public function testFailedLoginKeepsTheSession(): void {
		$this->db->group = ['group_id' => 1, 'is_admin' => 1, 'is_reseller' => 0, 'subresellers' => ''];

		$rRes = Authenticator::login(['username' => 'boss', 'password' => 'wrong'], true);

		$this->assertSame(STATUS_FAILURE, $rRes['status']);
		$this->assertArrayNotHasKey('hash', $_SESSION);
	}
}
