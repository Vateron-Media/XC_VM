<?php

use PHPUnit\Framework\TestCase;

/**
 * The flood guard keeps each count in a JSON file named after the address
 * (AuditGuardStateTest). Requests of one address read and write a count at
 * once, so a count is never rewritten in place: a request reads the state
 * another wrote before or after, never a file that is being written.
 *
 * FLOOD_TMP_PATH is a constant, so each run is a child PHP with its own.
 */
final class AuditReviewStreamGuardCountTest extends TestCase {
	private const IP = '203.0.113.9';

	private const SETTINGS = [
		'flood_limit' => 10, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 5, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 10, 'auth_flood_seconds' => 10, 'auth_flood_sleep' => 0,
	];

	/** The count files of the three checks below: refused requests, usernames tried, a line's requests. */
	private const FILES = [self::IP, self::IP . '_user', '5_' . self::IP];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-guard-count-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** One refused request of the address, with this username, in a child PHP that has the suite's bootstrap and the settings above. It may not warn or fail. */
	private function refused(string $rUsername): void {
		file_put_contents(
			$this->rDir . 'child.php',
			'<?php define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
			. ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' \XcVm\Core\Config\SettingsManager::set(' . var_export(self::SETTINGS, true) . ');'
			. ' \XcVm\Core\Cluster\NodeFlows::usePath(' . var_export($this->rDir . 'flows.json', true) . ');' // none: this server is no cluster node
			. ' $rIP = ' . var_export(self::IP, true) . ';'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkFlood($rIP);'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, null, ' . var_export($rUsername, true) . ');'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkAuthFlood(["id" => 5], $rIP);'
		);
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'child.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('', $rErr . $rOut);
	}

	/** @return array<string, int> the inode of each count file */
	private function inodes(): array {
		clearstatcache();
		return array_map(fn(string $rFile): int => (int) fileinode($this->rDir . 'flood/' . $rFile), array_combine(self::FILES, self::FILES));
	}

	/** @return array<string, mixed> what a count file holds */
	private function state(string $rFile): array {
		return (array) json_decode((string) file_get_contents($this->rDir . 'flood/' . $rFile), true);
	}

	public function testACountFileIsReplacedNeverRewrittenInPlace(): void {
		$this->refused('a');
		$rFirst = $this->inodes();

		// The next request counts on from what the first wrote, each in a file of its own.
		$this->refused('b');
		$rSecond = $this->inodes();
		foreach (self::FILES as $rFile) {
			$this->assertNotSame($rFirst[$rFile], $rSecond[$rFile], $rFile . ' was rewritten in place');
		}
		$this->assertSame(1, $this->state(self::IP)['requests']);
		$this->assertSame(['a', 'b'], array_keys($this->state(self::IP . '_user')['attempts']));
		$this->assertCount(2, $this->state('5_' . self::IP)['attempts']);

		// So is a count that starts again once its window has passed.
		file_put_contents($this->rDir . 'flood/' . self::IP, (string) json_encode(['requests' => 3, 'last_request' => time() - 3600]));
		$this->refused('b');
		$this->assertNotSame($rSecond[self::IP], $this->inodes()[self::IP], 'the count that started again was rewritten in place');
		$this->assertSame(0, $this->state(self::IP)['requests']);

		// Nothing written aside is left beside the counts.
		$this->assertSame(self::FILES, array_values(array_diff(scandir($this->rDir . 'flood'), ['.', '..'])));
	}
}
