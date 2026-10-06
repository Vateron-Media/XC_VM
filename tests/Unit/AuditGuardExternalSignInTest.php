<?php

use PHPUnit\Framework\TestCase;

/**
 * The web player signs a visitor in with a line of this panel or with an
 * account on another Xtream server. Either sign-in, when refused, counts
 * against the visitor's address in the flood guard.
 *
 * FLOOD_TMP_PATH is a constant, so the sign-in runs in a child PHP with its own.
 */
final class AuditGuardExternalSignInTest extends TestCase {
	private const IP = '203.0.113.9';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-guard-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testARefusedExternalSignInCountsAgainstTheAddress(): void {
		// The server named is this machine itself, which the sign-in refuses before it connects anywhere.
		file_put_contents(
			$this->rDir . 'child.php',
			'<?php define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
			. ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' \XcVm\Core\Config\SettingsManager::set(["flood_limit" => 10, "flood_seconds" => 60, "flood_ips_exclude" => ""]);'
			. ' $_SERVER["REMOTE_ADDR"] = ' . var_export(self::IP, true) . ';'
			. ' $rSignIn = new ReflectionMethod(\XcVm\Public\Controllers\PlayerV2\PlayerLoginController::class, "processExternalXtreamLogin");'
			. ' $rSignIn->setAccessible(true);'
			. ' $rAnswers = [];'
			. ' foreach ([1, 2] as $rTime) { $rAnswers[] = $rSignIn->invoke(new \XcVm\Public\Controllers\PlayerV2\PlayerLoginController(), "http://127.0.0.1:9", "viewer", "secret")["success"]; }'
			. ' echo json_encode($rAnswers);'
		);
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'child.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('[false,false]', $rOut, $rErr);

		$rFile = $this->rDir . 'flood/' . self::IP;
		$this->assertFileExists($rFile, 'the first refused sign-in starts the count');
		$this->assertSame(1, json_decode((string) file_get_contents($rFile), true)['requests'], 'and the second adds to it');
	}
}
