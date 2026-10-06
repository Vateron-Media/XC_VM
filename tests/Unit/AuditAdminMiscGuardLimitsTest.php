<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The flood guard counts the different MACs and usernames an address tried
 * only where the panel sets a limit on them (`bruteforce_mac_attempts`,
 * `bruteforce_username_attempts`). A limit that is not set, whether the
 * settings hold no such key or an empty value, means no count: the check says
 * nothing and writes nothing, as checkFlood() and checkAuthFlood() do without
 * theirs.
 *
 * FLOOD_TMP_PATH is a constant, so each case runs in a child PHP with its own.
 */
final class AuditAdminMiscGuardLimitsTest extends TestCase {
	private const IP = '203.0.113.9';

	/** The settings the guard reads besides the two limits. */
	private const SETTINGS = ['flood_limit' => 10, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_frequency' => 300];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-guard-limits-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Two different usernames, then two different MACs, from the address IP, in
	 * a child PHP that has the suite's bootstrap and these settings.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return string what the child said: a warning is said too
	 */
	private function guesses(array $rSettings): string {
		file_put_contents(
			$this->rDir . 'child.php',
			'<?php define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
			. ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' \XcVm\Core\Config\SettingsManager::set(' . var_export($rSettings, true) . ');'
			. ' \XcVm\Core\Cluster\NodeFlows::usePath(' . var_export($this->rDir . 'flows.json', true) . ');' // none: this server is no cluster node
			. ' $rIP = ' . var_export(self::IP, true) . ';'
			. ' foreach (["guess1", "guess2"] as $rUsername) { \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, null, $rUsername); }'
			. ' foreach (["00:1A:79:00:00:01", "00:1A:79:00:00:02"] as $rMAC) { \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, $rMAC); }'
		);
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'child.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);

		return $rErr . $rOut;
	}

	/** @return list<string> the files the guard keeps for the address */
	private function kept(): array {
		return array_map('basename', glob($this->rDir . 'flood/*') ?: []);
	}

	/** @return array<string, array{0: array<string, mixed>}> the two limits as the settings hold them */
	public static function limitsThatAreNotSet(): array {
		return [
			'no such keys' => [[]],
			'null' => [['bruteforce_username_attempts' => null, 'bruteforce_mac_attempts' => null]],
			'an empty value' => [['bruteforce_username_attempts' => '', 'bruteforce_mac_attempts' => '']],
			'zero' => [['bruteforce_username_attempts' => 0, 'bruteforce_mac_attempts' => 0]],
			'zero as the database gives it' => [['bruteforce_username_attempts' => '0', 'bruteforce_mac_attempts' => '0']],
		];
	}

	/** @param array<string, mixed> $rLimits */
	#[DataProvider('limitsThatAreNotSet')]
	public function testALimitThatIsNotSetMeansNoCount(array $rLimits): void {
		$this->assertSame('', $this->guesses($rLimits + self::SETTINGS));
		$this->assertSame([], $this->kept());
	}

	public function testALimitThatIsSetIsCounted(): void {
		$this->assertSame('', $this->guesses(['bruteforce_username_attempts' => 3, 'bruteforce_mac_attempts' => '3'] + self::SETTINGS));
		$this->assertSame([self::IP . '_mac', self::IP . '_user'], $this->kept());
	}
}
