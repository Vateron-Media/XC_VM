<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The web player keeps its sign-in in a session cookie of its own, which the
 * browser also sends on a link from another site (SameSite=Lax). The admin and
 * the reseller panel keep theirs, which it sends on no such link
 * (SameSite=Strict). Both are kept from scripts, and both are taken only under
 * an id this server issued.
 *
 * So a panel sign-in and a player sign-in in one browser are two sessions: a
 * player request neither reads nor writes the panel's, a panel request neither
 * reads nor writes the player's, and signing out of the player ends the
 * player's only. A browser that holds nothing but the panel's cookie is not
 * signed in to the player, whatever that session holds.
 *
 * One test is one browser: every request runs in a child PHP that is sent the
 * cookies the browser holds, and the browser keeps the id the request's
 * session ended with, under the cookie name it was started with.
 */
final class AuditDecisionPlayerCookieTest extends TestCase {
	private const PANEL = 'PHPSESSID';
	private const PLAYER = 'PLAYERSESSID';

	private const KNOWN = 'issuedbythisserver00000000';
	private const UNKNOWN = 'neverissuedbythisserver000';

	/** The other server of the sign-in with an external account; its answer is the child's own. */
	private const SERVER = 'http://8.8.8.8:8080';

	private const CHILD = <<<'PHP'
<?php

namespace XcVm\Domain\External {
	/** The other server accepts the account: no request leaves this machine. */
	function curl_exec($rHandle) {
		return json_encode(['user_info' => ['auth' => 1, 'status' => 'Active', 'exp_date' => null], 'server_info' => []]);
	}

	function curl_getinfo($rHandle, $rOption = null) {
		return 200;
	}
}

namespace {
	require %BOOTSTRAP%;

	/** Answers the statements a sign-in issues, from fixed rows; never connects. */
	final class AuditPlayerCookieDb extends \XcVm\Core\Database\DatabaseHandler {
		private array $rows = [];

		private string $password;

		public function __construct() {
			$this->dbh = true;
			$this->password = \XcVm\Core\Auth\Authenticator::hashPassword('s3cret', 'fixedsalt', 1000);
		}

