<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\LegacyApiAudit;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Domain\Cluster\NodeAudit;
use XcVm\Tests\Support\AgentUser;

/**
 * LegacyApiAudit — who still calls a load balancer's legacy /api, by action
 * and caller, per UTC day; the last seven days travel in the audit.json the
 * agent sends with its heartbeats, and MAIN keeps them with the node
 * (NodeAudit) for the Cluster Nodes page. MAIN and mode 0 count nothing.
 */
final class LegacyApiAuditTest extends TestCase {
	private string $rDir;

	private const NOW = 1800000000; // 2027-01-15 08:00 UTC

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-legacy-api-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster', 0777, true);
		AgentUser::own($this->rDir); // as root, the audit writes as this tree's owner
		NodeRole::useMainBuild(false);
		$this->mode(1);
		LegacyApiAudit::useDir($this->rDir . 'legacy_api/');
		ConnectAudit::useDir(false);
	}

	protected function tearDown(): void {
		NodeRole::useMainBuild(null);
		NodeFlows::usePath(null);
		LegacyApiAudit::useDir(false);
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

	/** @return array<string, int>|null */
	private function day(int $rAt = self::NOW): ?array {
		$rDay = json_decode((string) @file_get_contents($this->rDir . 'legacy_api/' . gmdate('Ymd', $rAt) . '.json'), true);
		return is_array($rDay) ? $rDay : null;
	}

	/** @return array<string, mixed>|null */
	private function published(): ?array {
		clearstatcache();
		$rDoc = json_decode((string) @file_get_contents($this->rDir . 'cluster/audit.json'), true);
		return is_array($rDoc) ? $rDoc : null;
	}

	public function testEachCallIsCountedByActionAndCallerAndReported(): void {
		LegacyApiAudit::record('getFile', '51.75.144.208', self::NOW);
		LegacyApiAudit::record('getFile', '51.75.144.208', self::NOW);
		LegacyApiAudit::record('stats', '2001:db8::1', self::NOW);
		$this->assertSame(['getFile 51.75.144.208' => 2, 'stats 2001:db8::1' => 1], $this->day());
		$this->assertSame(['getFile 51.75.144.208' => 2, 'stats 2001:db8::1' => 1], $this->published()['legacy_api'] ?? null, 'a new key publishes at once');
	}

	public function testMainAndModeZeroCountNothing(): void {
		$this->mode(0);
		LegacyApiAudit::record('getFile', '10.0.0.1', self::NOW);
		$this->mode(null);
		LegacyApiAudit::record('getFile', '10.0.0.1', self::NOW);
		$this->mode(2);
		NodeRole::useMainBuild(true);
		LegacyApiAudit::record('getFile', '10.0.0.1', self::NOW);
		$this->assertDirectoryDoesNotExist($this->rDir . 'legacy_api');
	}

	public function testKeysAreBoundedAndOnlyNamesAndAddresses(): void {
		$this->assertSame('? ?', LegacyApiAudit::key('get file; rm', 'not-an-ip'));
		$this->assertSame('view_log 10.0.0.1', LegacyApiAudit::key('view_log', '10.0.0.1'));
		for ($i = 0; $i < LegacyApiAudit::MAX_KEYS + 3; $i++) {
			LegacyApiAudit::record('a' . $i, '10.0.0.1', self::NOW);
		}
		LegacyApiAudit::record('a0', '10.0.0.1', self::NOW); // a known key still counts as itself
		$rDay = $this->day();
		$this->assertCount(LegacyApiAudit::MAX_KEYS + 1, $rDay);
		$this->assertSame(3, $rDay[LegacyApiAudit::OTHER]);
		$this->assertSame(2, $rDay['a0 10.0.0.1']);
	}

	public function testTheReportSumsTheLastSevenDays(): void {
		mkdir($this->rDir . 'legacy_api');
		foreach ([0 => 1, 6 => 2, 7 => 50] as $rDaysAgo => $rCount) {
			file_put_contents($this->rDir . 'legacy_api/' . gmdate('Ymd', self::NOW - $rDaysAgo * 86400) . '.json', json_encode(['getFile 10.0.0.1' => $rCount, 'bad key!' => 9]));
		}
		$this->assertEquals(['legacy_api' => (object) ['getFile 10.0.0.1' => 3]], LegacyApiAudit::report(self::NOW), 'the eighth day out, a bad key dropped');
		exec('rm -rf ' . escapeshellarg($this->rDir . 'legacy_api'));
		$this->assertEquals(['legacy_api' => (object) []], LegacyApiAudit::report(self::NOW), 'no call: an empty report, not none');
		LegacyApiAudit::useDir(false);
		$this->assertSame([], LegacyApiAudit::report(self::NOW), 'no directory: the member is left out');
		$this->assertSame(0, LegacyApiAudit::prune(8, self::NOW + 86400 * 30), 'nothing to prune without a directory');
	}

	public function testMainKeepsTheReportWithTheNode(): void {
		$rDoc = NodeAudit::normalise(['settings_misses' => [], 'legacy_api' => ['getFile 10.0.0.1' => 4, '*' => 1, 'x; y' => 2, 'stats 10.0.0.2' => 0]]);
		$this->assertSame(['getFile 10.0.0.1' => 4, '*' => 1], $rDoc['legacy_api']);
		$this->assertSame('{"settings_misses":{},"legacy_api":{"getFile 10.0.0.1":4,"*":1}}', NodeAudit::encode($rDoc));
		$this->assertArrayNotHasKey('legacy_api', NodeAudit::normalise(['settings_misses' => []]), 'an older node says nothing');
	}

	public function testTheLegacyApiCountsWhatItAnswers(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Api/InternalApiController.php');
		$this->assertGreaterThan(strpos($rSrc, "generateError('API_IP_NOT_ALLOWED')"), strpos($rSrc, 'LegacyApiAudit::record('), 'after the password and the address passed');
		$this->assertStringNotContainsString('Access-Control-Allow-Origin', $rSrc, 'a server-to-server endpoint: no browser reads it');
	}
}
