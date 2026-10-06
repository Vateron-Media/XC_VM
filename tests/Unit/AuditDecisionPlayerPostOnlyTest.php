<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The web players' actions that change something run only on a POST: signing
 * out (both players), refreshing the line's caches (the second player) and
 * reordering the line's bouquets from the profile (the first player). The
 * player's cookie is SameSite=Lax, so the browser sends it on a link from
 * another site, which is a GET: a GET of these changes nothing. A GET of the
 * profile still shows the page, and a GET of the sign-out leads home.
 *
 * Each request runs in a child PHP through the scope's route file and the
 * router, as the front controller dispatches it.
 */
final class AuditDecisionPlayerPostOnlyTest extends TestCase {
	private const KNOWN = 'issuedbythisserver00000000';

	private const CHILD = <<<'PHP'
<?php

namespace XcVm\Public\Controllers\Player {
	/** The answer's headers, kept for the test: the command line sends none. */
	function header(string $rHeader, bool $rReplace = true, int $rCode = 0): void {
		$GLOBALS['rSent'][] = $rHeader;
	}
}

namespace XcVm\Public\Controllers\PlayerV2 {
	function header(string $rHeader, bool $rReplace = true, int $rCode = 0): void {
		$GLOBALS['rSent'][] = $rHeader;
	}
}

namespace {
	require %BOOTSTRAP%;
	error_reporting(E_ERROR | E_PARSE);

	$rIn = json_decode($argv[1], true);
	$GLOBALS['rSent'] = [];
	$_SERVER['REQUEST_METHOD'] = $rIn['method'];
	$_SERVER['XC_SCOPE'] = $rIn['scope'];
	$_SERVER['XC_CODE'] = 'code';
	$rDir = $rIn['dir'];

	if ($rIn['page'] === 'logout') {
		ini_set('session.save_path', $rDir . 'sessions');
		ini_set('session.serialize_handler', 'php_serialize');
		$_COOKIE = ['PLAYERSESSID' => $rIn['known']];
	} else {
		$db = new TestDb();
		foreach (['lines', 'bouquets', 'mag_devices', 'enigma2_devices', 'users', 'lines_live', 'streams', 'streams_series', 'streams_episodes', 'streams_categories', 'output_formats', 'output_devices', 'lines_logs', 'servers'] as $rTable) {
			$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
		}
		$db->query('INSERT INTO `bouquets` (`id`, `bouquet_name`) VALUES (5, ?), (7, ?), (9, ?)', 'A', 'B', 'C');
		$db->query('INSERT INTO `lines` (`id`, `username`, `password`, `bouquet`, `enabled`, `admin_enabled`) VALUES (7, ?, ?, ?, 1, 1)', 'viewer', 'secret', '[5,7,9]');
		\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
		define('CACHE_TMP_PATH', $rDir . 'cache/');
		define('LINES_TMP_PATH', $rDir . 'lines/');
		define('SERVER_ID', 1);
		$rServers = [1 => ['is_main' => 1]];
		$rSettings = ['enable_cache' => 0, 'player_allow_bouquet' => 1];
		\XcVm\Core\Config\SettingsManager::set($rSettings);
		file_put_contents(LINES_TMP_PATH . 'line_i_7', 'line 7');
		file_put_contents(CACHE_TMP_PATH . 'bouquets', 'as it was');
		$rUserInfo = ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'bouquet' => [5, 7, 9], 'exp_date' => null];
		\XcVm\Core\Http\RequestManager::set($rIn['page'] === 'profile' ? ['bouquet_order' => '[9,5,7]'] : []);
	}

	// A page answers and ends in exit(): what it changed is the answer here.
	ob_start();
	register_shutdown_function(static function () use ($rIn) {
		ob_end_clean();
		$rOut = ['headers' => $GLOBALS['rSent']];
		if ($rIn['page'] === 'logout') {
			session_write_close();
		} else {
			$GLOBALS['db']->query('SELECT `bouquet` FROM `lines` WHERE `id` = 7');
			$rOut['bouquet'] = $GLOBALS['db']->get_row()['bouquet'] ?? null;
			$rOut['line_cache'] = file_exists(LINES_TMP_PATH . 'line_i_7');
			$rOut['bouquets_cache'] = @file_get_contents(CACHE_TMP_PATH . 'bouquets') === 'as it was';
		}
		echo json_encode($rOut);
	});

