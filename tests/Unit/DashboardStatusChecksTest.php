<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\DashboardController;

/**
 * DashboardController status checks — the "Service Status" checklist rows.
 * Assertions target state/help, not wording, so they survive translation edits.
 */
final class DashboardStatusChecksTest extends TestCase {

	private const BIN = ['{bin}' => 'php'];
	private const NOW = 1_800_000_000;

	public function testServersOkWhenEveryEnabledServerIsOnline(): void {
		$check = DashboardController::serversCheck([
			['server_name' => 'Main', 'enabled' => 1, 'server_online' => 1],
			// Disabled servers are expected to be offline and must not count.
			['server_name' => 'Spare', 'enabled' => 0, 'server_online' => 0],
		]);

		$this->assertSame('ok', $check['state']);
		$this->assertSame('', $check['help']);
	}

	public function testServersFailNamesTheOfflineOnes(): void {
		$check = DashboardController::serversCheck([
			['server_name' => 'Main', 'enabled' => 1, 'server_online' => 1],
			['server_name' => 'LB-2', 'enabled' => 1, 'server_online' => 0],
		]);

		$this->assertSame('fail', $check['state']);
		$this->assertStringContainsString('LB-2', $check['detail']);
		$this->assertStringNotContainsString('Main', $check['detail']);
	}

	public function testSchemaOkOnlyWhenWatermarkMatchesVersion(): void {
		$this->assertSame('ok', DashboardController::schemaCheck('2.5.3', '2.5.3', self::BIN)['state']);

		$stale = DashboardController::schemaCheck('2.5.2', '2.5.3', self::BIN);
		$this->assertSame('warn', $stale['state']);
		$this->assertNotSame('', $stale['help']);

		$this->assertSame('warn', DashboardController::schemaCheck('', '2.5.3', self::BIN)['state']);
	}

	public function testCronFreshWithinTenMinutes(): void {
		$this->assertSame('ok', DashboardController::cronCheck(self::NOW - 600, self::NOW, self::BIN)['state']);

		$stale = DashboardController::cronCheck(self::NOW - 601, self::NOW, self::BIN);
		$this->assertSame('fail', $stale['state']);
		$this->assertNotSame('', $stale['help']);
	}

	public function testCronNeverRanFails(): void {
		$check = DashboardController::cronCheck(null, self::NOW, self::BIN);

		$this->assertSame('fail', $check['state']);
		$this->assertNotSame('', $check['help']);
	}

	public function testFanoutDisabledIsOffNotFailure(): void {
		$check = DashboardController::fanoutCheck(false, [$this->fanoutServer('Main', false)], self::NOW, self::BIN);

		$this->assertSame('off', $check['state']);
		$this->assertSame('', $check['help']);
	}

	public function testFanoutFailsWhenAnyFreshServerReportsDown(): void {
		$check = DashboardController::fanoutCheck(true, [
			$this->fanoutServer('Main', true),
			$this->fanoutServer('LB-2', false),
		], self::NOW, self::BIN);

		$this->assertSame('fail', $check['state']);
		$this->assertNotSame('', $check['help']);
	}

	public function testFanoutIgnoresStaleWatchdogReports(): void {
		$check = DashboardController::fanoutCheck(true, [
			$this->fanoutServer('Main', true),
			// Last heartbeat two minutes ago: its "down" is not current evidence.
			$this->fanoutServer('LB-2', false, self::NOW - 120),
		], self::NOW, self::BIN);

		$this->assertSame('ok', $check['state']);
	}

	public function testFanoutWithoutReportsIsOff(): void {
		$check = DashboardController::fanoutCheck(true, [
			['server_name' => 'Main', 'watchdog_data' => '{}', 'last_check_ago' => self::NOW],
		], self::NOW, self::BIN);

		$this->assertSame('off', $check['state']);
	}

	/** @return array<string,mixed> */
	private function fanoutServer(string $name, bool $running, int $lastCheck = self::NOW): array {
		return ['server_name' => $name, 'watchdog_data' => json_encode(['fanout' => ['running' => $running]]), 'last_check_ago' => $lastCheck];
	}

	// ── Cluster API ──────────────────────────────────────────────────

