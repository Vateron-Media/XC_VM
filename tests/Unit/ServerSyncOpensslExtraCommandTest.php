<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerSyncOpensslExtraCommand;
use XcVm\Core\Config\OpensslExtra;

/**
 * server:sync-openssl-extra — queues MAIN's OPENSSL_EXTRA for the streaming
 * LBs that report another one. A node that does not report a fingerprint runs
 * a build whose root cron cannot apply the value, and an offline node would
 * leave it waiting in the signals table, so both need --force; proxies and the
 * MAIN never get it. Nothing is sent when the main has no value of its own, or
 * when the value this process reads is not the one the main publishes (a file
 * root can read and xc_vm's php-fpm cannot).
 *
 * The LB side (cron:root_signals) applies the row through
 * OpensslExtra::applySignal, covered in OpensslExtraTest.
 */
final class ServerSyncOpensslExtraCommandTest extends TestCase {

	private TestDb $db;

	private string $rFile;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec('CREATE TABLE signals (signal_id INTEGER PRIMARY KEY AUTO_INCREMENT, server_id INTEGER, `time` INTEGER, custom_data TEXT);');
		ServerSyncOpensslExtraCommand::setDb($this->db);
		$this->rFile = sys_get_temp_dir() . '/xcvm_sync_openssl_extra_' . uniqid();
		file_put_contents($this->rFile, 'main-extra-on-disk');
	}

	protected function tearDown(): void {
		try {
			(new ReflectionProperty(ServerSyncOpensslExtraCommand::class, 'db'))->setValue(null, null);
		} finally {
			@unlink($this->rFile);
		}
	}

	/** Main #1 (publishes this process's value), LBs #2 and #5 on another value, #3 in sync, proxy #4. */
	private function cluster(?string $rMainValue = OPENSSL_EXTRA): array {
		$rMainHardware = $rMainValue === null ? ['cores' => 8] : [OpensslExtra::HARDWARE_KEY => OpensslExtra::fingerprint($rMainValue)];

		return [
			1 => ['id' => 1, 'is_main' => 1, 'server_type' => 0, 'server_online' => true, 'server_name' => 'Main', 'server_hardware' => json_encode($rMainHardware)],
			2 => $this->lb(['id' => 2]),
			3 => $this->lb(['id' => 3, 'server_hardware' => json_encode([OpensslExtra::HARDWARE_KEY => OpensslExtra::fingerprint(OPENSSL_EXTRA)])]),
			4 => $this->lb(['id' => 4, 'server_type' => 1, 'server_hardware' => json_encode(['cores' => 2])]),
			5 => $this->lb(['id' => 5]),
		];
	}

	/** @return array{0:int,1:string} [exit code, output] */
	private function sync(array $rServers, bool $rAll, int $rTargetID, bool $rForce): array {
		ob_start();
		try {
			$rCode = ServerSyncOpensslExtraCommand::sync($rServers, 1, $rAll, $rTargetID, $rForce, $this->rFile);
		} finally {
			$rOutput = (string) ob_get_clean();
		}

		return [$rCode, $rOutput];
	}

	/** @return array<int,string> server_id => custom_data of every queued signal */
	private function queued(): array {
		$this->db->query('SELECT server_id, custom_data FROM signals ORDER BY signal_id;');
		$rQueued = [];
		foreach ($this->db->get_rows() as $rRow) {
			$this->assertArrayNotHasKey((int) $rRow['server_id'], $rQueued, 'one signal per node');
			$rQueued[(int) $rRow['server_id']] = $rRow['custom_data'];
		}

		return $rQueued;
	}

	public function testEachMismatchedLbGetsOneSignalCarryingTheMainsValue(): void {
		[$rCode] = $this->sync($this->cluster(), true, 0, false);

		$this->assertSame(0, $rCode);
		$rSignal = OpensslExtra::signal(OPENSSL_EXTRA);
		$this->assertSame([2 => $rSignal, 5 => $rSignal], $this->queued());
		$this->assertSame(['action' => OpensslExtra::SIGNAL_ACTION, 'value' => OPENSSL_EXTRA], json_decode($rSignal, true));
	}

	public function testForceNeverQueuesTheMainOrAProxy(): void {
		[$rCode] = $this->sync($this->cluster(), true, 0, true);

		$this->assertSame(0, $rCode);
		$this->assertSame([2, 3, 5], array_keys($this->queued()));
	}

	public function testASingleTargetIsQueuedAlone(): void {
		$this->assertSame(0, $this->sync($this->cluster(), false, 5, false)[0]);
		$this->assertSame([5], array_keys($this->queued()));

		[$rCode, $rOutput] = $this->sync($this->cluster(), false, 1, true);
		$this->assertSame(0, $rCode);
		$this->assertStringContainsString('#1 Main: skipped, the main', $rOutput);
		$this->assertSame([5], array_keys($this->queued()));
	}

	/** Without config/openssl_extra the main runs on the built-in default, as its LBs do. */
	public function testNothingIsQueuedWhenTheMainHasNoValueOfItsOwn(): void {
		foreach (['', " \n"] as $rEmpty) {
			file_put_contents($this->rFile, $rEmpty);
			$this->assertSame(0, $this->sync($this->cluster(), true, 0, true)[0]);
		}
		@unlink($this->rFile);
		$this->assertSame(0, $this->sync($this->cluster(), true, 0, true)[0]);

		$this->assertSame([], $this->queued());
	}

	/** Run as root, the command can read a file xc_vm cannot: the main's php-fpm then mints with another value. */
	public function testNothingIsQueuedUnlessTheMainPublishesTheValueToSend(): void {
		[$rCode, $rOutput] = $this->sync($this->cluster('built-in-default'), true, 0, true);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('chown xc_vm:xc_vm', $rOutput);

		[$rCode, $rOutput] = $this->sync($this->cluster(null), true, 0, true);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('cron:servers', $rOutput);

		$this->assertSame([], $this->queued());
	}

	public function testItRunsOnlyOnTheMainAndForAKnownServer(): void {
		$rServers = $this->cluster();
		$rServers[1]['is_main'] = 0;
		$this->assertSame(1, $this->sync($rServers, true, 0, true)[0]);
		$this->assertSame(1, $this->sync($this->cluster(), false, 9, true)[0]);

		$this->assertSame([], $this->queued());
	}

	public function testTargetsListsEveryNodeButTheMainWithItsSkipReason(): void {
		$rTargets = ServerSyncOpensslExtraCommand::targets($this->cluster(), true, 0, OpensslExtra::fingerprint(OPENSSL_EXTRA), false);

		$this->assertSame([2, 3, 4, 5], array_keys($rTargets));
		$this->assertNull($rTargets[2]);
		$this->assertSame('already in sync', $rTargets[3]);
		$this->assertSame('a proxy', $rTargets[4]);
		$this->assertNull($rTargets[5]);
	}

	/** Its own case with its own break: falling through would run the next action (restart_services). */
	public function testTheLbAppliesTheSignalInItsOwnCase(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');

		$this->assertSame(1, preg_match('/case OpensslExtra::SIGNAL_ACTION:(.*?)\n\s*case /s', $rSource, $rMatch));
		$this->assertStringContainsString('OpensslExtra::applySignal($rData, !empty($rServers[SERVER_ID][\'is_main\']), CONFIG_PATH, time())', $rMatch[1]);
		$this->assertMatchesRegularExpression('/break;\s*$/', $rMatch[1]);
	}

	private function lb(array $rOverride = []): array {
		return array_merge([
			'id'              => 2,
			'is_main'         => 0,
			'server_type'     => 0,
			'server_online'   => true,
			'server_hardware' => json_encode(['cores' => 4, OpensslExtra::HARDWARE_KEY => OpensslExtra::fingerprint('lb-extra')]),
		], $rOverride);
	}

	private function skip(array $rServer, bool $rForce): ?string {
		return ServerSyncOpensslExtraCommand::skipReason($rServer, OpensslExtra::fingerprint('main-extra'), $rForce);
	}

	public function testAnLbThatReportsAnotherValueIsSynced(): void {
		$this->assertNull($this->skip($this->lb(), false));
		$this->assertNull($this->skip($this->lb(), true));
	}

	public function testAnLbInSyncIsSyncedOnlyWhenForced(): void {
		$rInSync = $this->lb(['server_hardware' => json_encode([OpensslExtra::HARDWARE_KEY => OpensslExtra::fingerprint('main-extra')])]);

		$this->assertSame('already in sync', $this->skip($rInSync, false));
		$this->assertNull($this->skip($rInSync, true));
	}

	public function testAnOutdatedOrOfflineLbIsSyncedOnlyWhenForced(): void {
		$rOutdated = $this->lb(['server_hardware' => json_encode(['cores' => 4])]);
		$rOffline = $this->lb(['server_online' => false]);

		$this->assertStringStartsWith('unknown (node not updated)', (string) $this->skip($rOutdated, false));
		$this->assertNull($this->skip($rOutdated, true));
		$this->assertStringStartsWith('offline', (string) $this->skip($rOffline, false));
		$this->assertNull($this->skip($rOffline, true));
	}

	public function testProxiesAndTheMainAreNeverSynced(): void {
		$this->assertNotNull($this->skip($this->lb(['server_type' => 1]), true));
		$this->assertNotNull($this->skip($this->lb(['is_main' => 1]), true));
	}
}
