<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\BlocklistChanges;
use XcVm\Domain\Cluster\BlocklistDelta;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The blocklist's delta (cluster plan, Phase 7): every block and unblock path
 * logs the key it changed in `cluster_changes`, and MAIN serves a node what
 * changed since the last id it applied, with each key's current row or its
 * removal; a bulk change or a gap sends the node back to a reload.
 */
final class BlocklistDeltaTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_changes` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `section` varchar(32), `op` varchar(8), `kind` varchar(16), `value` varchar(255), `time` int)');
		$this->rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39) UNIQUE, `notes` text, `date` int)');
		$this->rDb->exec('CREATE TABLE `blocked_uas` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_agent` varchar(255), `exact_match` int DEFAULT 0, `attempts_blocked` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `blocked_isps` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `isp` text, `blocked` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `blocked_asns` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `asn` int, `blocked` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `rtmp_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(255), `password` varchar(128), `notes` text, `push` int, `pull` int)');
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	private function lastID(): int {
		$this->rDb->query('SELECT MAX(`id`) AS `hi` FROM `cluster_changes`');
		return (int) $this->rDb->get_row()['hi'];
	}

	public function testIPsTravelAsAddsAndRemovesAndOtherKindsAsAReload(): void {
		$this->rDb->exec("INSERT INTO `blocked_ips` (`ip`) VALUES ('203.0.113.1')");
		BlocklistChanges::set('ip', ['203.0.113.1']);
		$rSince = $this->lastID();

		$this->rDb->exec("INSERT INTO `blocked_ips` (`ip`) VALUES ('203.0.113.2'), ('203.0.113.3')");
		BlocklistChanges::set('ip', ['203.0.113.2', '203.0.113.3']);
		$this->rDb->exec("DELETE FROM `blocked_ips` WHERE `ip` = '203.0.113.3'"); // blocked, then unblocked
		BlocklistChanges::del('ip', ['203.0.113.3']);
		$this->rDb->exec("DELETE FROM `blocked_ips` WHERE `ip` = '203.0.113.1'");
		BlocklistChanges::del('ip', ['203.0.113.1']);
		BlocklistChanges::set('nope', ['x']);

		$rOut = BlocklistDelta::since($rSince);
		$this->assertFalse($rOut['full']);
		$this->assertFalse($rOut['more']);
		$this->assertSame($this->lastID(), $rOut['last']);
		$this->assertSame([], $rOut['reload']);
		$this->assertSame(['203.0.113.2'], $rOut['add']);
		$this->assertEqualsCanonicalizing(['203.0.113.1', '203.0.113.3'], $rOut['remove']);

		$this->rDb->exec("INSERT INTO `blocked_uas` (`user_agent`, `exact_match`) VALUES ('curl', 1)");
		BlocklistChanges::set('ua', [1]);
		BlocklistChanges::set('rtmp', [1]);
		$rOut = BlocklistDelta::since($rOut['last']);
		$this->assertSame(['ua', 'rtmp'], $rOut['reload'], 'rare hand edits: the node takes the section again');

		$this->assertSame(['last' => $rOut['last'], 'more' => false, 'full' => false, 'reload' => [], 'add' => [], 'remove' => []], BlocklistDelta::since($rOut['last']), 'nothing new');
	}

	public function testTheSnapshotHoldsWhatEachKindNeeds(): void {
		$this->rDb->exec("INSERT INTO `blocked_ips` (`ip`) VALUES ('203.0.113.2'), ('203.0.113.1')");
		$this->rDb->exec("INSERT INTO `blocked_uas` (`user_agent`, `exact_match`) VALUES ('curl', 1)");
		$this->rDb->exec("INSERT INTO `blocked_isps` (`isp`, `blocked`) VALUES ('isp', 1)");
		$this->rDb->exec("INSERT INTO `rtmp_ips` (`ip`, `password`, `push`, `pull`) VALUES ('198.51.100.9', 'pw', 1, 0)");
		$this->rDb->exec("INSERT INTO `blocked_asns` (`asn`, `blocked`) VALUES (64500, 1), (64501, 0)");
		$this->assertSame([
			'ip' => ['203.0.113.1', '203.0.113.2'],
			'ua' => [['id' => 1, 'user_agent' => 'curl', 'exact_match' => 1]],
			'isp' => [['id' => 1, 'isp' => 'isp', 'blocked' => 1]],
			'asn' => [64500],
			// The node checks RTMP publishers against the password.
			'rtmp' => [['id' => 1, 'ip' => '198.51.100.9', 'password' => 'pw', 'push' => 1, 'pull' => 0]],
		], BlocklistDelta::snapshot());
	}

	public function testABulkChangeReloadsItsKindAndLongListsBecomeOne(): void {
		BlocklistChanges::set('ip', ['203.0.113.1']);
		$rSince = $this->lastID();
		BlocklistChanges::set('asn', [7]);
		BlocklistChanges::reset('asn');
		BlocklistChanges::del('ip', array_map(static fn(int $i): string => '10.0.' . intdiv($i, 256) . '.' . ($i % 256), range(1, BlocklistChanges::MAX_KEYS + 1)));
		$rOut = BlocklistDelta::since($rSince);
		$this->assertEqualsCanonicalizing(['asn', 'ip'], $rOut['reload']);
		$this->assertSame([], $rOut['add']);
		$this->assertSame($rSince + 3, $rOut['last'], 'one row each');

		$rPaged = BlocklistDelta::since($rSince, 2);
		$this->assertTrue($rPaged['more']);
		$this->assertSame($rSince + 2, $rPaged['last']);
	}

	public function testAGapOrNoStartMeansAFullReload(): void {
		$this->assertTrue(BlocklistDelta::since(5)['full'], 'nothing logged yet');
		foreach (range(1, 4) as $i) {
			BlocklistChanges::set('ip', ['203.0.113.' . $i]);
		}
		$this->assertTrue(BlocklistDelta::since(0)['full'], 'a new node');
		$this->assertTrue(BlocklistDelta::since(9)['full'], 'the log went back (a restore)');
		$this->assertFalse(BlocklistDelta::since(2)['full']);

		// Old rows go, but never the newest: a quiet week forces no reload.
		$this->rDb->exec('UPDATE `cluster_changes` SET `time` = 1000');
		BlocklistDelta::prune(1000 + BlocklistDelta::KEEP_DAYS * 86400 + 1);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_changes`');
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
		$this->assertTrue(BlocklistDelta::since(2)['full'], 'pruned past it');
		$this->assertFalse(BlocklistDelta::since(4)['full'], 'up to date');
		$this->assertFalse(BlocklistDelta::since(3)['full'], 'the next one is still there');

		$rSnap = BlocklistDelta::snapshot(['ip', 'rtmp']);
		$this->assertSame(['ip', 'rtmp'], array_keys($rSnap));
	}

	public function testTheAdminsDeletesAreLogged(): void {
		$this->rDb->exec("INSERT INTO `blocked_ips` (`ip`) VALUES ('203.0.113.1')");
		$this->rDb->exec("INSERT INTO `blocked_uas` (`user_agent`) VALUES ('curl')");
		$this->rDb->exec("INSERT INTO `blocked_isps` (`isp`, `blocked`) VALUES ('isp', 1)");
		$this->rDb->exec("INSERT INTO `rtmp_ips` (`ip`) VALUES ('198.51.100.9')");
		if (!defined('FLOOD_TMP_PATH')) {
			define('FLOOD_TMP_PATH', sys_get_temp_dir() . '/');
		}
		BlocklistChanges::set('ip', ['seed']);
		$rSince = $this->lastID();
		$this->assertTrue(BlocklistService::deleteBlockedIP(1));
		$this->assertTrue(BlocklistService::deleteBlockedUA(1));
		$this->assertTrue(BlocklistService::deleteBlockedISP(1));
		$this->assertTrue(BlocklistService::deleteRTMPIP(1));
		$rOut = BlocklistDelta::since($rSince);
		$this->assertSame(['203.0.113.1'], $rOut['remove']);
		$this->assertSame(['ua', 'isp', 'rtmp'], $rOut['reload']);
	}

	/**
	 * Every file that writes a blocklist table logs the change. A new path
	 * fails here until it calls BlocklistChanges (or is listed with why not).
	 */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testEveryWriterOfTheBlocklistLogsIt(): void {
		$rSrc = rtrim(MAIN_HOME, '/');
		$rExempt = [
			// Upserts reference columns and prunes unblocked ASNs: blocked ones never change.
			'Core/GeoIP/AsnCatalogSync.php',
		];
		$rMissing = [];
		$rIt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rSrc, FilesystemIterator::SKIP_DOTS));
		foreach ($rIt as $rFile) {
			$rPath = (string) $rFile;
			if (!str_ends_with($rPath, '.php') || str_contains($rPath, '/vendor/') || str_contains($rPath, '/bin/install/')) {
				continue;
			}
			$rRel = substr($rPath, strlen($rSrc) + 1);
			$rCode = (string) file_get_contents($rPath);
			if (!preg_match('/(INSERT INTO|REPLACE INTO|DELETE FROM|DELETE `b` FROM|UPDATE|TRUNCATE)\s+`(blocked_ips|blocked_uas|blocked_isps|blocked_asns|rtmp_ips)`/', $rCode)) {
				continue;
			}
			if (in_array($rRel, $rExempt, true) || str_contains($rCode, 'BlocklistChanges::')) {
				continue;
			}
			$rMissing[] = $rRel;
		}
		$this->assertSame([], $rMissing, 'writes the blocklist without logging it in cluster_changes');
	}
}