	public function testClusterOffAndEnrolledNothingAreBothOff(): void {
		$this->assertSame('off', DashboardController::clusterCheck(false, [], [], self::BIN)['state']);
		$this->assertSame('off', DashboardController::clusterCheck(true, [], [], self::BIN)['state'], 'on with no node is not a failure');
	}

	public function testASilentOrStoppedNodeFails(): void {
		$rSilent = DashboardController::clusterCheck(true, [
			$this->node('LB-1', 'active', 'ok'),
			$this->node('LB-2', 'active', 'offline'),
		], [], self::BIN);
		$this->assertSame('fail', $rSilent['state']);
		$this->assertStringContainsString('LB-2', $rSilent['detail']);
		$this->assertStringNotContainsString('LB-1', $rSilent['detail']);
		$this->assertNotSame('', $rSilent['help'], 'a failure says where to look');

		$rStopped = DashboardController::clusterCheck(true, [$this->node('LB-3', 'quarantined', 'quarantined')], [], self::BIN);
		$this->assertSame('fail', $rStopped['state']);
		$this->assertStringContainsString('LB-3', $rStopped['detail']);
	}

	public function testANodeWaitingForADecisionIsAWarningNotAFailure(): void {
		// It is not serving anything yet: nobody's viewers are affected.
		$rCode = DashboardController::clusterCheck(true, [$this->node('LB-1', 'active', 'ok')], [['server_name' => 'LB-9']], self::BIN);
		$this->assertSame('warn', $rCode['state']);
		$this->assertStringContainsString('LB-9', $rCode['detail']);

		$rEnrolling = DashboardController::clusterCheck(true, [$this->node('LB-4', 'enrolling', 'enrolling')], [], self::BIN);
		$this->assertSame('warn', $rEnrolling['state']);

		// A node that missed a heartbeat or two is suspect, not offline.
		$this->assertSame('warn', DashboardController::clusterCheck(true, [$this->node('LB-5', 'active', 'suspect')], [], self::BIN)['state']);
	}

	public function testEveryActiveNodeAnsweringIsOk(): void {
		$rCheck = DashboardController::clusterCheck(true, [
			$this->node('LB-1', 'active', 'ok'),
			$this->node('LB-2', 'active', 'ok'),
			// A revoked node is MAIN's decision, not a fleet failure... but it is
			// stopped, so it is named.
		], [], self::BIN);

		$this->assertSame('ok', $rCheck['state']);
		$this->assertSame('', $rCheck['help'], 'nothing to do, nothing to read');
		$this->assertStringContainsString('2', $rCheck['detail']);
	}

	public function testAServerInstallingOrUpdatingIsNeitherCountedNorDown(): void {
		$rCheck = DashboardController::serversCheck([
			1 => ['server_name' => 'MAIN', 'enabled' => 1, 'server_online' => true, 'status' => 1],
			2 => ['server_name' => 'LB-2', 'enabled' => 1, 'server_online' => false, 'status' => 5],
			3 => ['server_name' => 'LB-3', 'enabled' => 1, 'server_online' => false, 'status' => 3],
			4 => ['server_name' => 'LB-4', 'enabled' => 1, 'server_online' => false, 'status' => 1],
		]);

		$this->assertSame('fail', $rCheck['state']);
		$this->assertStringContainsString('LB-4', $rCheck['detail']);
		$this->assertStringNotContainsString('LB-2', $rCheck['detail']);
		$this->assertStringNotContainsString('LB-3', $rCheck['detail']);
		$this->assertStringContainsString('1 of 2', $rCheck['detail']);
	}

	public function testTheDiskRowJudgesTmpByPercentAndThePanelDiskAlsoByWhatIsLeft(): void {
		$rGiB = 1024 ** 3;
		$this->assertSame('off', DashboardController::diskCheck([])['state']);
		$this->assertSame('off', DashboardController::diskCheck(['panel' => null, 'tmp' => null])['state']);
		$this->assertSame('ok', DashboardController::diskCheck(['panel' => [41, 500 * $rGiB], 'tmp' => [3, $rGiB]])['state']);
		$this->assertSame('warn', DashboardController::diskCheck(['panel' => [89, 5 * $rGiB], 'tmp' => [90, $rGiB]])['state']);
		$rFull = DashboardController::diskCheck(['panel' => [95, 2 * $rGiB], 'tmp' => [3, $rGiB]]);
		$this->assertSame('fail', $rFull['state']);
		$this->assertNotSame('', $rFull['help']);
		$this->assertStringContainsString('95', $rFull['detail']);
		$this->assertStringContainsString('3', $rFull['detail'], 'every volume listed');
		$this->assertSame('ok', DashboardController::diskCheck(['panel' => [96, 160 * $rGiB], 'tmp' => [3, $rGiB]])['state'], 'a large disk mostly content');
		$this->assertSame('fail', DashboardController::diskCheck(['panel' => [96, 2 * $rGiB], 'tmp' => [3, $rGiB]])['state']);
	}

