<?php

use PHPUnit\Framework\TestCase;

/**
 * The flood guard limits how many different passwords an address may try for
 * one username. A refused sign-in hands it the password the request carried,
 * and the address is blocked at the `bruteforce_username_attempts`-th
 * different one inside `bruteforce_frequency` seconds, as it is for different
 * usernames. The same password again is the same guess, so a device that
 * keeps sending an old password is counted once.
 *
 * The count is kept per address and username in the address's `<ip>_user`
 * file, as a keyed digest of each password: the password itself is never
 * written. A sign-in that hands no password is counted as it always was.
 * A username in another letter case shares its count of passwords, and
 * sign-ins refused at the same moment are each counted.
 *
 * FLOOD_TMP_PATH is a constant, so each run is a child PHP with its own.
 */
final class AuditDecisionGuessLimitGuardTest extends TestCase {
	private const IP = '203.0.113.9';
	private const OTHER_IP = '203.0.113.10';

	/** The fourth different password for a username, or the fourth different username, blocks the address. */
	private const SETTINGS = [
		'flood_limit' => 10, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 4, 'bruteforce_mac_attempts' => 4,
		'bruteforce_frequency' => 300, 'live_streaming_pass' => 'guess-limit-test-pass',
	];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-guess-limit-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'flood', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Refused sign-ins, one after the other, in a child PHP that has the suite's
	 * bootstrap and these settings. One without a password is handed to the
	 * guard as the callers that have none do. It may not warn or fail.
	 *
	 * @param list<array{0: string, 1: string, 2: string|null}> $rTries address, username, password as base64
	 * @param array<string, mixed> $rSettings
	 */
	private function refused(array $rTries, array $rSettings = self::SETTINGS): void {
		file_put_contents(
			$this->rDir . 'child.php',
			'<?php define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
			. ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' \XcVm\Core\Config\SettingsManager::set(' . var_export($rSettings, true) . ');'
			. ' \XcVm\Core\Cluster\NodeFlows::usePath(' . var_export($this->rDir . 'flows.json', true) . ');' // none: this server is no cluster node
			. ' foreach (' . var_export($rTries, true) . ' as [$rIP, $rUsername, $rPassword]) {'
			. ' if ($rPassword === null) { \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, null, $rUsername); }'
			. ' else { \XcVm\Core\Auth\BruteforceGuard::checkBruteforce($rIP, null, $rUsername, false, base64_decode($rPassword)); }'
			. ' }'
		);
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'child.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rErr . $rOut);
		$this->assertSame('', $rErr . $rOut);
	}

	/**
	 * Refused sign-ins of one username from one address, a password each.
	 *
	 * @param list<string> $rPasswords
	 * @return list<array{0: string, 1: string, 2: string}>
	 */
	private static function tries(array $rPasswords, string $rUsername = 'viewer', string $rIP = self::IP): array {
		return array_map(static fn(string $rPassword): array => [$rIP, $rUsername, base64_encode($rPassword)], $rPasswords);
	}

	/** @return array<string, mixed>|null what the address's count file holds: null when there is none */
	private function state(string $rIP = self::IP): ?array {
		$rPath = $this->rDir . 'flood/' . $rIP . '_user';
		return is_file($rPath) ? json_decode((string) file_get_contents($rPath), true) : null;
	}

	/** @return list<string> the digests of the different passwords counted for a username of an address */
	private function counted(string $rUsername = 'viewer', string $rIP = self::IP): array {
		return array_map('strval', array_keys($this->state($rIP)['passwords'][$rUsername] ?? []));
	}

	private function isBlocked(string $rIP = self::IP): bool {
		return is_file($this->rDir . 'flood/block_' . $rIP);
	}

	public function testTheLimitOnDifferentUsernamesIsTheLimitOnDifferentPasswordsForOne(): void {
		$this->refused(self::tries(['wrong1', 'wrong2', 'wrong3']));
		$this->assertCount(3, $this->counted());
		$this->assertSame(['viewer'], array_keys($this->state()['attempts']), 'one username');
		$this->assertFalse($this->isBlocked(), 'three different passwords');

		$this->refused(self::tries(['wrong4']));
		$this->assertTrue($this->isBlocked(), 'the fourth different password');
		$this->assertNull($this->state(), 'the count ends with the block, as the one of usernames does');
	}

	public function testTheSamePasswordAgainIsTheSameGuess(): void {
		$this->refused(self::tries(array_merge(array_fill(0, 12, 'old-password'), ['wrong2', 'old-password', 'wrong3', 'old-password'])));
		$this->assertCount(3, $this->counted());
		$this->assertFalse($this->isBlocked());
	}

	public function testEachUsernameHasACountOfItsOwn(): void {
		$this->refused(array_merge(self::tries(['wrong1', 'wrong2', 'wrong3']), self::tries(['wrong1', 'wrong2', 'wrong3'], 'second')));
		$this->assertCount(3, $this->counted());
		$this->assertCount(3, $this->counted('second'));
		$this->assertSame([], array_intersect($this->counted(), $this->counted('second')), 'one password under two usernames is kept as two digests');
		$this->assertFalse($this->isBlocked());
	}

