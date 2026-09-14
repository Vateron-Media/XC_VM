<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A DatabaseHandler that answers the handful of statements activateCode issues
 * from in-memory tables, and never opens a connection.
 */
class ActivationScriptedDb extends DatabaseHandler {
	public array $codes = [];
	public array $lines = [];
	public array $packages = [];
	/** Returned by the next code lookup instead of the table row: a read taken before another request's write. */
	public ?array $staleCode = null;

	private array $rows = [];
	private int $affected = 0;

	public function __construct() {
		$this->dbh = true; // Database's helpers only test it for truthiness
	}

	public function query($query, $buffered = false) {
		$rBinds = array_slice(func_get_args(), 1);
		$this->rows = [];
		$this->affected = 0;

		if (str_contains($query, 'FROM `activation_codes` WHERE `activation_code` = ?')) {
			if ($this->staleCode !== null) {
				$this->rows = [$this->staleCode];
				$this->staleCode = null;
			} else {
				$this->rows = array_values(array_filter($this->codes, fn($r) => $r['activation_code'] === $rBinds[0]));
			}
		} elseif (str_contains($query, 'FROM `lines` WHERE `id` = ?')) {
			$this->rows = isset($this->lines[$rBinds[0]]) ? [$this->lines[$rBinds[0]]] : [];
		} elseif (str_contains($query, 'FROM `users_packages` WHERE `id` = ?')) {
			$this->rows = isset($this->packages[$rBinds[0]]) ? [$this->packages[$rBinds[0]]] : [];
		} elseif (str_starts_with(trim($query), 'UPDATE `activation_codes`')) {
			[$rAt, $rMac, $rDevice, $rID] = $rBinds;
			$rRow = &$this->codes[$rID];
			// The claim: when the statement carries the "still unactivated" guard,
			// a row another request already activated is left alone.
			if (str_contains($query, '(`status` = 1 OR `activated_at` IS NULL)') && $rRow['status'] != 1 && $rRow['activated_at'] !== null) {
				return true;
			}
			$rRow['status'] = 2;
			$rRow['activated_at'] = $rAt;
			$rRow['mac'] = $rMac ?? $rRow['mac'];
			$rRow['device_id'] = $rDevice ?? $rRow['device_id'];
			$this->affected = 1;
		} elseif (str_starts_with(trim($query), 'UPDATE `lines`')) {
			[$rExp, $rIP, $rNow, $rID] = $rBinds;
			$this->lines[$rID]['exp_date'] = $rExp;
			$this->affected = 1;
		}

		return true;
	}

	public function num_rows() {
		return $this->rows ? count($this->rows) : $this->affected;
	}

	public function get_row() {
		return $this->rows[0] ?? [];
	}
}

class ActiveCodeActivationTest extends TestCase {
	private ActivationScriptedDb $db;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$GLOBALS['rSettings'] = ['keep_protocol' => 0, 'use_mdomain_in_lists' => 0];
		$GLOBALS['rServers'] = [1 => [
			'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test',
			'server_ip' => '10.0.0.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'server_type' => 0,
		]];

		$this->db = new ActivationScriptedDb();
		$this->db->packages[3] = ['id' => 3, 'package_name' => 'P', 'official_duration' => 1, 'official_duration_in' => 'months'];
		$this->db->lines[7] = ['id' => 7, 'username' => 'u7', 'password' => 'p7', 'exp_date' => time() + 86400, 'max_connections' => 1];
		DatabaseFactory::set($this->db);
	}

	private function code(array $rOver): array {
		return array_merge([
			'id' => 1, 'activation_code' => 'ABCD234567', 'status' => 2, 'subscriber_id' => 7, 'package_id' => 3,
			'is_trial' => 0, 'mac' => null, 'device_id' => null, 'activated_at' => time() - 3600, 'dns_base' => '',
		], $rOver);
	}

	/** A code locked to a device must not hand its credentials to a request that simply names no device. */
	public function testLockedCodeRefusesARequestWithoutAMac(): void {
		$this->db->codes[1] = $this->code(['mac' => '00:1A:79:AA:BB:CC']);

		$rRes = ActiveCodeService::activateCode('ABCD234567', ['ip' => '192.0.2.9']);

		$this->assertSame('DEVICE_MISMATCH', $rRes['status']);
		$this->assertArrayNotHasKey('credentials', $rRes);
	}

	public function testLockedCodeServesItsOwnDevice(): void {
		$this->db->codes[1] = $this->code(['mac' => '00:1A:79:AA:BB:CC']);

		$rRes = ActiveCodeService::activateCode('abcd234567', ['mac' => '00:1a:79:aa:bb:cc']);

		$this->assertSame('SUCCESS', $rRes['status']);
		$this->assertSame('u7', $rRes['credentials']['username']);
		$this->assertFalse($rRes['is_new_activation']);
	}

	public function testUnboundCodeServesAnyone(): void {
		$this->db->codes[1] = $this->code([]);

		$this->assertSame('SUCCESS', ActiveCodeService::activateCode('ABCD234567', [])['status']);
	}

	public function testFirstActivationBindsTheDeviceAndStartsTheCountdown(): void {
		$this->db->codes[1] = $this->code(['status' => 1, 'activated_at' => null]);
		$this->db->lines[7]['exp_date'] = null;

		$rRes = ActiveCodeService::activateCode('ABCD234567', ['mac' => '00:1A:79:AA:BB:CC']);

		$this->assertSame('SUCCESS', $rRes['status']);
		$this->assertTrue($rRes['is_new_activation']);
		$this->assertSame('00:1A:79:AA:BB:CC', $this->db->codes[1]['mac']);
		$this->assertGreaterThan(time() + 27 * 86400, $this->db->lines[7]['exp_date']);
	}

	/**
	 * Two requests read a fresh code at once. The first binds its device; the
	 * second must not re-bind the code to itself or restart the countdown — it
	 * meets the lock like any later request.
	 */
	public function testTheLoserOfAnActivationRaceMeetsTheLock(): void {
		$rActivatedAt = time() - 2;
		$rExp = time() + 30 * 86400 - 2;
		$this->db->codes[1] = $this->code(['mac' => '00:1A:79:AA:BB:CC', 'activated_at' => $rActivatedAt]);
		$this->db->lines[7]['exp_date'] = $rExp;
		$this->db->staleCode = $this->code(['status' => 1, 'activated_at' => null]); // what the loser read

		$rRes = ActiveCodeService::activateCode('ABCD234567', ['mac' => '00:1A:79:DD:EE:FF']);

		$this->assertSame('DEVICE_MISMATCH', $rRes['status']);
		$this->assertSame('00:1A:79:AA:BB:CC', $this->db->codes[1]['mac'], 'the winner keeps the device');
		$this->assertSame($rActivatedAt, $this->db->codes[1]['activated_at']);
		$this->assertSame($rExp, $this->db->lines[7]['exp_date'], 'the countdown is not restarted');
	}
}