		public function query(string $query, mixed $buffered = false) {
			$this->rows = [];
			if (str_contains($query, 'FROM `lines`') && func_get_args() === [$query, 'viewer', 'secret']) {
				$this->rows = [[
					'id' => 7, 'username' => 'viewer', 'password' => 'secret', 'is_e2' => 0, 'is_mag' => 0, 'is_stalker' => 0,
					'exp_date' => null, 'admin_enabled' => 1, 'enabled' => 1, 'allowed_ips' => '[]', 'allowed_ua' => '[]',
					'bouquet' => '[]', 'allowed_outputs' => '[1]', 'forced_country' => '', 'max_connections' => 1,
					'is_restreamer' => 0, 'is_isplock' => 0, 'isp_desc' => '',
				]];
			} elseif (str_contains($query, 'FROM `users` WHERE `username` = ?')) {
				$this->rows = [['id' => 4, 'username' => 'boss', 'password' => $this->password, 'member_group_id' => 1, 'status' => 1]];
			} elseif (str_contains($query, 'FROM `access_codes` WHERE `code` = ?')) {
				$this->rows = [['id' => 1, 'code' => 'code', 'groups' => '[1]']];
			} elseif (str_contains($query, 'COUNT(*) AS `count` FROM `access_codes`')) {
				$this->rows = [['count' => 1]];
			} elseif (str_contains($query, 'FROM `users_groups` WHERE `group_id` = ?')) {
				$this->rows = [['group_id' => 1, 'is_admin' => 1, 'is_reseller' => 1, 'subresellers' => '']];
			}
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

	/**
	 * The session stage of the framework boot, which the sign-in pages run
	 * before anything else. It skips itself on the command line, so the state
	 * says what a web request's says.
	 */
	function bootStage(): void {
		$rState = new class(\XcVm\Core\Enum\BootContext::Admin, [], \XcVm\Core\Container\ServiceContainer::getInstance()) extends \XcVm\Core\Bootstrap\BootState {
			public function isCli(): bool {
				return false;
			}
		};
		(new \XcVm\Core\Bootstrap\Stage\SessionStage())->run($rState);
	}

	/** Call the private sign-in $rMethod of $rController. */
	function signIn(object $rController, string $rMethod, mixed ...$rArguments): void {
		$rSignIn = new ReflectionMethod($rController, $rMethod);
		$rSignIn->setAccessible(true);
		$rSignIn->invoke($rController, ...$rArguments);
	}

	$rIn = json_decode($argv[1], true);

	ini_set('session.save_path', $rIn['dir'] . 'sessions');
	ini_set('session.serialize_handler', 'php_serialize');
	$_COOKIE = $rIn['cookies'];
	$_SERVER['XC_CODE'] = 'code';
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	if ($rIn['scope'] !== '') {
		$_SERVER['XC_SCOPE'] = $rIn['scope'];
	}

	foreach (['CLIENT_INVALID', 'CLIENT_IS_E2', 'CLIENT_IS_MAG', 'CLIENT_IS_STALKER', 'CLIENT_EXPIRED', 'CLIENT_BANNED', 'CLIENT_DISABLED', 'CLIENT_DISALLOWED'] as $rValue => $rName) {
		define($rName, $rValue);
	}
	foreach (['STATUS_FAILURE' => 0, 'STATUS_SUCCESS' => 1, 'STATUS_DISABLED' => 5, 'STATUS_NOT_ADMIN' => 6, 'STATUS_INVALID_CAPTCHA' => 12, 'STATUS_INVALID_CODE' => 13, 'STATUS_NOT_RESELLER' => 35] as $rName => $rValue) {
		define($rName, $rValue);
	}
	define('CACHE_TMP_PATH', $rIn['dir'] . 'tmp/');
	define('CONS_TMP_PATH', $rIn['dir'] . 'tmp/');
	$GLOBALS['rSettings'] = ['enable_cache' => false, 'county_override_1st' => 0, 'show_isps' => 0, 'allow_countries' => ['ALL'], 'recaptcha_enable' => 0, 'save_login_logs' => 0];
	$GLOBALS['db'] = new AuditPlayerCookieDb();
	\XcVm\Infrastructure\Database\DatabaseFactory::set($GLOBALS['db']);

	// A page answers with a redirect or a body and ends in exit(): the session it ends with is the answer here.
	ob_start();
	register_shutdown_function(static function () {
		$rOut = ['name' => session_name(), 'id' => (string) session_id(), 'session' => $_SESSION ?? [], 'cookie' => session_get_cookie_params()];
		session_write_close();
		ob_end_clean();
		echo json_encode($rOut);
	});

	$rSecond = $rIn['scope'] === 'player_v2';

	switch ($rIn['step']) {
		case 'stage':
			bootStage();
			break;

		case 'page':
			// A page of the scope, up to the framework boot.
			$rBootstrap = \XcVm\Infrastructure\Bootstrap\ScopeBootstrapFactory::create($rIn['scope']);
			$rSession = new ReflectionMethod($rBootstrap, 'bootSession');
			$rSession->setAccessible(true);
			$rSession->invoke($rBootstrap);
			break;

		case 'panel sign-in':
			bootStage();
			if ($rIn['scope'] === 'reseller') {
				\XcVm\Core\Auth\Authenticator::resellerLogin(['username' => 'boss', 'password' => 's3cret']);
			} else {
				\XcVm\Core\Auth\Authenticator::login(['username' => 'boss', 'password' => 's3cret'], true);
			}
			break;

		case 'sign-in form':
			// The player's sign-in page ends the sign-in the browser came with.
			bootStage();
			\XcVm\Core\Auth\SessionManager::clearContext('player');
			break;

		case 'sign-in':
			bootStage();
			\XcVm\Core\Auth\SessionManager::clearContext('player');
			if ($rSecond) {
				signIn(new \XcVm\Public\Controllers\PlayerV2\PlayerLoginController(), 'processCredentialLogin', 'viewer', 'secret', [0 => 'Invalid username or password.']);
			} else {
				\XcVm\Core\Http\RequestManager::set(['username' => 'viewer', 'password' => 'secret']);
				signIn(new \XcVm\Public\Controllers\Player\PlayerLoginController(), 'processLogin');
			}
			break;

		case 'external sign-in':
			bootStage();
			\XcVm\Core\Auth\SessionManager::clearContext('player');
			signIn(new \XcVm\Public\Controllers\PlayerV2\PlayerLoginController(), 'processExternalXtreamLogin', $rIn['server'], 'viewer', 'secret');
			break;

		case 'sign-out':
			// The players sign out with a POST (a GET leads home).
			$_SERVER['REQUEST_METHOD'] = 'POST';
			if ($rSecond) {
				(new \XcVm\Public\Controllers\PlayerV2\PlayerLogoutController())->index();
			} else {
				(new \XcVm\Public\Controllers\Player\PlayerLogoutController())->index();
			}
			break;

		case 'external account after the session ended':
			// The external account's service starts a session when it finds none.
			bootStage();
			session_destroy();
			\XcVm\Domain\External\ExternalXtreamService::fromSession();
			break;
	}
}
PHP;

