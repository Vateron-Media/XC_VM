<?php

use PHPUnit\Framework\TestCase;

/**
 * The admin and the reseller panel start their session before the framework
 * boot, as the web player does. All three start it the way the framework's
 * session stage does: the cookie is kept from scripts and from other sites,
 * and only an id this server issued is taken. A session that is signed in is
 * carried on as before, and so is the hour after which an idle one ends.
 *
 * Every case runs in a child PHP: an unanswered page ends in exit(), and PHP
 * refuses to touch a session once output has started.
 */
final class AuditPlayerJsPanelSessionTest extends TestCase {
	private const KNOWN = 'issuedbythisserver00000000';
	private const UNKNOWN = 'neverissuedbythisserver000';

	/** Opens a page of the scope $rScope with the session cookie $rId, up to the framework boot. */
	private const PAGE = <<<'PHP'
		$_COOKIE[session_name()] = $rId;
		$rSession = new ReflectionMethod('XcVm\\Infrastructure\\Bootstrap\\' . $rScope . 'ScopeBootstrap', 'bootSession');
		$rSession->setAccessible(true);
		$rSession->invoke($rSession->getDeclaringClass()->newInstance());
		PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-panel-session-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Open a page of $rScope with the cookie $rId and return the session it ends with.
	 *
	 * @param array<string, int>|null $rStored what the session $rId holds on this server, null when it has none
	 * @return array{id: string, session: array<string, mixed>, cookie: array<string, mixed>}
	 */
	private function page(string $rScope, string $rId, ?array $rStored = null): array {
		if ($rStored !== null) {
			$rData = '';
			foreach ($rStored as $rKey => $rValue) {
				$rData .= $rKey . '|i:' . $rValue . ';';
			}
			file_put_contents($this->rDir . 'sess_' . $rId, $rData);
		}

		$rSetup = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'ini_set("session.save_path", ' . var_export($this->rDir, true) . ');'
			. 'ini_set("session.serialize_handler", "php");'
			. 'extract(' . var_export(['rScope' => $rScope, 'rId' => $rId], true) . ');'
			. 'register_shutdown_function(static function () {'
			. ' $rOut = ["id" => session_id(), "session" => $_SESSION ?? [], "cookie" => session_get_cookie_params()];'
			. ' session_write_close();'
			. ' echo json_encode($rOut);'
			. '});';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rSetup . self::PAGE], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rResult = json_decode((string) $rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);

		return $rResult;
	}

	/** @return list<array{string}> */
	public static function panels(): array {
		return [['Admin'], ['Reseller']];
	}

	/** @dataProvider panels */
	public function testAPanelPageStartsTheSessionAsTheSessionStageDoes(string $rScope): void {
		$rEnd = $this->page($rScope, self::UNKNOWN);

		$this->assertNotSame(self::UNKNOWN, $rEnd['id'], 'the page took a session id this server never issued');
		$this->assertNotSame('', $rEnd['id']);
		$this->assertTrue($rEnd['cookie']['httponly'], 'scripts can read the session cookie');
		$this->assertSame('Strict', $rEnd['cookie']['samesite']);
	}

	public function testASignedInAdministratorKeepsTheSession(): void {
		$rEnd = $this->page('Admin', self::KNOWN, ['hash' => 4, 'last_activity' => time() - 600]);

		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertSame(4, $rEnd['session']['hash'] ?? null);
		$this->assertGreaterThan(time() - 60, $rEnd['session']['last_activity']);
	}

	public function testASignedInResellerKeepsTheSession(): void {
		$rEnd = $this->page('Reseller', self::KNOWN, ['reseller' => 4, 'rlast_activity' => time() - 600]);

		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertSame(4, $rEnd['session']['reseller'] ?? null);
		$this->assertGreaterThan(time() - 60, $rEnd['session']['rlast_activity']);
	}

	/** An hour without a request ends the login; the session itself stays the visitor's. */
	public function testAnIdleLoginStillEndsAfterAnHour(): void {
		$rEnd = $this->page('Admin', self::KNOWN, ['hash' => 4, 'last_activity' => time() - 3700]);
		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertArrayNotHasKey('hash', $rEnd['session']);

		$rEnd = $this->page('Reseller', self::KNOWN, ['reseller' => 4, 'rlast_activity' => time() - 3700]);
		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertArrayNotHasKey('reseller', $rEnd['session']);
	}

	/** The rules above are written once, in the session stage. */
	public function testEveryScopeStartsItsSessionThroughTheSessionStage(): void {
		foreach (['Admin', 'Reseller', 'Player'] as $rScope) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Infrastructure/Bootstrap/' . $rScope . 'ScopeBootstrap.php');

			$this->assertSame(0, substr_count($rSource, 'session_start('), $rScope . ' starts a session of its own');
			$this->assertGreaterThan(0, substr_count($rSource, 'SessionStage::startSession();'), $rScope);
		}
	}
}
