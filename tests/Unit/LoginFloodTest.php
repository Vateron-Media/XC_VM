<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Keeps login_logs in memory and answers the statements a login and the flood check issue. */
class LoginLogDb extends DatabaseHandler {
	public array $logs = [];
	public array $user;
	private array $rows = [];

	public function __construct() {
		$this->dbh = true;
	}

	public function query($query, $buffered = false) {
		$rBinds = array_slice(func_get_args(), 1);
		$this->rows = [];
		if (str_contains($query, 'FROM `users` WHERE `username` = ?')) {
			$this->rows = [$this->user];
		} elseif (str_contains($query, 'FROM `access_codes` WHERE `code` = ?')) {
			$this->rows = [['id' => 1, 'groups' => json_encode([1])]];
		} elseif (str_contains($query, 'INSERT INTO `login_logs`')) {
			// VALUES('ADMIN'|'RESELLER', access_code, user_id?, status, ip, date)
			$rStatus = count($rBinds) === 4 ? $rBinds[1] : $rBinds[2];
			$this->logs[] = ['status' => $rStatus, 'ip' => $rBinds[count($rBinds) - 2], 'date' => $rBinds[count($rBinds) - 1]];
		} elseif (str_contains($query, 'COUNT(`id`) AS `count` FROM `login_logs`')) {
			// The query the pages used, TIME_TO_SEC(TIMEDIFF(NOW(), `date`)), is NULL
			// for an integer `date` on MariaDB 11.4 — no row ever counted.
			$rCount = 0;
			if (str_contains($query, '`date` >= ?')) {
				[$rIP, $rSince] = $rBinds;
				foreach ($this->logs as $rLog) {
					$rCount += (int) ($rLog['status'] === 'INVALID_LOGIN' && $rLog['ip'] === $rIP && $rLog['date'] >= $rSince);
				}
			}
			$this->rows = [['count' => $rCount]];
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
 * The admin and reseller login pages block an address after login_flood failed
 * sign-ins in a day. They counted with a date expression that is NULL for the
 * integer `date` column, so the count was always 0; and failures were only
 * recorded when "save login logs" was on. Either way, no address was blocked.
 */
class LoginFloodTest extends TestCase {
	private LoginLogDb $db;

	protected function setUp(): void {
		foreach (['STATUS_FAILURE' => 0, 'STATUS_SUCCESS' => 1] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
		$_SERVER['XC_CODE'] = 'panel';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$GLOBALS['rSettings'] = ['recaptcha_enable' => 0, 'save_login_logs' => 0];
		$this->db = new LoginLogDb();
		$this->db->user = ['id' => 4, 'username' => 'boss', 'password' => Authenticator::hashPassword('s3cret', 'fixedsalt', 1000), 'member_group_id' => 1, 'status' => 1];
		$GLOBALS['db'] = $this->db;
		DatabaseFactory::set($this->db);
	}

	public function testFailedSignInsBlockTheAddressEvenWithLoginLogsOff(): void {
		Authenticator::login(['username' => 'boss', 'password' => 'guess1'], true);
		Authenticator::resellerLogin(['username' => 'boss', 'password' => 'guess2']);
		$this->assertFalse(Authenticator::loginFloodExceeded('192.0.2.10', 3));

		Authenticator::login(['username' => 'boss', 'password' => 'guess3'], true);
		$this->assertTrue(Authenticator::loginFloodExceeded('192.0.2.10', 3), 'three failures recorded: ' . count($this->db->logs));
		$this->assertFalse(Authenticator::loginFloodExceeded('198.51.100.7', 3), 'another address');
	}

	public function testOnlyTheLastDayCounts(): void {
		$this->db->logs = [
			['status' => 'INVALID_LOGIN', 'ip' => '192.0.2.10', 'date' => time() - 90000],
			['status' => 'INVALID_LOGIN', 'ip' => '192.0.2.10', 'date' => time() - 90000],
			['status' => 'INVALID_LOGIN', 'ip' => '192.0.2.10', 'date' => time() - 60],
		];
		$this->assertFalse(Authenticator::loginFloodExceeded('192.0.2.10', 2));
	}

	public function testALimitOfZeroTurnsItOff(): void {
		$this->db->logs = array_fill(0, 50, ['status' => 'INVALID_LOGIN', 'ip' => '192.0.2.10', 'date' => time()]);
		$this->assertFalse(Authenticator::loginFloodExceeded('192.0.2.10', 0));
	}
}