	private string $rDir;

	/** @var array<string, string> the cookies the browser holds: name => session id */
	private array $rCookies = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-player-cookie-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'sessions', 0700, true);
		mkdir($this->rDir . 'tmp', 0700, true);
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
		$this->rCookies = [];
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * The browser makes the request $rStep under an access code of the scope
	 * $rScope ('' for a script nginx runs itself) and keeps the session cookie.
	 *
	 * @return array{name: string, id: string, session: array<string, mixed>, cookie: array<string, mixed>}
	 */
	private function request(string $rScope, string $rStep): array {
		$rIn = ['dir' => $this->rDir, 'cookies' => $this->rCookies, 'scope' => $rScope, 'step' => $rStep, 'server' => self::SERVER];
		// The session ini the panel ships with (bin/php/lib/php.ini).
		$rPhp = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-d', 'session.use_strict_mode=0', '-d', 'session.name=' . self::PANEL, '-d', 'session.cookie_path=/'];
		$rProc = proc_open([...$rPhp, $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rStep . ': ' . $rOut);

		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, $rStep . ': ' . $rOut);
		if ($rResult['id'] !== '') {
			$this->rCookies[$rResult['name']] = $rResult['id'];
		}

		return $rResult;
	}

	/** What the session $rId holds on this server, null when it has none. */
	private function stored(string $rId): ?array {
		$rFile = $this->rDir . 'sessions/sess_' . $rId;
		// A request (another process) may have ended the session since the last look.
		clearstatcache(true, $rFile);
		if (!is_file($rFile)) {
			return null;
		}

		return unserialize((string) file_get_contents($rFile)) ?: [];
	}

	private function store(string $rId, array $rSession): void {
		file_put_contents($this->rDir . 'sessions/sess_' . $rId, serialize($rSession));
	}

	/** @return array<string, array{string}> */
	public static function players(): array {
		return ['the first player' => ['player'], 'the second player' => ['player_v2']];
	}

	#[DataProvider('players')]
	public function testAPlayerRequestStartsItsSessionUnderThePlayersCookie(string $rPlayer): void {
		$this->rCookies = [self::PLAYER => self::UNKNOWN];
		foreach (['page', 'stage'] as $rStep) {
			$rEnd = $this->request($rPlayer, $rStep);

			$this->assertSame(self::PLAYER, $rEnd['name'], $rStep);
			$this->assertSame('Lax', $rEnd['cookie']['samesite'], $rStep);
			$this->assertTrue($rEnd['cookie']['httponly'], 'scripts can read the session cookie');
			$this->assertNotSame('', $rEnd['id']);
		}
		$this->assertNull($this->stored(self::UNKNOWN), 'the player took a session id this server never issued');

		// The id a page issued on the way to the sign-in form is the one the viewer comes back with.
		$this->store(self::KNOWN, []);
		$this->rCookies = [self::PLAYER => self::KNOWN];
		$this->assertSame(self::KNOWN, $this->request($rPlayer, 'page')['id']);
	}

	/** @return array<string, array{string}> */
	public static function otherScopes(): array {
		return [
			'the admin panel' => ['admin'],
			'the reseller panel' => ['reseller'],
			'a script nginx runs itself' => [''],
			'the admin API' => ['includes/api/admin'],
			'the reseller API' => ['includes/api/reseller'],
			'the Ministra portal' => ['ministra'],
		];
	}

	/** Only the two player scopes have the player's cookie: a panel sign-in is never in it. */
	#[DataProvider('otherScopes')]
	public function testEveryOtherRequestKeepsThePanelsCookie(string $rScope): void {
		$this->rCookies = [self::PANEL => self::UNKNOWN, self::PLAYER => self::KNOWN];
		$this->store(self::KNOWN, ['phash' => 7]);

		$rEnd = $this->request($rScope, 'stage');

		$this->assertSame(self::PANEL, $rEnd['name']);
		$this->assertSame('Strict', $rEnd['cookie']['samesite']);
		$this->assertTrue($rEnd['cookie']['httponly'], 'scripts can read the session cookie');
		$this->assertNotSame('', $rEnd['id']);
		$this->assertNull($this->stored(self::UNKNOWN), 'the panel took a session id this server never issued');
		$this->assertSame([], $rEnd['session'], 'the panel read the session of the player\'s cookie');
	}

