<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Tests\Support\AgentUser;

/**
 * ConnectAudit — the trace behind the cluster plan's cutover gate (seven days
 * without a MySQL/Redis connect before a node leaves the legacy link). On a
 * node in mode 1 or 2 every connect is counted per UTC day, with its site,
 * and logged (bounded, one file a day, eight days kept); the last seven days
 * travel in the audit.json the agent sends with its heartbeats. It never
 * throws, and MAIN and mode 0 write nothing.
 */
final class ConnectAuditTest extends TestCase {
	private string $rDir;

	private const NOW = 1800000000; // 2027-01-15 08:00 UTC

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-connects-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		AgentUser::own($this->rDir); // as root, the audit writes as this tree's owner
		$this->mode(1);
		ConnectAudit::useDir($this->rDir . 'sql_audit/');
		ConnectAudit::useClock(self::NOW);
	}

	protected function tearDown(): void {
		NodeRole::resetAudit();
		NodeFlows::usePath(null);
		ConnectAudit::useDir(false);
		ConnectAudit::useClock(null);
		SettingsAudit::useDir(false);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function mode(?int $rMode): void {
		@unlink($this->rDir . 'flows.json');
		if ($rMode !== null) {
			file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => $rMode, 'flows' => 63, 'state' => 'active']));
		}
		NodeFlows::usePath($this->rDir . 'flows.json');
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
	}

	/** One connect, always from the same site. */
	private function connect(string $rKind): void {
		ConnectAudit::guard($rKind);
	}

	/** This file as ConnectAudit names a site: relative to MAIN_HOME, which holds tests/ in a deploy root. */
	private static function file(): string {
		return str_starts_with(__FILE__, MAIN_HOME) ? substr(__FILE__, strlen(MAIN_HOME)) : __FILE__;
	}

	/** The site connect() records. */
	private function site(string $rKind = ConnectAudit::SQL): string {
		return $rKind . ' ' . self::file() . ':' . ((new ReflectionMethod($this, 'connect'))->getStartLine() + 1);
	}

	/** @return array<string, mixed>|null */
	private function day(int $rAt = self::NOW, string $rDir = 'sql_audit/'): ?array {
		$rDay = json_decode((string) @file_get_contents($this->rDir . $rDir . gmdate('Ymd', $rAt) . '.json'), true);
		return is_array($rDay) ? $rDay : null;
	}

	/** @return list<array<string, mixed>> */
	private function log(int $rAt = self::NOW): array {
		$rFile = $this->rDir . 'sql_audit/' . gmdate('Ymd', $rAt) . '.ndjson';
		return is_file($rFile) ? array_map(static fn(string $rLine): array => json_decode($rLine, true), file($rFile, FILE_IGNORE_NEW_LINES)) : [];
	}

	/** @return array<string, mixed>|null */
	private function published(): ?array {
		clearstatcache();
		$rDoc = json_decode((string) @file_get_contents($this->rDir . 'cluster/audit.json'), true);
		return is_array($rDoc) ? $rDoc : null;
	}

	public function testMainAndModeZeroWriteNothing(): void {
		foreach ([null, 0] as $rMode) {
			$this->mode($rMode);
			ConnectAudit::guard(ConnectAudit::SQL);
			ConnectAudit::guard(ConnectAudit::REDIS);
		}
		$this->assertDirectoryDoesNotExist($this->rDir . 'sql_audit');
		// A manual trace (XCVM_CONNECT_AUDIT=1, or the `enabled` file) still counts in mode 0.
		NodeRole::resetAudit(true);
		ConnectAudit::guard(ConnectAudit::SQL);
		$this->assertSame(1, $this->day()['sql']);
		$this->assertNull($this->published(), 'no report in mode 0');
	}

	public function testModeOneCountsEachConnectWithItsCaller(): void {
		ConnectAudit::guard(ConnectAudit::SQL); $rLine = __LINE__;
		ConnectAudit::guard(ConnectAudit::REDIS);
		ConnectAudit::guard(ConnectAudit::SQL); $rLine2 = __LINE__;
		$rDay = $this->day();
		$this->assertSame([2, 1], [$rDay['sql'], $rDay['redis']]);
		$this->assertSame(1, $rDay['sites']['sql ' . self::file() . ':' . $rLine], 'the caller, not ConnectAudit itself');
		$this->assertSame(1, $rDay['sites']['sql ' . self::file() . ':' . $rLine2]);
		$rLog = $this->log();
		$this->assertCount(3, $rLog);
		$this->assertSame(['t' => self::NOW, 'k' => 'sql', 's' => self::file() . ':' . $rLine, 'p' => getmypid()], $rLog[0], 'not refused: no `r`');
		$this->assertEquals(['sql' => 2, 'redis' => 1, 'sites' => $rDay['sites']], ConnectAudit::summary(7, self::NOW), 'the same, ranked');
	}

	public function testSiteSkipsTheConnectMachinery(): void {
		$rTrace = [
			['file' => '/x/Core/Database/Database.php', 'line' => 139, 'class' => ConnectAudit::class, 'function' => 'guard'],
			['file' => '/x/Core/Database/LazyDatabaseHandler.php', 'line' => 39, 'class' => 'XcVm\\Core\\Database\\Database', 'function' => 'db_connect'],
			['file' => '/x/Core/Database/LazyDatabaseHandler.php', 'line' => 45, 'class' => 'XcVm\\Core\\Database\\LazyDatabaseHandler', 'function' => 'open'],
			['file' => '/x/Streaming/Foo.php', 'line' => 42, 'class' => 'XcVm\\Core\\Database\\LazyDatabaseHandler', 'function' => 'query'],
			['file' => '/x/Public/stream/live.php', 'line' => 7, 'class' => 'XcVm\\Streaming\\Foo', 'function' => 'boot'],
		];
		$this->assertSame('/x/Streaming/Foo.php:42', ConnectAudit::site($rTrace));
		$this->assertSame('unknown', ConnectAudit::site([]));
	}

	public function testEveryConnectPathPassesTheGuard(): void {
		$rRoot = MAIN_HOME;
		$this->assertStringContainsString('ConnectAudit::guard(ConnectAudit::SQL);', (string) file_get_contents($rRoot . 'Core/Database/Database.php'));
		$this->assertStringContainsString('ConnectAudit::guard(ConnectAudit::REDIS);', (string) file_get_contents($rRoot . 'Infrastructure/Redis/RedisManager.php'));
	}

	public function testEveryCountIsBounded(): void {
		// Past MAX_SITES sites a day, the rest count under "*".
		for ($i = 0; $i < ConnectAudit::MAX_SITES + 3; $i++) {
			ConnectAudit::record(ConnectAudit::SQL, 'Streaming/Site' . $i . '.php:1');
		}
		ConnectAudit::record(ConnectAudit::SQL, 'Streaming/Site0.php:1'); // a known site still counts as itself
		$rDay = $this->day();
		$this->assertSame(ConnectAudit::MAX_SITES + 4, $rDay['sql']);
		$this->assertCount(ConnectAudit::MAX_SITES + 1, $rDay['sites']);
		$this->assertSame(3, $rDay['sites'][ConnectAudit::OTHER]);
		$this->assertSame(2, $rDay['sites']['sql Streaming/Site0.php:1']);

		// A long site keeps its end (file and line), within MAX_SITE_LEN; printable ASCII only.
		$rLong = ConnectAudit::siteKey(ConnectAudit::SQL, str_repeat('d/', 200) . 'x.php:9');
		$this->assertSame('sql ...' . str_repeat('d/', 73) . 'x.php:9', $rLong);
		$this->assertSame(ConnectAudit::MAX_SITE_LEN, strlen($rLong));
		$this->assertSame('redis ?.php:1', ConnectAudit::siteKey(ConnectAudit::REDIS, "\x01.php:1"));
		$this->assertSame('sql ??.php:1', ConnectAudit::siteKey(ConnectAudit::SQL, "\xc3\xa9.php:1"));

		// The log stops at LOG_MAX_BYTES a day; the counts go on.
		$rLog = $this->rDir . 'sql_audit/' . gmdate('Ymd', self::NOW) . '.ndjson';
		file_put_contents($rLog, str_repeat('x', ConnectAudit::LOG_MAX_BYTES));
		ConnectAudit::guard(ConnectAudit::REDIS);
		clearstatcache();
		$this->assertSame(ConnectAudit::LOG_MAX_BYTES, filesize($rLog));
		$this->assertSame(1, $this->day()['redis']);

		// A broken day file starts over rather than failing the connect.
		file_put_contents($this->rDir . 'sql_audit/' . gmdate('Ymd', self::NOW) . '.json', '{broken');
		ConnectAudit::guard(ConnectAudit::SQL);
		$this->assertSame([1, 0], [$this->day()['sql'], $this->day()['redis']]);
	}

	public function testTheWindowIsSevenDaysAndOldDaysArePruned(): void {
		foreach ([0, 6, 7, 9] as $rDaysAgo) {
			ConnectAudit::useClock(self::NOW - $rDaysAgo * 86400);
			ConnectAudit::record(ConnectAudit::REDIS, 'Cli/Old.php:' . $rDaysAgo);
		}
		ConnectAudit::useClock(self::NOW);
		$rSum = ConnectAudit::summary(7, self::NOW);
		$this->assertSame([0, 2], [$rSum['sql'], $rSum['redis']], 'seven days back, today included');
		$this->assertSame(['redis Cli/Old.php:0' => 1, 'redis Cli/Old.php:6' => 1], $rSum['sites']);
		$this->assertSame(3, ConnectAudit::summary(8, self::NOW)['redis']);

		$this->assertSame(2, ConnectAudit::prune(8, self::NOW), 'the nine-day-old counts and log');
		$this->assertNotNull($this->day(self::NOW - 7 * 86400), 'eight days are kept');
		$this->assertNull($this->day(self::NOW - 9 * 86400));
		$this->assertSame([], $this->log(self::NOW - 9 * 86400));
	}

	public function testTheReportGoesWithTheSettingsMissesInAuditJson(): void {
		$this->connect(ConnectAudit::SQL);
		$rDoc = $this->published();
		$this->assertSame(['settings_misses', 'sql_connects', 'redis_connects', 'sites', 'connects_since'], array_keys($rDoc), 'a new site rewrites the report at once');
		$this->assertSame([1, 0, [$this->site() => 1], self::NOW], [$rDoc['sql_connects'], $rDoc['redis_connects'], $rDoc['sites'], $rDoc['connects_since']]);

		// The same site again: the report waits for a minute (or cron:cleanup).
		touch($this->rDir . 'cluster/audit.json', self::NOW);
		ConnectAudit::useClock(self::NOW + 10);
		$this->connect(ConnectAudit::SQL);
		$this->assertSame(1, $this->published()['sql_connects']);
		// A new site rewrites it at once, however fresh.
		touch($this->rDir . 'cluster/audit.json', self::NOW + 10);
		ConnectAudit::guard(ConnectAudit::REDIS); $rLine = __LINE__;
		$this->assertSame([2, 1], [$this->published()['sql_connects'], $this->published()['redis_connects']], 'a new site: at once');
		$this->assertSame(1, $this->published()['sites']['redis ' . self::file() . ':' . $rLine]);
		touch($this->rDir . 'cluster/audit.json', self::NOW + 10);
		ConnectAudit::useClock(self::NOW + 10 + ConnectAudit::PUBLISH_EVERY);
		$this->connect(ConnectAudit::SQL);
		$this->assertSame(3, $this->published()['sql_connects'], 'once a minute');
		$this->assertTrue(SettingsAudit::publish(null, self::NOW + 100));
		$this->assertSame(self::NOW, $this->published()['connects_since'], 'kept across reports');

		// Mode 0: the report goes, and so does the start of its window.
		$this->mode(0);
		$this->assertFalse(SettingsAudit::publish(null, self::NOW + 200));
		$this->assertNull($this->published());
		$this->assertFileDoesNotExist($this->rDir . 'sql_audit/since');
		$this->mode(1);
		$this->assertTrue(SettingsAudit::publish(null, self::NOW + 300));
		$this->assertSame(self::NOW + 300, $this->published()['connects_since'], 'back in mode 1: a new window');
	}

	public function testANodeWithoutConnectsReportsZerosSinceItsAuditBegan(): void {
		$this->assertTrue(SettingsAudit::publish(null, self::NOW - 86400)); // cron:cleanup, every hour
		$this->assertSame('{"settings_misses":{},"sql_connects":0,"redis_connects":0,"sites":{},"connects_since":' . (self::NOW - 86400) . '}', file_get_contents($this->rDir . 'cluster/audit.json'), 'objects, as MAIN reads them');

		// Most connects first, at most MAX_SITES sites over the week, the rest under "*".
		for ($i = 0; $i < ConnectAudit::MAX_SITES; $i++) {
			for ($j = 0; $j <= $i; $j++) {
				ConnectAudit::record(ConnectAudit::SQL, 'A' . $i . '.php:1');
			}
		}
		ConnectAudit::useClock(self::NOW - 86400);
		for ($j = 0; $j < 50; $j++) {
			ConnectAudit::record(ConnectAudit::REDIS, 'B.php:1');
		}
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$rDoc = $this->published();
		$this->assertSame([(ConnectAudit::MAX_SITES + 1) * ConnectAudit::MAX_SITES / 2, 50], [$rDoc['sql_connects'], $rDoc['redis_connects']]);
		$this->assertCount(ConnectAudit::MAX_SITES + 1, $rDoc['sites']);
		$this->assertSame('redis B.php:1', array_key_first($rDoc['sites']));
		$this->assertSame([ConnectAudit::OTHER => 1], array_slice($rDoc['sites'], -1, 1, true), 'the least, last');
	}

	/**
	 * `connects_since` marks where the counts start: a node back in mode 1
	 * reports none of the connects it counted before mode 0, and a day before
	 * the audit began is left out of the report.
	 */
	public function testTheCountsStartWhenTheAuditBegan(): void {
		ConnectAudit::useClock(self::NOW - 3 * 86400);
		ConnectAudit::record(ConnectAudit::SQL, 'Old.php:1');
		$this->assertSame([1, self::NOW - 3 * 86400], [$this->published()['sql_connects'], $this->published()['connects_since']]);
		$this->mode(0);
		$this->assertFalse(SettingsAudit::publish(null, self::NOW - 2 * 86400));
		$this->assertSame([], glob($this->rDir . 'sql_audit/*'), 'the window and its days went');
		$this->mode(1);
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$this->assertSame(['settings_misses' => [], 'sql_connects' => 0, 'redis_connects' => 0, 'sites' => [], 'connects_since' => self::NOW], $this->published());

		// A day file older than the window's start (a manual trace's, say) is not counted.
		file_put_contents($this->rDir . 'sql_audit/' . gmdate('Ymd', self::NOW - 86400) . '.json', '{"sql":5,"redis":0,"sites":{"sql Old.php:1":5}}');
		$this->assertTrue(SettingsAudit::publish(null, self::NOW + 60));
		$this->assertSame(0, $this->published()['sql_connects']);
		$this->assertSame(5, ConnectAudit::summary(7, self::NOW)['sql'], 'the file is still there');
	}

	/**
	 * A count rewrites its day file in place, under its lock: a report read
	 * at the same time (another process's publish) waits for it rather than
	 * seeing a day emptied, so the counts it reports never go down.
	 */
	public function testAReportNeverReadsADayHalfWritten(): void {
		$rScript = $this->rDir . 'count.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Cluster\ConnectAudit;
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\SettingsAudit;

			require $argv[1];
			NodeFlows::usePath($argv[2]);
			SettingsAudit::useDir($argv[3], $argv[4]);
			ConnectAudit::useDir($argv[5]);
			ConnectAudit::useClock((int) $argv[6]);
			for ($i = 0; $i < 2000; $i++) {
				ConnectAudit::record(ConnectAudit::SQL, 'Writer.php:1');
			}
			PHP);
		$rCommand = [PHP_BINARY, $rScript, MAIN_HOME . 'vendor/autoload.php', $this->rDir . 'flows.json', $this->rDir . 'misses/', $this->rDir . 'cluster/', $this->rDir . 'sql_audit/', (string) self::NOW];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		[$rLast, $rReads, $rDrops] = [0, 0, 0];
		do {
			// PHP < 8.3: once this sees the exit, proc_close() returns -1; the code is here.
			$rStatus = proc_get_status($rProc);
			$rSql = ConnectAudit::summary(7, self::NOW)['sql'];
			$rDrops += (int) ($rSql < $rLast);
			[$rLast, $rReads] = [max($rLast, $rSql), $rReads + 1];
		} while ($rStatus['running']);
		proc_close($rProc);
		$this->assertSame(0, $rStatus['exitcode']);
		$this->assertSame(0, $rDrops, $rReads . ' reads while the writer counted');
		$this->assertSame(2000, ConnectAudit::summary(7, self::NOW)['sql']);
	}

	public function testAuditJsonStaysWithinWhatMainTakes(): void {
		foreach (range(1, SettingsAudit::MAX_KEYS + 5) as $i) {
			SettingsAudit::read(str_repeat('k', 60) . sprintf('%04d', $i));
		}
		SettingsAudit::flush(self::NOW);
		for ($i = 0; $i < ConnectAudit::MAX_SITES + 5; $i++) {
			for ($j = 0; $j < 3; $j++) {
				ConnectAudit::record(ConnectAudit::REDIS, str_repeat('p/', 100) . $i . '.php:12345');
			}
		}
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$this->assertLessThanOrEqual(16384, filesize($this->rDir . 'cluster/audit.json'), 'the largest report fits the 16 KiB the agent sends and MAIN takes');
	}

	/**
	 * Run SettingsAudit::publish() in a child PHP as nobody (65534), standing
	 * in for xc_vm, with this test's files.
	 */
	private function publishAsNobody(string $rConnects): bool {
		$rScript = $this->rDir . 'publish.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Cluster\ConnectAudit;
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\SettingsAudit;

			require $argv[1];
			NodeFlows::usePath($argv[2]);
			SettingsAudit::useDir($argv[3], $argv[4]);
			ConnectAudit::useDir($argv[5]);
			NodeFlows::declared(); // every class loaded before the switch
			ConnectAudit::summary();
			if (!posix_setgid(65534) || !posix_setuid(65534)) {
				exit(3);
			}
			echo json_encode(SettingsAudit::publish(null, (int) $argv[6]));
			PHP);
		$rCommand = [PHP_BINARY, $rScript, MAIN_HOME . 'vendor/autoload.php', $this->rDir . 'flows.json', $this->rDir . 'misses/', $this->rDir . 'cluster/', $rConnects, (string) self::NOW];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		return json_decode($rOut) === true;
	}

	public function testARootProcessCountsAsTheAgentsUser(): void {
		if (!AgentUser::root()) {
			$this->markTestSkipped('needs root, as the node\'s root crons run');
		}
		// A node: storage/ is not in the LB build, and the deploy root and
		// config/cluster/ are xc_vm's (nobody here, setUp): root makes each
		// level and file as that user.
		$rDir = $this->rDir . 'storage/cluster/sql_audit/';
		ConnectAudit::useDir($rDir);
		ConnectAudit::guard(ConnectAudit::SQL); // cron:root_signals boots through MAIN's database in mode 1
		$this->assertSame(0, posix_geteuid(), 'root again after the count');
		$rDay = $rDir . gmdate('Ymd', self::NOW) . '.json';
		foreach ([$this->rDir . 'storage', $this->rDir . 'storage/cluster', $rDir, $rDay, $rDir . gmdate('Ymd', self::NOW) . '.ndjson', $rDir . 'since', $this->rDir . 'cluster/audit.json'] as $rPath) {
			clearstatcache(true, $rPath);
			$this->assertSame([AgentUser::UID, AgentUser::UID], [fileowner($rPath), filegroup($rPath)], $rPath);
		}
		$this->assertSame(0750, fileperms($this->rDir . 'storage') & 0777);

		// xc_vm's hourly publish (cron:cleanup) reads root's counts.
		$this->assertTrue($this->publishAsNobody($rDir));
		$this->assertSame(1, $this->published()['sql_connects']);
		// A day file it cannot read: no report, the one there stays.
		chown($rDay, 0);
		chmod($rDay, 0600);
		file_put_contents($this->rDir . 'cluster/audit.json', '{"settings_misses":{},"sql_connects":7}');
		$this->assertFalse($this->publishAsNobody($rDir));
		$this->assertSame(7, $this->published()['sql_connects']);
	}

	/**
	 * Root (cron:root_signals, `startup` and cluster:root boot through MAIN's
	 * database in mode 1) counts in directories xc_vm can write. A link xc_vm
	 * planted there leads root nowhere xc_vm could not go itself: nothing
	 * outside them is made, written or handed over. Where the agent's
	 * directory is root's, root has no user to count as, and writes nothing.
	 */
	public function testRootFollowsNoLinkTheAgentsUserPlanted(): void {
		if (!AgentUser::root()) {
			$this->markTestSkipped('needs root, as the node\'s root crons run');
		}
		$rSafe = $this->rDir . 'root-only/';
		mkdir($rSafe, 0700);
		chown($rSafe, 0);
		file_put_contents($rSafe . 'log', "root's\n");
		file_put_contents($rSafe . 'report', "root's\n");
		$rDay = gmdate('Ymd', self::NOW);
		$rLinks = [
			$this->rDir . 'sql_audit/' . $rDay . '.json' => $rSafe . 'counts', // none there: root would make it
			$this->rDir . 'sql_audit/' . $rDay . '.ndjson' => $rSafe . 'log', // root would append to it
			$this->rDir . 'misses/' . $rDay . '.json' => $rSafe . 'misses',
			$this->rDir . 'cluster/audit.json' => $rSafe . 'report', // the rename replaces the link, never writes through it
		];
		foreach ($rLinks as $rLink => $rTarget) {
			@mkdir(dirname($rLink), 0750);
			AgentUser::own(dirname($rLink));
			symlink($rTarget, $rLink);
			lchown($rLink, AgentUser::UID);
		}
		ConnectAudit::guard(ConnectAudit::SQL);
		SettingsAudit::read('zz_missed_by_root');
		SettingsAudit::flush(self::NOW);
		$this->assertSame(0, posix_geteuid());
		clearstatcache();
		$this->assertSame(['log', 'report'], array_values(array_diff(scandir($rSafe), ['.', '..'])), 'nothing made there');
		foreach (['log', 'report'] as $rFile) {
			$this->assertSame(["root's\n", 0, 0], [file_get_contents($rSafe . $rFile), fileowner($rSafe . $rFile), filegroup($rSafe . $rFile)], $rFile);
		}

		// The agent's directory root's: nothing written at all.
		exec('rm -rf ' . escapeshellarg($this->rDir . 'sql_audit') . ' ' . escapeshellarg($this->rDir . 'misses'));
		chown($this->rDir . 'cluster', 0);
		ConnectAudit::guard(ConnectAudit::SQL);
		SettingsAudit::read('zz_missed_by_root');
		SettingsAudit::flush(self::NOW);
		$this->assertDirectoryDoesNotExist($this->rDir . 'sql_audit');
		$this->assertDirectoryDoesNotExist($this->rDir . 'misses');
	}
}
