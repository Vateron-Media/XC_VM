<?php

use XcVm\Core\Auth\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * SessionManager::adminSessionValid — the admin session-integrity guard shared
 * by the HTML bootstrap (AdminScopeBootstrap) and the JSON table endpoint
 * (Admin\TableController). Seeds $_SESSION / $_SERVER (login IP, verify hash,
 * request IP) and asserts the accept/reject decision, including the ip_logout /
 * ip_subnet_match IP rules.
 */
final class SessionManagerTest extends TestCase {

	/** @var array<string, string|int> */
	private array $user;

	/** @var array<string, int> */
	private array $perms;

	protected function setUp(): void {
		$this->user = ['username' => 'admin', 'password' => 'secret', 'status' => 1];
		$this->perms = ['is_admin' => 1];
		$_SESSION['ip'] = '10.0.0.5';
		$_SESSION['verify'] = md5('admin||secret');
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
	}

	protected function tearDown(): void {
		unset($_SESSION['ip'], $_SESSION['verify'], $_SERVER['REMOTE_ADDR']);
	}

	/** @return array{ip_logout: int, ip_subnet_match: int} */
	private function settings(int $ipLogout = 0, int $subnet = 0): array {
		return ['ip_logout' => $ipLogout, 'ip_subnet_match' => $subnet];
	}

	public function testAcceptsAValidSession(): void {
		$this->assertTrue(SessionManager::adminSessionValid($this->user, $this->perms, $this->settings()));
	}

	public function testRejectsWhenUserMissing(): void {
		$this->assertFalse(SessionManager::adminSessionValid(null, $this->perms, $this->settings()));
	}

	public function testRejectsWhenPermissionsMissing(): void {
		$this->assertFalse(SessionManager::adminSessionValid($this->user, null, $this->settings()));
	}

	public function testRejectsWhenNotAdmin(): void {
		$this->assertFalse(SessionManager::adminSessionValid($this->user, ['is_admin' => 0], $this->settings()));
	}

	public function testRejectsWhenAccountDisabled(): void {
		$this->user['status'] = 0;
		$this->assertFalse(SessionManager::adminSessionValid($this->user, $this->perms, $this->settings()));
	}

	public function testRejectsARowWithoutItsStatus(): void {
		unset($this->user['status']);
		// PHP warns of the missing key: the answer is what is asked here.
		$this->assertFalse(@SessionManager::adminSessionValid($this->user, $this->perms, $this->settings()));
	}

	public function testRejectsWhenVerifyHashMismatches(): void {
		$_SESSION['verify'] = md5('admin||wrong');
		$this->assertFalse(SessionManager::adminSessionValid($this->user, $this->perms, $this->settings()));
	}

	public function testRejectsChangedIpWhenIpLogoutEnabled(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
		$this->assertFalse(SessionManager::adminSessionValid($this->user, $this->perms, $this->settings(1, 0)));
	}

	public function testAcceptsChangedHostInSameSubnetWhenSubnetMatch(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.9';
		$this->assertTrue(SessionManager::adminSessionValid($this->user, $this->perms, $this->settings(1, 1)));
	}

	public function testIgnoresIpChangeWhenIpLogoutDisabled(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
		$this->assertTrue(SessionManager::adminSessionValid($this->user, $this->perms, $this->settings(0, 0)));
	}
}
