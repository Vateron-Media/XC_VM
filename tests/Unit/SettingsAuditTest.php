<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Config\SettingsManager;
use XcVm\Tests\Support\AgentUser;

/**
 * `audit.settings_misses` on the node (cluster plan, section 9, R1
 * `settings`): on a node in mode 1 or 2, a read through SettingsManager of a
 * key outside lb_settings_keys.php is counted, without a database: per
 * process, merged into a per-day file, reported for the last seven days in
 * the audit.json the agent sends with its heartbeats. Bounded everywhere;
 * MAIN, legacy nodes and mode 0 count nothing.
 */
final class SettingsAuditTest extends TestCase {
	private string $rDir;

	private const NOW = 1800000000; // 2027-01-15 08:00 UTC

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-misses-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		AgentUser::own($this->rDir); // as root, the audit writes as this tree's owner
		$this->mode(1);
		SettingsManager::set(['server_name' => 'Panel', 'api_pass' => 'x']);
	}

	protected function tearDown(): void {
		SettingsAudit::useDir(false);
		NodeFlows::usePath(null);
		SettingsManager::set([]);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** This node's mode as its agent wrote it (null: no agent file), and a process that decides afresh. */
	private function mode(?int $rMode): void {
		@unlink($this->rDir . 'flows.json');
		if ($rMode !== null) {
			file_put_contents($this->rDir . 'flows.json', json_encode(['mode' => $rMode, 'flows' => 63, 'state' => 'active']));
		}
		NodeFlows::usePath($this->rDir . 'flows.json');
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
	}

	/** @return array<string, int>|null */
	private function day(int $rAt = self::NOW): ?array {
		$rDay = json_decode((string) @file_get_contents($this->rDir . 'misses/' . gmdate('Ymd', $rAt) . '.json'), true);
		return is_array($rDay) ? $rDay : null;
	}

	/** @return array<string, mixed>|null */
	private function published(): ?array {
		$rDoc = json_decode((string) @file_get_contents($this->rDir . 'cluster/audit.json'), true);
		return is_array($rDoc) ? $rDoc : null;
	}

	public function testAnApiNodeCountsReadsTheReplicaWouldNotAnswer(): void {
		SettingsManager::get('server_name');
		SettingsManager::get('api_pass'); // withheld: a known read, the secret travels apart or not at all
		SettingsManager::get('not_a_setting');
		SettingsManager::getBool('not_a_setting');
		SettingsManager::getInt('another_key', 3);
		SettingsManager::getString('another_key');
		SettingsManager::getArray('third_key');
		SettingsManager::has('third_key');
		SettingsAudit::flush(self::NOW);
		$this->assertSame(['not_a_setting' => 2, 'another_key' => 2, 'third_key' => 2], $this->day());
		$this->assertSame(['settings_misses' => ['another_key' => 2, 'not_a_setting' => 2, 'third_key' => 2]], $this->published(), 'most missed first, then by name');

		// Another process the same day adds to the file.
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('third_key');
		SettingsAudit::flush(self::NOW + 60);
		$this->assertSame(['not_a_setting' => 2, 'another_key' => 2, 'third_key' => 3], $this->day());
		$this->assertSame(['third_key' => 3, 'another_key' => 2, 'not_a_setting' => 2], SettingsAudit::summary(self::NOW + 60));
	}

	public function testMainALegacyNodeAndModeZeroCountNothing(): void {
		foreach ([null, 0] as $rMode) {
			file_put_contents($this->rDir . 'cluster/audit.json', '{"settings_misses":{"old":1}}');
			$this->mode($rMode);
			SettingsManager::get('not_a_setting');
			SettingsAudit::flush(self::NOW);
			$this->assertNull($this->day());
			$this->assertFalse(SettingsAudit::publish(null, self::NOW));
			$this->assertFileDoesNotExist($this->rDir . 'cluster/audit.json', 'nothing counted: nothing for the agent to send');
		}
		// Off (the suite's default): nothing at all, not even a decision.
		SettingsAudit::useDir(false);
		$this->mode(2);
		SettingsAudit::useDir(false, $this->rDir . 'cluster/');
		SettingsManager::get('not_a_setting');
		SettingsAudit::flush(self::NOW);
		$this->assertNull($this->day());
		$this->assertFalse(SettingsAudit::publish(null, self::NOW));
	}

	public function testEveryCountIsBounded(): void {
		for ($i = 0; $i < SettingsAudit::MAX_KEYS + 6; $i++) {
			SettingsManager::get(sprintf('key_%03d', $i));
		}
		SettingsManager::get('Not A Name!');
		SettingsAudit::flush(self::NOW);
		$rDay = $this->day();
		$this->assertCount(SettingsAudit::MAX_KEYS + 1, $rDay);
		$this->assertSame(7, $rDay[SettingsAudit::OTHER], 'past the cap, and a key that is not a name');

		// A day at the cap keeps its names; a new key counts under OTHER.
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('key_000');
		SettingsManager::get('brand_new');
		SettingsAudit::flush(self::NOW);
		$rDay = $this->day();
		$this->assertSame([2, 8], [$rDay['key_000'], $rDay[SettingsAudit::OTHER]]);
		$this->assertArrayNotHasKey('brand_new', $rDay);

		// The report: at most MAX_KEYS names over the days, the rest under OTHER, last.
		file_put_contents($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - 86400) . '.json', json_encode(['yesterday_key' => 50]));
		$rSummary = SettingsAudit::summary(self::NOW);
		$this->assertCount(SettingsAudit::MAX_KEYS + 1, $rSummary);
		$this->assertSame(['yesterday_key', 'key_000'], array_slice(array_keys($rSummary), 0, 2));
		$this->assertSame(SettingsAudit::OTHER, array_key_last($rSummary));
		$this->assertSame(8 + 1, $rSummary[SettingsAudit::OTHER], 'OTHER, and the one name that no longer fits');
		$this->assertLessThan(16384, strlen((string) file_get_contents($this->rDir . 'cluster/audit.json')));
	}

	public function testTheReportCoversSevenDaysAndOlderDaysArePruned(): void {
		foreach ([0 => 'today', 6 => 'six_days', 7 => 'seven_days', 8 => 'eight_days', 9 => 'nine_days'] as $rDays => $rKey) {
			@mkdir($this->rDir . 'misses/', 0777, true);
			file_put_contents($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - $rDays * 86400) . '.json', json_encode([$rKey => 1]));
		}
		$this->assertSame(['six_days' => 1, 'today' => 1], SettingsAudit::summary(self::NOW));
		$this->assertSame(1, SettingsAudit::prune(8, self::NOW));
		$this->assertFileDoesNotExist($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - 9 * 86400) . '.json');
		$this->assertFileExists($this->rDir . 'misses/' . gmdate('Ymd', self::NOW - 8 * 86400) . '.json', 'eight days old: kept');
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$this->assertSame(['settings_misses' => ['six_days' => 1, 'today' => 1]], $this->published());

		// Nothing missed: an empty object, which clears what MAIN shows.
		exec('rm -rf ' . escapeshellarg($this->rDir . 'misses'));
		$this->assertTrue(SettingsAudit::publish(null, self::NOW));
		$this->assertSame('{"settings_misses":{}}', file_get_contents($this->rDir . 'cluster/audit.json'));
		// No agent here (MAIN, a legacy node): nothing written.
		$this->assertFalse(SettingsAudit::publish($this->rDir . 'no-agent/', self::NOW));
	}

	public function testAMergeRewritesTheReportForANewKeyOrOnceAMinute(): void {
		SettingsManager::get('first_key');
		SettingsAudit::flush(self::NOW);
		$this->assertSame(['settings_misses' => ['first_key' => 1]], $this->published());
		touch($this->rDir . 'cluster/audit.json', self::NOW);

		// Another request within the minute, no new key: only the day file (PHP-FPM keeps no state between requests).
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('first_key');
		SettingsAudit::flush(self::NOW + 30);
		$this->assertSame(['first_key' => 2], $this->day());
		$this->assertSame(['settings_misses' => ['first_key' => 1]], $this->published(), 'the report waits for the minute');

		// A new key is in the report at once.
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('second_key');
		SettingsAudit::flush(self::NOW + 31);
		$this->assertSame(['settings_misses' => ['first_key' => 2, 'second_key' => 1]], $this->published());
		touch($this->rDir . 'cluster/audit.json', self::NOW + 31);

		// A minute after the last report, a merge rewrites it.
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		SettingsManager::get('second_key');
		SettingsAudit::flush(self::NOW + 31 + SettingsAudit::FLUSH_EVERY);
		$this->assertSame(['settings_misses' => ['first_key' => 2, 'second_key' => 2]], $this->published());
	}

	/**
	 * Run SettingsAudit::publish() in a child PHP as nobody (65534), standing
	 * in for xc_vm, with this test's files.
	 */
	private function publishAsNobody(): bool {
		$rScript = $this->rDir . 'publish.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\SettingsAudit;

			require $argv[1];
			NodeFlows::usePath($argv[2]);
			SettingsAudit::useDir($argv[3], $argv[4]);
			NodeFlows::declared(); // every class loaded before the switch
			if (!posix_setgid(65534) || !posix_setuid(65534)) {
				exit(3);
			}
			echo json_encode(SettingsAudit::publish(null, (int) $argv[5]));
			PHP);
		$rCommand = [PHP_BINARY, $rScript, MAIN_HOME . 'vendor/autoload.php', $this->rDir . 'flows.json', $this->rDir . 'storage/cluster/settings_misses/', $this->rDir . 'cluster/', (string) self::NOW];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);
		$this->assertSame(0, proc_close($rProc), $rOut);
		return json_decode($rOut) === true;
	}

	public function testARootProcessMergesAsTheAgentsUser(): void {
		if (!AgentUser::root()) {
			$this->markTestSkipped('needs root, as the node\'s root crons run');
		}
		// A node: storage/ is not in the LB build, and the deploy root and
		// config/cluster/ are xc_vm's (nobody here, setUp): root makes each
		// level and file as that user.
		$rDir = $this->rDir . 'storage/cluster/settings_misses/';
		SettingsAudit::useDir($rDir, $this->rDir . 'cluster/');
		SettingsManager::get('update_channel_x'); // as a root-only command reads it
		SettingsAudit::flush(self::NOW);
		$this->assertSame(0, posix_geteuid(), 'root again after the merge');
		$rDay = $rDir . gmdate('Ymd', self::NOW) . '.json';
		foreach ([$this->rDir . 'storage', $this->rDir . 'storage/cluster', $rDir, $rDay, $this->rDir . 'cluster/audit.json'] as $rPath) {
			clearstatcache(true, $rPath);
			$this->assertSame([AgentUser::UID, AgentUser::UID], [fileowner($rPath), filegroup($rPath)], $rPath);
		}
		$this->assertSame(0750, fileperms($this->rDir . 'storage') & 0777);

		// xc_vm's hourly publish (cron:cleanup) reads root's misses and keeps them. That
		// process has a load balancer's build (no MAIN_HOME), which also says whether
		// it reads its streams on itself: STREAMS is on here, and nothing is built.
		$this->assertTrue($this->publishAsNobody());
		$this->assertSame(['settings_misses' => ['update_channel_x' => 1], 'streams_local' => false], $this->published());

		// A level it cannot search: no report from days it cannot read, the one there stays.
		chown($this->rDir . 'storage/cluster', 0);
		chmod($this->rDir . 'storage/cluster', 0700);
		$this->assertFalse($this->publishAsNobody());
		$this->assertSame(['settings_misses' => ['update_channel_x' => 1], 'streams_local' => false], $this->published());
		// Nor a day file it cannot read.
		chown($this->rDir . 'storage/cluster', 65534);
		chown($rDay, 0);
		chmod($rDay, 0600);
		$this->assertFalse($this->publishAsNobody());
		$this->assertSame(['settings_misses' => ['update_channel_x' => 1], 'streams_local' => false], $this->published());
	}

	/**
	 * A merge rewrites its day file in place, under its lock: a report read
	 * at the same time waits for it rather than seeing a day emptied, so the
	 * counts it reports never go down.
	 */
	public function testAReportNeverReadsADayHalfWritten(): void {
		$rScript = $this->rDir . 'merge.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\SettingsAudit;

			require $argv[1];
			NodeFlows::usePath($argv[2]);
			SettingsAudit::useDir($argv[3], $argv[4]);
			for ($i = 0; $i < 2000; $i++) {
				SettingsAudit::read('zz_writer');
				SettingsAudit::flush((int) $argv[5]);
			}
			PHP);
		$rCommand = [PHP_BINARY, $rScript, MAIN_HOME . 'vendor/autoload.php', $this->rDir . 'flows.json', $this->rDir . 'misses/', $this->rDir . 'cluster/', (string) self::NOW];
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		[$rLast, $rReads, $rDrops] = [0, 0, 0];
		do {
			// PHP < 8.3: once this sees the exit, proc_close() returns -1; the code is here.
			$rStatus = proc_get_status($rProc);
			$rCount = SettingsAudit::summary(self::NOW)['zz_writer'] ?? 0;
			$rDrops += (int) ($rCount < $rLast);
			[$rLast, $rReads] = [max($rLast, $rCount), $rReads + 1];
		} while ($rStatus['running']);
		proc_close($rProc);
		$this->assertSame(0, $rStatus['exitcode']);
		$this->assertSame(0, $rDrops, $rReads . ' reads while the writer merged');
		$this->assertSame(['zz_writer' => 2000], SettingsAudit::summary(self::NOW));
	}

	public function testALongRunningProcessMergesEveryMinute(): void {
		SettingsManager::get('first_key');
		$this->assertNull($this->day(time()), 'held in memory');
		(new \ReflectionProperty(SettingsAudit::class, 'rFlushedAt'))->setValue(null, time() - SettingsAudit::FLUSH_EVERY);
		SettingsManager::get('first_key');
		$this->assertSame(['first_key' => 2], $this->day(time()));
		$this->assertSame(['settings_misses' => ['first_key' => 2]], $this->published());
	}
}