	public function testTheBackupsRow(): void {
		$rHour = 3600;
		$this->assertSame('off', DashboardController::backupCheck('off', null, self::NOW)['state']);
		$this->assertSame('fail', DashboardController::backupCheck('daily', null, self::NOW)['state']);
		$this->assertSame('ok', DashboardController::backupCheck('daily', ['timestamp' => self::NOW - 25 * $rHour, 'upload_failed' => false], self::NOW)['state']);
		$this->assertSame('fail', DashboardController::backupCheck('daily', ['timestamp' => self::NOW - 30 * $rHour - 1, 'upload_failed' => false], self::NOW)['state']);
		$this->assertSame('fail', DashboardController::backupCheck('hourly', ['timestamp' => self::NOW - 75 * 60 - 1, 'upload_failed' => false], self::NOW)['state']);
		$this->assertSame('warn', DashboardController::backupCheck('daily', ['timestamp' => self::NOW - $rHour, 'upload_failed' => true], self::NOW)['state']);
	}

	public function testTheCertificatesRow(): void {
		$rServer = static fn(string $rName, int $rLeft, int $rHttps = 1, int $rEnabled = 1): array => ['server_name' => $rName, 'enabled' => $rEnabled, 'enable_https' => $rHttps, 'certbot_ssl' => json_encode(['expiration' => self::NOW + $rLeft])];
		$rDay = 86400;
		$this->assertSame('ok', DashboardController::certificateCheck([1 => $rServer('MAIN', 6 * $rDay)], self::NOW)['state']);
		$rSoon = DashboardController::certificateCheck([1 => $rServer('MAIN', 6 * $rDay), 2 => $rServer('LB-2', $rDay + 60)], self::NOW, self::BIN);
		$this->assertSame('warn', $rSoon['state']);
		$this->assertStringContainsString('LB-2 (1 d)', $rSoon['detail']);
		$this->assertStringNotContainsString('MAIN', $rSoon['detail']);
		$this->assertSame('warn', DashboardController::certificateCheck([1 => $rServer('MAIN', 5 * $rDay - 1)], self::NOW)['state']);
		$this->assertSame('fail', DashboardController::certificateCheck([1 => $rServer('MAIN', -1)], self::NOW)['state']);
		$this->assertSame('off', DashboardController::certificateCheck([1 => $rServer('MAIN', -1, 0), 2 => $rServer('LB-2', -1, 1, 0), 3 => ['server_name' => 'LB-3', 'enabled' => 1, 'enable_https' => 1, 'certbot_ssl' => null]], self::NOW)['state']);
	}

	public function testTheCacheRow(): void {
		$this->assertSame('off', DashboardController::cacheCheck(false, true, true, true, 0, self::NOW)['state']);
		$this->assertSame('ok', DashboardController::cacheCheck(true, true, false, false, self::NOW - 120, self::NOW)['state']);
		$this->assertSame('warn', DashboardController::cacheCheck(true, false, false, false, 0, self::NOW)['state']);
		$this->assertSame('fail', DashboardController::cacheCheck(true, true, true, false, self::NOW - 600, self::NOW)['state']);
		$this->assertSame('fail', DashboardController::cacheCheck(true, true, false, true, self::NOW - 600, self::NOW)['state']);
	}

	/** @return array<string,mixed> A ClusterAdmin::nodes() row, as the checklist reads it. */
	private function node(string $name, string $state, string $health): array {
		return ['server_id' => 5, 'server_name' => $name, 'state' => $state, 'health' => $health];
	}
}