	public function testAUsernameInAnotherLetterCaseSharesItsCountOfPasswords(): void {
		$this->refused(array_merge(self::tries(['wrong1', 'wrong2']), self::tries(['wrong3'], 'Viewer')));
		$this->assertSame(['viewer', 'Viewer'], array_keys($this->state()['attempts']), 'each is a username of its own in the count of usernames, as before');
		$this->assertSame(['viewer'], array_keys($this->state()['passwords']));
		$this->assertCount(3, $this->counted());
		$this->assertFalse($this->isBlocked(), 'three different passwords');

		$this->refused(self::tries(['wrong4'], 'Viewer'));
		$this->assertTrue($this->isBlocked(), 'the fourth different password, in whichever letter case the username came');
	}

	public function testAPasswordIsTheSameGuessInWhicheverLetterCaseTheUsernameCame(): void {
		$rSettings = ['bruteforce_username_attempts' => 10] + self::SETTINGS;
		// Spaces after the username are not part of it either.
		$this->refused(array_merge(self::tries(['old-password']), self::tries(['old-password'], 'VIEWER'), self::tries(['old-password'], 'viewer  ')), $rSettings);
		$this->assertSame(['viewer'], array_keys($this->state()['passwords']));
		$this->assertCount(1, $this->counted());

		// Letters outside ASCII have a case as well.
		$this->refused(array_merge(self::tries(['wrong1'], "\u{00C9}MILE"), self::tries(['wrong1', 'wrong2'], "\u{00E9}mile")), $rSettings);
		$this->assertCount(2, $this->counted("\u{00E9}mile"));
		$this->assertFalse($this->isBlocked());
	}

	public function testRefusedSignInsThatArriveTogetherAreEachCounted(): void {
		$rTogether = 6;
		file_put_contents(
			$this->rDir . 'together.php',
			'<?php define("FLOOD_TMP_PATH", ' . var_export($this->rDir . 'flood/', true) . ');'
			. ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. ' \XcVm\Core\Config\SettingsManager::set(' . var_export(['bruteforce_username_attempts' => 100] + self::SETTINGS, true) . ');'
			. ' \XcVm\Core\Cluster\NodeFlows::usePath(' . var_export($this->rDir . 'flows.json', true) . ');'
			// All that the sign-in loads is loaded before the start, which every child waits for.
			. ' class_exists(\XcVm\Core\Auth\BruteforceGuard::class); class_exists(\XcVm\Core\Util\AtomicFile::class);'
			. ' touch($argv[1] . ".ready");'
			. ' while (!file_exists($argv[2])) { clearstatcache(); }'
			. ' \XcVm\Core\Auth\BruteforceGuard::checkBruteforce(' . var_export(self::IP, true) . ', null, "viewer", false, $argv[3]);'
		);

		// The first round starts the count, the others count on from it.
		for ($rRound = 1; $rRound <= 3; $rRound++) {
			$rStart = $this->rDir . 'start' . $rRound;
			$rProcs = [];
			for ($rChild = 0; $rChild < $rTogether; $rChild++) {
				$rProcs[] = proc_open(
					[PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', $this->rDir . 'together.php', $rStart . '-' . $rChild, $rStart, 'wrong-' . $rRound . '-' . $rChild],
					[0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->rDir . 'together.out', 'a'], 2 => ['file', $this->rDir . 'together.out', 'a']],
					$rPipes
				);
			}
			for ($rWaited = 0; count(glob($rStart . '-*.ready') ?: []) < $rTogether && $rWaited < 60000; $rWaited++) {
				usleep(1000);
			}
			touch($rStart);
			foreach ($rProcs as $rProc) {
				$this->assertIsResource($rProc);
				$this->assertSame(0, proc_close($rProc));
			}
			$this->assertSame('', (string) file_get_contents($this->rDir . 'together.out'));
			$this->assertCount($rRound * $rTogether, $this->counted(), 'round ' . $rRound . ': ' . $rTogether . ' different passwords at once');
		}
		$this->assertSame([self::IP . '_user'], array_values(array_diff(scandir($this->rDir . 'flood'), ['.', '..'])), 'nothing is left beside the count');
	}

	public function testEachAddressHasACountOfItsOwn(): void {
		$this->refused(array_merge(self::tries(['wrong1', 'wrong2', 'wrong3']), self::tries(['wrong4', 'wrong5', 'wrong6'], 'viewer', self::OTHER_IP)));
		$this->assertFalse($this->isBlocked());
		$this->assertFalse($this->isBlocked(self::OTHER_IP));

		$this->refused(self::tries(['wrong7']));
		$this->assertTrue($this->isBlocked());
		$this->assertFalse($this->isBlocked(self::OTHER_IP), 'the other address is not blocked with it');
		$this->assertCount(3, $this->counted('viewer', self::OTHER_IP), 'and keeps its count');
	}

