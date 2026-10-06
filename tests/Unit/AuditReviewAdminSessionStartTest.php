<?php

use PHPUnit\Framework\TestCase;

/**
 * Two admin requests start their session before any framework boot: the
 * session check at <access code>/session, and a form save, which nginx hands
 * to Public/Views/admin/post.php itself. Both start it the way the framework's
 * session stage does, as every panel page does: the cookie is kept from
 * scripts and from other sites, and only an id this server issued is taken. A
 * session that is signed in is carried on as before, and so is the hour after
 * which an idle one ends.
 *
 * Every case runs in a child PHP: these requests end in exit(), and PHP
 * refuses to touch a session once output has started.
 */
final class AuditReviewAdminSessionStartTest extends TestCase {
	private const KNOWN = 'issuedbythisserver00000000';
	private const UNKNOWN = 'neverissuedbythisserver000';

	/** The session check. */
	private const HEARTBEAT = '(new XcVm\\Public\\Controllers\\Admin\\SessionController())->index();';

	/** What post.php, setup.php and player.php open with. */
	private const MANAGER = 'XcVm\\Core\\Auth\\SessionManager::start("admin");';

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-admin-session-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Make a request with the session cookie $rId and return what it ends with.
	 *
	 * @param string $rRun the request: PHP code, or the path of the script nginx runs for it
	 * @param array<string, int>|null $rStored what the session $rId holds on this server, null when it has none
	 * @return array{id: string, body: string, session: array<string, mixed>, cookie: array<string, mixed>}
	 */
	private function request(string $rRun, string $rId, ?array $rStored = null): array {
		if ($rStored !== null) {
			$rData = '';
			foreach ($rStored as $rKey => $rValue) {
				$rData .= $rKey . '|i:' . $rValue . ';';
			}
			file_put_contents($this->rDir . 'sess_' . $rId, $rData);
		}

		$rScript = is_file($rRun);
		$rSetup = ($rScript ? '<?php ' : 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';')
			. 'ini_set("session.save_path", ' . var_export($this->rDir, true) . ');'
			. 'ini_set("session.serialize_handler", "php");'
			. '$_COOKIE[session_name()] = ' . var_export($rId, true) . ';'
			. '$_SERVER["REQUEST_URI"] = "/code/' . ($rScript ? basename($rRun) : 'session') . '";'
			. 'ob_start();'
			. 'register_shutdown_function(static function () {'
			. ' $rOut = ["id" => session_id(), "body" => (string) ob_get_clean(), "session" => $_SESSION ?? [], "cookie" => session_get_cookie_params()];'
			. ' session_write_close();'
			. ' echo json_encode($rOut);'
			. '});';

		// The session ini the panel ships with (bin/php/lib/php.ini).
		$rPhp = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'session.use_strict_mode=0'];
		if ($rScript) {
			file_put_contents($this->rDir . 'prepend.php', $rSetup);
			$rPhp = [...$rPhp, '-d', 'auto_prepend_file=' . $this->rDir . 'prepend.php', $rRun];
		} else {
			$rPhp = [...$rPhp, '-r', $rSetup . $rRun];
		}

		$rProc = proc_open($rPhp, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rResult = json_decode((string) $rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);

		return $rResult;
	}

	/** @return array<string, array{string}> */
	public static function firstStarts(): array {
		return [
			'the session check' => [self::HEARTBEAT],
			'a form save' => [MAIN_HOME . 'Public/Views/admin/post.php'],
			'the session manager' => [self::MANAGER],
		];
	}

	/** @dataProvider firstStarts */
	public function testARequestStartsTheSessionAsTheSessionStageDoes(string $rRun): void {
		$rEnd = $this->request($rRun, self::UNKNOWN);

		$this->assertNotSame(self::UNKNOWN, $rEnd['id'], 'the request took a session id this server never issued');
		$this->assertNotSame('', $rEnd['id']);
		$this->assertFileDoesNotExist($this->rDir . 'sess_' . self::UNKNOWN);
		$this->assertTrue($rEnd['cookie']['httponly'], 'scripts can read the session cookie');
		$this->assertSame('Strict', $rEnd['cookie']['samesite']);
		$this->assertArrayNotHasKey('hash', $rEnd['session']);
	}

	public function testTheSessionCheckStillAnswersForASignedInAdministrator(): void {
		$this->assertSame('{"result":false}', $this->request(self::HEARTBEAT, self::UNKNOWN)['body']);

		$rEnd = $this->request(self::HEARTBEAT, self::KNOWN, ['hash' => 4, 'last_activity' => time() - 600]);
		$this->assertSame('{"result":true}', $rEnd['body']);
		$this->assertSame(self::KNOWN, $rEnd['id']);

		// The check counts as activity: the next request finds the hour restarted.
		$rEnd = $this->request(self::MANAGER, self::KNOWN);
		$this->assertSame(4, $rEnd['session']['hash'] ?? null);
		$this->assertGreaterThan(time() - 60, $rEnd['session']['last_activity']);
	}

	public function testTheSessionManagerCarriesASignedInSessionOn(): void {
		$rEnd = $this->request(self::MANAGER, self::KNOWN, ['hash' => 4, 'last_activity' => time() - 600]);

		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertSame(4, $rEnd['session']['hash'] ?? null);
	}

	/** An hour without a request ends the login; the session itself stays the visitor's. */
	public function testAnIdleLoginStillEndsAfterAnHour(): void {
		$rEnd = $this->request(self::HEARTBEAT, self::KNOWN, ['hash' => 4, 'last_activity' => time() - 3700]);
		$this->assertSame('{"result":false}', $rEnd['body']);
		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertArrayNotHasKey('hash', $rEnd['session']);

		$rEnd = $this->request(self::MANAGER, self::KNOWN, ['hash' => 4, 'last_activity' => time() - 3700]);
		$this->assertSame(self::KNOWN, $rEnd['id']);
		$this->assertArrayNotHasKey('hash', $rEnd['session']);
	}

	/** The session check has no start of its own: the rules are written once, in the session stage. */
	public function testTheSessionCheckStartsItsSessionThroughTheSessionStage(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/SessionController.php');

		$this->assertSame(0, substr_count($rSource, 'session_start('));
		$this->assertGreaterThan(0, substr_count($rSource, 'SessionStage::startSession();'));
	}
}
