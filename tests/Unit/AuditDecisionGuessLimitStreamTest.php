<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Util\Encryption;

/**
 * The stream entry scripts (auth.php, rtmp.php through RtmpViewerAuth, probe.php) hand a name and
 * password that match no line to the flood guard, both of them: an address
 * may try fewer than `bruteforce_username_attempts` different passwords for
 * one username in `bruteforce_frequency` seconds, and the same one again is
 * the same guess. A line's token carries no password.
 *
 * The scripts are procedural, so the lines under test are read from the
 * script and run in a child PHP (with its own FLOOD_TMP_PATH), where an error
 * answer is thrown instead of ending the process.
 */
final class AuditDecisionGuessLimitStreamTest extends TestCase {
	private const AUTH = 'Public/stream/auth.php';
	private const RTMP = 'Domain/User/RtmpViewerAuth.php';
	private const PROBE = 'Public/stream/probe.php';
	private const IP = '203.0.113.9';
	private const PASS = 'streaming-pass';

	/** The third different password for a username blocks the address. */
	private const SETTINGS = [
		'flood_limit' => 40, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 3, 'bruteforce_frequency' => 300,
		'live_streaming_pass' => self::PASS, 'secure_stream_tokens' => 0, 'ignore_invalid_users' => 0, 'enable_cache' => 1,
	];

	/** The line lookup is the repository's: here it knows the line `reseller` with the password `secret`. */
	private const LINES = 'class GuessLimitLines { public static function getStreamingUserInfo($rSettings, $rCached, $rBouquets, $rUserID, $rUsername, $rPassword) { return [$rUsername, $rPassword] === ["reseller", "secret"] ? ["id" => 1] : null; } }'
		. ' class_alias("GuessLimitLines", UserRepository::class);';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-guess-limit-stream-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The source of $rFile from $rFrom up to $rTo. */
	private function lines(string $rFile, string $rFrom, string $rTo): string {
		$rSource = (string) file_get_contents(MAIN_HOME . $rFile);
		$rStart = strpos($rSource, $rFrom);
		$this->assertNotFalse($rStart, $rFile . ' has no: ' . $rFrom);
		$rEnd = strpos($rSource, $rTo, $rStart);
		$this->assertNotFalse($rEnd, $rFile . ' has no: ' . $rTo);
		return substr($rSource, $rStart, $rEnd - $rStart);
	}

	/** The statement of $rFile that hands the name of a refused sign-in to the guard, as it stands there: the one before the sign-in is logged as failed. */
	private function refusedStatement(string $rFile): string {
		$rFound = preg_match_all('/(BruteforceGuard::checkBruteforce\(\$rIP, null, \$rUsername[^;]*\);)\s*(?:\}\s*)?DatabaseLogger::clientLog\(\$rStreamID, 0, \'AUTH_FAILED\', \$rIP\);/', (string) file_get_contents(MAIN_HOME . $rFile), $rCall);
		$this->assertSame(1, $rFound, $rFile);
		return $rCall[1][0];
	}