	$router = \XcVm\Core\Http\Router::getInstance();
	require MAIN_HOME . 'Public/routes/' . $rIn['scope'] . '.php';
	if (!$router->dispatch($rIn['page'], $rIn['method'])) {
		$GLOBALS['rSent'][] = '404';
	}
}
PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-player-post-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['sessions', 'cache', 'lines'] as $rSub) {
			mkdir($this->rDir . $rSub, 0700, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
		// The viewer is signed in to the player.
		file_put_contents($this->rDir . 'sessions/sess_' . self::KNOWN, serialize(['phash' => 7, 'pverify' => 'x']));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array<string, mixed> what the request $rMethod $rPage of $rScope sent and changed */
	private function request(string $rScope, string $rPage, string $rMethod): array {
		$rIn = ['dir' => $this->rDir, 'scope' => $rScope, 'page' => $rPage, 'method' => $rMethod, 'known' => self::KNOWN];
		$rProc = proc_open([PHP_BINARY, '-d', 'session.use_strict_mode=0', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);

		return $rResult;
	}

	/** What the viewer's session holds on the server, null when it has none. */
	private function session(): ?array {
		$rFile = $this->rDir . 'sessions/sess_' . self::KNOWN;
		clearstatcache(true, $rFile);

		return is_file($rFile) ? (unserialize((string) file_get_contents($rFile)) ?: []) : null;
	}

	/** @return array<string, array{string, string, string}> player, the home it leads to, the sign-in form it leads to */
	public static function players(): array {
		return [
			'the first player' => ['player', 'Location: index', 'Location: login'],
			'the second player' => ['player_v2', 'Location: /code/index', 'Location: /code/login?logged_out=1'],
		];
	}

	/** The sign-in form ends the sign-in it finds, so a GET of the sign-out must not lead there either. */
	#[DataProvider('players')]
	public function testAGetOfTheSignOutLeavesTheViewerSignedIn(string $rPlayer, string $rHome, string $rSignIn): void {
		$rEnd = $this->request($rPlayer, 'logout', 'GET');

		$this->assertSame(7, $this->session()['phash'] ?? null, 'a GET signed the viewer out');
		$this->assertSame([$rHome], $rEnd['headers'], 'a GET did not lead home');
	}

	#[DataProvider('players')]
	public function testAPostOfTheSignOutSignsTheViewerOut(string $rPlayer, string $rHome, string $rSignIn): void {
		$rEnd = $this->request($rPlayer, 'logout', 'POST');

		$this->assertSame([$rSignIn], $rEnd['headers']);
		if ($rPlayer === 'player_v2') {
			$this->assertArrayNotHasKey('phash', $this->session() ?? [], 'the viewer is still signed in');
		}
	}

	public function testAGetOfRefreshDropsNoCache(): void {
		$rEnd = $this->request('player_v2', 'refresh', 'GET');

		$this->assertTrue($rEnd['line_cache'], 'a GET dropped the line\'s cache');
		$this->assertTrue($rEnd['bouquets_cache'], 'a GET rebuilt the bouquets cache');
	}

	public function testAPostOfRefreshDropsTheLinesCaches(): void {
		$rEnd = $this->request('player_v2', 'refresh', 'POST');

		$this->assertFalse($rEnd['line_cache']);
		$this->assertFalse($rEnd['bouquets_cache']);
	}

	public function testAGetOfTheProfileReordersNothing(): void {
		$this->assertSame('[5,7,9]', $this->request('player', 'profile', 'GET')['bouquet'], 'a GET reordered the line\'s bouquets');
	}

	public function testAPostOfTheProfileReordersTheBouquets(): void {
		$this->assertSame('[9,5,7]', $this->request('player', 'profile', 'POST')['bouquet']);
	}

	/** The players' own pages send these actions as a POST. */
	public function testThePlayersPagesSendTheseActionsAsAPost(): void {
		$rFooter = (string) file_get_contents(MAIN_HOME . 'Public/Views/layouts/player/footer.php');
		$this->assertSame(1, preg_match('/function doLogout\(\) \{[^}]*<form method="POST" action="logout"><\/form>[^}]*\.submit\(\)/', $rFooter), 'the first player signs out with a GET');
		$this->assertStringContainsString('<form action="profile" class="profile__form" id="bouquet__form" method="POST">', (string) file_get_contents(MAIN_HOME . 'Public/Views/player/profile.php'));

		$rHeader = (string) file_get_contents(MAIN_HOME . 'Public/Views/layouts/player_v2/header.php');
		$this->assertTrue(str_contains($rHeader, '<form id="player-logout-form" method="post" action="<?= $baseUrl ?>logout" class="d-none"></form>'), 'the second player has no sign-out form');
		foreach (['Public/Views/layouts/player_v2/header.php', 'Public/Views/player_v2/profile.php'] as $rView) {
			preg_match_all('/<a [^\n]*\?>logout"[^\n]*/', (string) file_get_contents(MAIN_HOME . $rView), $rLinks);
			$this->assertNotEmpty($rLinks[0], $rView);
			foreach ($rLinks[0] as $rLink) {
				$this->assertStringContainsString("document.getElementById('player-logout-form').submit(); return false;", $rLink, $rView . ' signs out with a GET');
			}
		}

		$this->assertMatchesRegularExpression("/fetch\(refreshUrl, \{\s*method: 'POST'/", (string) file_get_contents(MAIN_HOME . 'Public/assets/player_v2/js/player-sync.js'));
		$this->assertMatchesRegularExpression("/fetch\(saveUrl, \{\s*method: 'POST'/", (string) file_get_contents(MAIN_HOME . 'Public/assets/player_v2/js/player-profile.js'));
	}
}
