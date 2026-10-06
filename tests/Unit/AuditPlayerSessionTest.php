<?php

use PHPUnit\Framework\TestCase;

/**
 * The web player's session follows the panel's rules: signing in moves it to
 * an id nobody has seen yet, and a player page starts it with the panel's
 * cookie flags and only under an id this server issued.
 *
 * Every case runs in a child PHP: both paths end in exit(), and PHP refuses to
 * touch a session once output has started.
 */
final class AuditPlayerSessionTest extends TestCase {
	private const KNOWN = 'issuedbythisserver00000000';
	private const UNKNOWN = 'neverissuedbythisserver000';

	/** Signs the line `viewer` in at the v1 login, from a session that already carries a panel login. */
	private const LOGIN = <<<'PHP'
		final class AuditPlayerLineDb extends \XcVm\Core\Database\DatabaseHandler {
			private array $rows = [];

			public function __construct() {
				$this->dbh = true;
			}

			public function query(string $query, mixed $buffered = false) {
				$this->rows = str_contains($query, 'FROM `lines`') && func_get_args() === [$query, 'viewer', 'secret'] ? [[
					'id' => 7, 'username' => 'viewer', 'password' => 'secret', 'is_e2' => 0, 'is_mag' => 0, 'is_stalker' => 0,
					'exp_date' => null, 'admin_enabled' => 1, 'enabled' => 1, 'allowed_ips' => '[]', 'allowed_ua' => '[]',
					'bouquet' => '[]', 'allowed_outputs' => '[1]', 'forced_country' => '', 'max_connections' => 1,
					'is_restreamer' => 0, 'is_isplock' => 0, 'isp_desc' => '',
				]] : [];
				return true;
			}

			public function num_rows() {
				return count($this->rows);
			}

			public function get_row() {
				return $this->rows[0] ?? [];
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rows;
			}
		}

		foreach (['CLIENT_INVALID', 'CLIENT_IS_E2', 'CLIENT_IS_MAG', 'CLIENT_IS_STALKER', 'CLIENT_EXPIRED', 'CLIENT_BANNED', 'CLIENT_DISABLED', 'CLIENT_DISALLOWED'] as $rValue => $rName) {
			define($rName, $rValue);
		}
		define('CACHE_TMP_PATH', $rDir);
		define('CONS_TMP_PATH', $rDir);
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$GLOBALS['rSettings'] = ['enable_cache' => false, 'county_override_1st' => 0, 'show_isps' => 0, 'allow_countries' => ['ALL']];
		$GLOBALS['db'] = new AuditPlayerLineDb();
		\XcVm\Infrastructure\Database\DatabaseFactory::set($GLOBALS['db']);
		\XcVm\Core\Http\RequestManager::set(['username' => 'viewer', 'password' => $rPassword]);

		session_id($rId);
		session_start();
		$_SESSION['hash'] = 4;

		$rLogin = new ReflectionMethod(\XcVm\Public\Controllers\Player\PlayerLoginController::class, 'processLogin');
		$rLogin->setAccessible(true);
		$rLogin->invoke(new \XcVm\Public\Controllers\Player\PlayerLoginController());
		PHP;

	/** Opens a player page with the session cookie $rId. */
	private const PAGE = <<<'PHP'
		$_COOKIE[session_name()] = $rId;
		(new \XcVm\Infrastructure\Bootstrap\PlayerScopeBootstrap())->boot();
		PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-player-session-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run $rCode with the variables $rVars and return the session it ends with.
	 *
	 * @return array{id: string, session: array<string, mixed>, cookie: array<string, mixed>, files: list<string>}
	 */
	private function child(string $rCode, array $rVars): array {
		$rVars['rDir'] = $this->rDir;
		$rSetup = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'ini_set("session.save_path", ' . var_export($this->rDir, true) . ');'
			. 'extract(' . var_export($rVars, true) . ');'
			. 'register_shutdown_function(static function () use ($rDir) {'
			. ' $rOut = ["id" => session_id(), "session" => $_SESSION ?? [], "cookie" => session_get_cookie_params()];'
			. ' session_write_close();'
			. ' echo json_encode($rOut + ["files" => array_map("basename", glob($rDir . "sess_*"))]);'
			. '});';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rSetup . $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rResult = json_decode((string) $rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);

		return $rResult;
	}

	public function testSigningInMovesTheSessionToAFreshId(): void {
		$rEnd = $this->child(self::LOGIN, ['rId' => self::KNOWN, 'rPassword' => 'secret']);

		$this->assertSame(7, $rEnd['session']['phash'] ?? null, 'the line did not sign in');
		$this->assertNotSame(self::KNOWN, $rEnd['id'], 'the session id from before the login carries the signed-in session');
		$this->assertSame(['sess_' . $rEnd['id']], $rEnd['files'], 'the old session is still on disk');
		$this->assertSame(4, $rEnd['session']['hash'] ?? null, 'a panel login in the same session was lost');
	}

	/** A refused login leaves the session where it was. */
	public function testARefusedLoginKeepsTheSession(): void {
		$rEnd = $this->child(self::LOGIN, ['rId' => self::KNOWN, 'rPassword' => 'wrong']);

		$this->assertArrayNotHasKey('phash', $rEnd['session']);
		$this->assertSame(self::KNOWN, $rEnd['id']);
	}

	public function testAPlayerPageStartsTheSessionWithThePanelsCookieFlags(): void {
		$rEnd = $this->child(self::PAGE, ['rId' => self::UNKNOWN]);

		$this->assertNotSame(self::UNKNOWN, $rEnd['id'], 'the page took a session id this server never issued');
		$this->assertNotSame('', $rEnd['id']);
		$this->assertTrue($rEnd['cookie']['httponly'], 'scripts can read the session cookie');
		$this->assertSame('Strict', $rEnd['cookie']['samesite']);
	}

	/** The id a player page issued on the way to the login form is the one the visitor comes back with. */
	public function testAPlayerPageKeepsASessionThisServerIssued(): void {
		touch($this->rDir . 'sess_' . self::KNOWN);

		$rEnd = $this->child(self::PAGE, ['rId' => self::KNOWN]);

		$this->assertSame(self::KNOWN, $rEnd['id']);
	}
}