	/**
	 * Runs $rLines in a child PHP with the imports of the entry script $rFile,
	 * once per request and with the request's variables, at a panel with the
	 * settings above. It may not warn.
	 *
	 * @param list<array<string, mixed>> $rRequests
	 */
	private function script(string $rFile, string $rLines, array $rRequests): void {
		preg_match_all('/^use [^;]+;$/m', (string) file_get_contents(MAIN_HOME . $rFile), $rUses);
		$rCode = 'define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');' . "\n"
			. 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
			. implode("\n", $rUses[0]) . "\n"
			. '\XcVm\Core\Error\ErrorResponder::$throwInsteadOfExit = true;' . "\n"
			. '\XcVm\Core\Cluster\NodeFlows::usePath(' . var_export($this->rDir . 'flows.json', true) . ');' . "\n" // none: this server is no cluster node
			. '$rSettings = ' . var_export(self::SETTINGS, true) . ';' . "\n"
			. self::LINES . "\n"
			. '$rRun = static function (array $rVars) { extract($rVars);' . "\n" . $rLines . "\n" . '};' . "\n"
			. 'foreach (' . var_export($rRequests, true) . ' as $rVars) {' . "\n"
			. '	ob_start();' . "\n"
			. '	try { $rRun($rVars + ["rIP" => ' . var_export(self::IP, true) . ', "rSettings" => $rSettings, "rCached" => false, "rBouquets" => []]); } catch (\XcVm\Core\Error\ErrorResponseException $e) { }' . "\n"
			. '	ob_end_clean();' . "\n"
			. '}';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $rCode], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('', $rErr . $rOut);
	}

	/** @return array<string, mixed> what the address's count file holds: nothing when there is none */
	private function state(): array {
		$rPath = $this->rDir . 'flood/' . self::IP . '_user';
		return is_file($rPath) ? (array) json_decode((string) file_get_contents($rPath), true) : [];
	}

	/** How many different passwords are counted against the address for a username. */
	private function counted(string $rUsername): int {
		return count($this->state()['passwords'][$rUsername] ?? []);
	}

	private function isBlocked(): bool {
		return is_file($this->rDir . 'flood/block_' . self::IP);
	}

	/** A request's variables for each password, by $rRequest. */
	private function tries(callable $rRequest, string ...$rPasswords): array {
		return array_map(static fn(string $rPassword): array => $rRequest($rPassword), array_values($rPasswords));
	}

	/**
	 * The ways a stream request names a line by username and password: where
	 * the script reads them, and the request for a username and password.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function signIns(): array {
		return [
			'a stream link' => [self::AUTH, '$rUsername = $rRequest[\'username\'];', 'plain'],
			'an RTMP link' => [self::RTMP, '$rUsername = $rRequest[\'username\']', 'plain'],
			'an RTMP link with a playlist token' => [self::RTMP, '$rTokenData = explode(', 'token'],
		];
	}

	#[DataProvider('signIns')]
	public function testARefusedNameAndPasswordAreBothHandedToTheGuard(string $rFile, string $rFrom, string $rForm): void {
		// What the script reads the name and password from, then what it does once they matched no line.
		$rLines = $this->lines($rFile, $rFrom, '$rUserInfo = UserRepository::getStreamingUserInfo(') . $this->refusedStatement($rFile);
		$rRequest = static fn(string $rPassword): array => ['rRequest' => ($rForm === 'plain'
			? ['username' => 'viewer', 'password' => $rPassword]
			: ['token' => Encryption::seal('viewer/' . $rPassword, self::PASS, OPENSSL_EXTRA)])];

		// A device that keeps an old password, and one mistyped: two different ones.
		$this->script($rFile, $rLines, $this->tries($rRequest, 'old-password', 'old-password', 'old-password', 'mistyped', 'old-password'));
		$this->assertSame(['viewer'], array_keys($this->state()['attempts'] ?? []));
		$this->assertSame(2, $this->counted('viewer'));
		$this->assertFalse($this->isBlocked(), 'two different passwords');

		$this->script($rFile, $rLines, $this->tries($rRequest, 'another'));
		$this->assertTrue($this->isBlocked(), 'the third different password');
	}

	/**
	 * The paths a probe names a line by username and password with.
	 *
	 * @return array<string, array{0: string, 1: bool}> the path (or what its token seals) for a username and password
	 */
	public static function probePaths(): array {
		return [
			'live link' => ['/live/%s/%s/5.ts', false],
			'live link without an extension' => ['/live/%s/%s/5', false],
			'short link' => ['/%s/%s/5.ts', false],
			'short link without an extension' => ['/%s/%s/5', false],
			'playlist link' => ['live/%s/%s/5', true],
			'playlist link with an extension' => ['live/%s/%s/5', true, '/ts'],
		];
	}

	#[DataProvider('probePaths')]
	public function testProbeHandsTheGuardThePasswordOfANameThatMatchesNoLine(string $rPath, bool $rSealed, string $rSuffix = ''): void {
		$rLines = $this->lines(self::PROBE, '$rPath = base64_decode(', 'if ($rStreamID && $rUserInfo) {');
		$rProbe = static function (string $rPassword, string $rUsername = 'guess') use ($rPath, $rSealed, $rSuffix): array {
			$rPath = sprintf($rPath, $rUsername, $rPassword);
			return ['rRequest' => ['data' => base64_encode($rSealed ? '/play/' . Encryption::seal($rPath, self::PASS, OPENSSL_EXTRA) . $rSuffix : $rPath)]];
		};

		$this->script(self::PROBE, $rLines, $this->tries($rProbe, 'old-password', 'old-password', 'mistyped', 'old-password'));
		$this->assertSame(['guess'], array_keys($this->state()['attempts'] ?? []));
		$this->assertSame(2, $this->counted('guess'));

		// A line's own name and password are not counted.
		$this->script(self::PROBE, $rLines, [$rProbe('secret', 'reseller')]);
		$this->assertSame(['guess'], array_keys($this->state()['attempts'] ?? []));
		$this->assertFalse($this->isBlocked(), 'two different passwords');

		$this->script(self::PROBE, $rLines, $this->tries($rProbe, 'another'));
		$this->assertTrue($this->isBlocked(), 'the third different password');
	}

	public function testProbeCountsNoPasswordForATokenOrWhereAuthIgnoresUnknownLines(): void {
		$rLines = $this->lines(self::PROBE, '$rPath = base64_decode(', 'if ($rStreamID && $rUserInfo) {');
		$rRequests = [];
		foreach (['one', 'two', 'three', 'four'] as $rIndex => $rPassword) {
			// A line's token, each time another.
			$rRequests[] = ['rRequest' => ['data' => base64_encode('/live/' . str_repeat((string) $rIndex, 32) . '/5.ts')]];
			$rRequests[] = ['rRequest' => ['data' => base64_encode('/live/guess/' . $rPassword . '/5.ts')], 'rSettings' => ['ignore_invalid_users' => 1] + self::SETTINGS];
		}

		$this->script(self::PROBE, $rLines, $rRequests);
		$this->assertSame([], $this->state());
		$this->assertFalse($this->isBlocked());
	}
}