	/** @return array<string, array{string, string, string, bool}> */
	public static function signIns(): array {
		$rCases = [];
		foreach ([['admin', 'player', 'sign-in'], ['reseller', 'player_v2', 'sign-in'], ['admin', 'player_v2', 'external sign-in']] as [$rPanel, $rPlayer, $rSignIn]) {
			foreach ([true, false] as $rPanelFirst) {
				$rCases[$rPanel . ', ' . $rPlayer . ' ' . $rSignIn . ($rPanelFirst ? ', the panel first' : ', the player first')] = [$rPanel, $rPlayer, $rSignIn, $rPanelFirst];
			}
		}

		return $rCases;
	}

	#[DataProvider('signIns')]
	public function testAPanelSignInAndAPlayerSignInAreTwoSessions(string $rPanel, string $rPlayer, string $rSignIn, bool $rPanelFirst): void {
		$rKey = $rPanel === 'admin' ? 'hash' : 'reseller';

		if ($rPanelFirst) {
			$rPanelEnd = $this->request($rPanel, 'panel sign-in');
			$rPlayerEnd = $this->request($rPlayer, $rSignIn);
		} else {
			$rPlayerEnd = $this->request($rPlayer, $rSignIn);
			$rPanelEnd = $this->request($rPanel, 'panel sign-in');
		}
		$this->assertSame(4, $rPanelEnd['session'][$rKey] ?? null, 'the panel refused the sign-in');
		$this->assertNotEmpty($rPlayerEnd['session']['phash'] ?? null, 'the player refused the sign-in');
		if ($rSignIn === 'external sign-in') {
			$this->assertSame(self::SERVER, $rPlayerEnd['session']['external_xc']['server'] ?? null);
		}

		// The browser holds one cookie for each, and neither request changed the other's.
		$this->assertSame(self::PANEL, $rPanelEnd['name']);
		$this->assertSame(self::PLAYER, $rPlayerEnd['name'], 'the player signed in under the panel\'s cookie');
		$this->assertEqualsCanonicalizing([self::PANEL, self::PLAYER], array_keys($this->rCookies));
		$this->assertSame($rPanelEnd['id'], $this->rCookies[self::PANEL]);
		$this->assertSame($rPlayerEnd['id'], $this->rCookies[self::PLAYER]);

		// On the server they are two sessions, each with its own sign-in only.
		$this->assertCount(2, glob($this->rDir . 'sessions/sess_*'));
		$rPanelStored = $this->stored($rPanelEnd['id']);
		$rPlayerStored = $this->stored($rPlayerEnd['id']);
		$this->assertSame(4, $rPanelStored[$rKey] ?? null, 'the panel\'s sign-in is gone');
		$this->assertSame($rPlayerEnd['session']['phash'], $rPlayerStored['phash'] ?? null, 'the player\'s sign-in is gone');
		foreach (['phash', 'pverify', 'is_external_xc', 'external_xc'] as $rPlayerKey) {
			$this->assertArrayNotHasKey($rPlayerKey, $rPanelStored, 'the panel\'s session holds the player\'s sign-in');
		}
		foreach (['hash', 'reseller', 'verify', 'rverify'] as $rPanelKey) {
			$this->assertArrayNotHasKey($rPanelKey, $rPlayerStored, 'the player\'s session holds the panel\'s sign-in');
		}

		// Each finds its own sign-in on its next page.
		$rPage = $this->request($rPanel, 'page');
		$this->assertSame($rPanelEnd['id'], $rPage['id']);
		$this->assertSame(4, $rPage['session'][$rKey] ?? null);
		$this->assertArrayNotHasKey('phash', $rPage['session']);

		$rPage = $this->request($rPlayer, 'page');
		$this->assertSame($rPlayerEnd['id'], $rPage['id']);
		$this->assertSame($rPlayerEnd['session']['phash'], $rPage['session']['phash'] ?? null);
		$this->assertArrayNotHasKey($rKey, $rPage['session']);
	}

