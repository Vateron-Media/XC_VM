<?php

use PHPUnit\Framework\TestCase;

/**
 * The flood guard keeps each count in a JSON file named after the address:
 * `<ip>` for refused requests, `<ip>_mac` and `<ip>_user` for the different
 * MACs and usernames an address tried, `<line>_<ip>` for a line's requests.
 *
 * Whatever bytes a request sent as its MAC or username are counted like any
 * other, and a count file that does not read as the state the guard wrote
 * starts its count again.
 *
 * FLOOD_TMP_PATH is a constant, so each case runs in a child PHP with its own.
 */
final class AuditGuardStateTest extends TestCase {
	private const IP = '203.0.113.9';

	/** Three different MACs or usernames from one address block it. */
	private const SETTINGS = [
		'flood_limit' => 10, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 3, 'bruteforce_mac_attempts' => 3,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 10, 'auth_flood_seconds' => 10, 'auth_flood_sleep' => 0,
	];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-guard-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Runs $rCode in a child PHP that has the suite's bootstrap and the settings above. It may not warn or fail. */
	private function guard(string $rCode): void {
		file_put_contents(
			$this->rDir . 'child.php',
			'<?php define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
			. ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' \XcVm\Core\Config\SettingsManager::set(' . var_export(self::SETTINGS, true) . ');'
			. ' \XcVm\Core\Cluster\NodeFlows::usePath(' . var_export($this->rDir . 'flows.json', true) . ');' // none: this server is no cluster node
			. ' $rIP = ' . var_export(self::IP, true) . '; ' . $rCode
		);
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'child.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('', $rErr . $rOut);
	}

	/** @return array<string, mixed>|null what a count file holds: null when there is none */
	private function state(string $rFile): ?array {
		$rPath = $this->rDir . 'flood/' . $rFile;
		return is_file($rPath) ? json_decode((string) file_get_contents($rPath), true) : null;
	}

	public function testATermThatIsNotValidUtf8IsCountedLikeAnyOther(): void {
		foreach (['mac' => '$rIP, "AA\xff"', 'user' => '$rIP, null, "gu\xffess"'] as $rType => $rFirst) {
			$rOther = ($rType === 'mac' ? '$rIP, "00:1A:79:00:00:01"' : '$rIP, null, "viewer"');
			$rThird = ($rType === 'mac' ? '$rIP, "AA\xfe"' : '$rIP, null, "gu\xfeess"');
			$rCheck = static fn(string $rArguments): string => '\XcVm\Core\Auth\BruteforceGuard::checkBruteforce(' . $rArguments . ');';

			// The same one twice is one, and the next is counted with it.
			$this->guard($rCheck($rFirst) . $rCheck($rFirst) . $rCheck($rOther) . $rCheck($rOther));
			$this->assertCount(2, $this->state(self::IP . '_' . $rType)['attempts'] ?? [], $rType);
			$this->assertFileDoesNotExist($this->rDir . 'flood/block_' . self::IP, $rType);

			// One that differs from the first in such a byte alone is another.
			$this->guard($rCheck($rThird));
			$this->assertFileExists($this->rDir . 'flood/block_' . self::IP, 'the third different ' . $rType);
			unlink($this->rDir . 'flood/block_' . self::IP);
		}
	}

	public function testACountFileThatDoesNotReadStartsItsCountAgain(): void {
		// A count file that holds no state: empty, cut short, or not an array.
		foreach (['', '{"attempts":{"some', 'null'] as $rContent) {
			foreach ([self::IP, self::IP . '_user', self::IP . '_mac', '5_' . self::IP] as $rFile) {
				file_put_contents($this->rDir . 'flood/' . $rFile, $rContent);
			}

			$this->guard(
				'\XcVm\Core\Auth\BruteforceGuard::checkFlood($rIP);'
				. ' \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, null, "someone");'
				. ' \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, "00:1A:79:00:00:01");'
				. ' \XcVm\Core\Auth\BruteforceGuard::checkAuthFlood(["id" => 5], $rIP);'
			);

			$rLabel = var_export($rContent, true);
			$this->assertSame(0, $this->state(self::IP)['requests'] ?? null, $rLabel);
			$this->assertSame(['someone'], array_keys($this->state(self::IP . '_user')['attempts'] ?? []), $rLabel);
			$this->assertSame(['00:1A:79:00:00:01'], array_keys($this->state(self::IP . '_mac')['attempts'] ?? []), $rLabel);
			$this->assertCount(1, $this->state('5_' . self::IP)['attempts'] ?? [], $rLabel);
			$this->assertFileDoesNotExist($this->rDir . 'flood/block_' . self::IP, $rLabel);
		}
	}
}
