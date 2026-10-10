<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A range entered on Blocked IPs ("Block IP / CIDR") is blocked. It was
 * accepted and listed, and enforced nowhere: the firewall sync takes single
 * addresses only. An IPv4 range of a /16 or narrower now goes into the
 * panel's ipset set, which takes it expanded; what cannot be enforced (an
 * IPv6 range, a wider one) is refused when entered, and so is a range that
 * holds one of the panel's own servers or the administrator's own address.
 */
final class BlockedRangeTest extends TestCase {
	private TestDb $rDb;
	private array $rGlobals;

	protected function setUp(): void {
		defined('STATUS_INVALID_IP') || define('STATUS_INVALID_IP', 9);
		if (!defined('FLOOD_TMP_PATH')) {
			$rFlood = sys_get_temp_dir() . '/xcvm-flood-' . getmypid() . '/';
			@mkdir($rFlood);
			define('FLOOD_TMP_PATH', $rFlood);
		}
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_changes` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `section` varchar(32), `op` varchar(8), `kind` varchar(16), `value` varchar(255), `at` int)');
		$this->rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39) UNIQUE, `notes` text, `date` int)');
		DatabaseFactory::set($this->rDb);
		$rOwn = new ReflectionProperty(BlocklistService::class, 'db');
		$this->rGlobals = [$rOwn->getValue(), $GLOBALS['rServers'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null];
		$rOwn->setValue(null, $this->rDb);
		$GLOBALS['rServers'] = [1 => ['id' => 1, 'server_ip' => '45.67.89.10', 'private_ip' => '10.0.0.1'], 2 => ['id' => 2, 'server_ip' => '45.80.1.2', 'private_ip' => '']];
		$_SERVER['REMOTE_ADDR'] = '91.200.3.4';
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		(new ReflectionProperty(BlocklistService::class, 'db'))->setValue(null, $this->rGlobals[0]);
		$GLOBALS['rServers'] = $this->rGlobals[1];
		$_SERVER['REMOTE_ADDR'] = $this->rGlobals[2];
	}

	public function testARangeThePanelBlocks(): void {
		$this->assertSame('45.67.0.0/16', RootSignalsCronJob::blockRange('45.67.89.10/16'), 'its network address');
		$this->assertSame('8.8.8.0/24', RootSignalsCronJob::blockRange('8.8.8.0/24'));
		foreach (['8.0.0.0/8' => 'wider than a /16', '8.8.8.8/32' => 'one address', '8.8.8.8' => 'one address', '10.1.0.0/16' => 'private', '127.0.0.0/16' => 'loopback', '2001:4860::/32' => 'IPv6', '8.8.8.0/33' => 'no such prefix', '8.8.8.0/2x' => 'not a prefix', 'x/24' => 'not an address'] as $rEntry => $rWhy) {
			$this->assertNull(RootSignalsCronJob::blockRange((string) $rEntry), $rWhy);
		}
	}

	/** What the sets are given: an address as it is, a range by its network, and nothing for the rest. */
	public function testWhatASetIsGiven(): void {
		$this->assertSame(['iptables', '8.8.8.8'], RootSignalsCronJob::setEntry('8.8.8.8'));
		$this->assertSame(['iptables', '8.8.8.0/24'], RootSignalsCronJob::setEntry('8.8.8.77/24'));
		$this->assertSame(['ip6tables', '2001:4860::1'], RootSignalsCronJob::setEntry('2001:4860::1'));
		$this->assertNull(RootSignalsCronJob::setEntry('2001:4860::/32'), 'an IPv6 range: a hash:ip set refuses it, and the whole refill with it');
		$this->assertNull(RootSignalsCronJob::setEntry('10.1.2.3'));
	}

	public function testARangeIsSavedByItsNetwork(): void {
		$this->assertSame(STATUS_SUCCESS, BlocklistService::blockIP(['ip' => '8.8.8.77/24', 'notes' => 'a range'])['status']);
		$this->assertSame(STATUS_SUCCESS, BlocklistService::blockIP(['ip' => '9.9.9.9/32', 'notes' => 'one address'])['status']);
		$this->rDb->query('SELECT `ip` FROM `blocked_ips` ORDER BY `id` ASC;');
		$this->assertSame(['8.8.8.0/24', '9.9.9.9'], array_column($this->rDb->get_rows(), 'ip'));
	}

	public function testWhatCannotBeEnforcedIsRefused(): void {
		foreach (['2001:4860::/32' => 'an IPv6 range', '8.0.0.0/8' => 'wider than a /16', '45.67.0.0/16' => 'holds a server of the panel', '45.80.1.0/24' => 'holds another', '91.200.3.0/24' => 'holds the administrator\'s own address'] as $rEntry => $rWhy) {
			$this->assertSame(STATUS_INVALID_IP, BlocklistService::blockIP(['ip' => (string) $rEntry, 'notes' => ''])['status'], $rWhy);
		}
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `blocked_ips`;');
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
	}
}