	/** The one session of before the player had its own cookie is not carried over: the viewer signs in once more. */
	#[DataProvider('players')]
	public function testABrowserWithOnlyThePanelsCookieIsNotSignedInToThePlayer(string $rPlayer): void {
		$rShared = ['hash' => 4, 'last_activity' => time() - 600, 'phash' => 7, 'pverify' => 'x'];
		$this->store(self::KNOWN, $rShared);
		$this->rCookies = [self::PANEL => self::KNOWN];

		$rPage = $this->request($rPlayer, 'page');

		$this->assertArrayNotHasKey('phash', $rPage['session'], 'the player took the sign-in of the panel\'s cookie');
		$this->assertNotSame(self::KNOWN, $rPage['id']);
		$this->assertSame($rShared, $this->stored(self::KNOWN), 'the player\'s page wrote to the panel\'s session');

		// The administrator stays signed in.
		$rPage = $this->request('admin', 'page');
		$this->assertSame(self::KNOWN, $rPage['id']);
		$this->assertSame(4, $rPage['session']['hash'] ?? null);
	}

	#[DataProvider('players')]
	public function testSigningOutOfThePlayerEndsOnlyThePlayersSession(string $rPlayer): void {
		$rPanelId = $this->request('admin', 'panel sign-in')['id'];
		$rPlayerId = $this->request($rPlayer, 'sign-in')['id'];
		$rPanelStored = $this->stored($rPanelId);
		$this->assertSame(7, $this->stored($rPlayerId)['phash'] ?? null, 'the player refused the sign-in');

		$this->request($rPlayer, 'sign-out');
		if ($rPlayer === 'player') {
			// The first player's sign-out sends the browser on to the sign-in form.
			$this->request($rPlayer, 'sign-in form');
		}

		$this->assertArrayNotHasKey('phash', $this->stored($rPlayerId) ?? [], 'the viewer is still signed in');
		$this->assertArrayNotHasKey('phash', $this->request($rPlayer, 'page')['session'], 'the viewer is still signed in');

		$this->assertSame($rPanelStored, $this->stored($rPanelId), 'the player\'s sign-out changed the panel\'s session');
		$rPage = $this->request('admin', 'page');
		$this->assertSame($rPanelId, $rPage['id']);
		$this->assertSame(4, $rPage['session']['hash'] ?? null);
	}

	/** A panel session that holds no sign-in yet (a visitor at the panel's sign-in form) is the panel's all the same. */
	#[DataProvider('players')]
	public function testThePlayersSignOutLeavesAPanelSessionWithoutASignInAlone(string $rPlayer): void {
		$this->store(self::KNOWN, []);
		$this->rCookies = [self::PANEL => self::KNOWN];
		$this->request($rPlayer, 'sign-in');

		$this->request($rPlayer, 'sign-out');

		$this->assertSame([], $this->stored(self::KNOWN), 'the player\'s sign-out ended the panel\'s session');
		$this->assertSame(self::KNOWN, $this->request('admin', 'page')['id']);
	}

	/**
	 * The cookie's name and flags, once the session stage has set them, hold
	 * for the rest of the request: a session started later in it, as the
	 * external account's service does when it finds none, is the player's too.
	 */
	public function testALaterStartInAPlayerRequestIsUnderThePlayersCookie(): void {
		$rPanelId = $this->request('admin', 'panel sign-in')['id'];
		$rPanelStored = $this->stored($rPanelId);
		$this->rCookies[self::PLAYER] = self::UNKNOWN;

		$rEnd = $this->request('player_v2', 'external account after the session ended');

		$this->assertSame(self::PLAYER, $rEnd['name']);
		$this->assertSame('Lax', $rEnd['cookie']['samesite']);
		$this->assertTrue($rEnd['cookie']['httponly'], 'scripts can read the session cookie');
		$this->assertNotSame('', $rEnd['id']);
		$this->assertNotSame($rPanelId, $rEnd['id']);
		$this->assertNull($this->stored(self::UNKNOWN), 'the player took a session id this server never issued');
		$this->assertSame($rPanelStored, $this->stored($rPanelId), 'the player\'s request changed the panel\'s session');
	}

	/** Which cookie a session has is decided once, in the session stage: no page of a player starts one itself. */
	public function testNoPlayerControllerStartsASessionOfItsOwn(): void {
		foreach (['Player/PlayerLoginController', 'Player/PlayerLogoutController', 'PlayerV2/PlayerLoginController', 'PlayerV2/PlayerLogoutController'] as $rController) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/' . $rController . '.php');

			$this->assertSame(0, substr_count($rSource, 'session_start('), $rController . ' starts a session of its own');
		}
	}
}