	public function testAPasswordTriedBeforeTheWindowIsNoLongerCounted(): void {
		$this->refused(self::tries(['wrong1', 'wrong2', 'wrong3']));
		$rState = $this->state();
		$rDigests = $this->counted();
		// The first two were tried longer ago than `bruteforce_frequency`.
		$rState['passwords']['viewer'][$rDigests[0]] = $rState['passwords']['viewer'][$rDigests[1]] = time() - 301;
		file_put_contents($this->rDir . 'flood/' . self::IP . '_user', (string) json_encode($rState));

		$this->refused(self::tries(['wrong4', 'wrong5']));
		$this->assertSame($rDigests[2], $this->counted()[0]);
		$this->assertCount(3, $this->counted());
		$this->assertFalse($this->isBlocked(), 'three different passwords inside the window');

		$this->refused(self::tries(['wrong6']));
		$this->assertTrue($this->isBlocked(), 'the fourth inside the window');
	}

	public function testThePasswordIsKeptAsAKeyedDigestNeverItself(): void {
		$rPassword = 'Tr0ub4dor&3-wrong';
		$this->refused(self::tries([$rPassword]));
		$rFile = (string) file_get_contents($this->rDir . 'flood/' . self::IP . '_user');
		$this->assertStringNotContainsString($rPassword, $rFile);
		$rDigests = $this->counted();
		$this->assertCount(1, $rDigests);
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16,64}$/', $rDigests[0]);
		foreach (['md5', 'sha1', 'sha256'] as $rAlgorithm) {
			$this->assertStringNotContainsString(substr(hash($rAlgorithm, $rPassword), 0, 16), $rFile, 'a digest anyone can compute: ' . $rAlgorithm);
		}

		// The key is the panel's own: under another, the same password is kept as another digest.
		unlink($this->rDir . 'flood/' . self::IP . '_user');
		$this->refused(self::tries([$rPassword]), ['live_streaming_pass' => 'another-panel'] + self::SETTINGS);
		$rOther = $this->counted();
		$this->assertCount(1, $rOther);
		$this->assertNotSame($rDigests, $rOther);

		// A server that holds no such setting still counts, by its other key.
		unlink($this->rDir . 'flood/' . self::IP . '_user');
		$this->refused(self::tries([$rPassword, $rPassword, 'wrong2']), array_diff_key(self::SETTINGS, ['live_streaming_pass' => true]));
		$this->assertCount(2, $this->counted());
		$this->assertSame([], array_intersect($this->counted(), $rDigests, $rOther));
	}

	public function testAPasswordOfAnyBytesIsCounted(): void {
		$this->refused(self::tries(["wr\xffong", "wr\xfeong", "wr\xffong"], "vi\xffewer"));
		$rState = $this->state();
		$this->assertCount(1, $rState['attempts']);
		$this->assertCount(2, current($rState['passwords']));
	}

	public function testASignInThatHandsNoPasswordIsCountedAsItWas(): void {
		// A token, an activation code: the same one again is the same guess, and no password is counted.
		$this->refused(array_fill(0, 12, [self::IP, 'GUESS0', null]));
		$this->assertSame(['attempts'], array_keys($this->state()));
		$this->assertSame(['GUESS0'], array_keys($this->state()['attempts']));
		// Neither is an empty one.
		$this->refused(self::tries(['', ''], 'GUESS0'));
		$this->assertSame([], $this->counted('GUESS0'));
		$this->assertFalse($this->isBlocked());

		$this->refused([[self::IP, 'GUESS1', null], [self::IP, 'GUESS2', null]]);
		$this->assertFalse($this->isBlocked(), 'three different usernames');
		$this->refused([[self::IP, 'GUESS3', null]]);
		$this->assertTrue($this->isBlocked(), 'the fourth different username');
	}

	public function testDifferentUsernamesAreCountedAsBeforeWhenEachHandsAPassword(): void {
		$this->refused(array_merge(self::tries(['same'], 'guess1'), self::tries(['same'], 'guess2'), self::tries(['same', 'same'], 'guess3')));
		$this->assertSame(['guess1', 'guess2', 'guess3'], array_keys($this->state()['attempts']));
		$this->assertFalse($this->isBlocked(), 'three different usernames');

		$this->refused(self::tries(['same'], 'guess4'));
		$this->assertTrue($this->isBlocked(), 'the fourth different username');
	}

	public function testACountKeptBeforePasswordsWereCountedIsCountedOn(): void {
		file_put_contents($this->rDir . 'flood/' . self::IP . '_user', (string) json_encode(['attempts' => ['viewer' => time() - 5, 'other' => time() - 5]]));

		$this->refused(self::tries(['wrong1', 'wrong2']));
		$this->assertSame(['viewer', 'other'], array_keys($this->state()['attempts']));
		$this->assertCount(2, $this->counted());
		$this->assertFalse($this->isBlocked());
	}

	public function testWithoutALimitNothingIsCounted(): void {
		$this->refused(self::tries(['wrong1', 'wrong2', 'wrong3', 'wrong4', 'wrong5']), ['bruteforce_username_attempts' => 0] + self::SETTINGS);
		$this->assertSame([], glob($this->rDir . 'flood/*') ?: []);
	}
}
