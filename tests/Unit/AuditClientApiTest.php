<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Api\Enigma2ApiController;
use XcVm\Public\Controllers\Api\PlayerApiController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The client APIs an app signs in to with a line's own credentials:
 * player_api and enigma2.
 *
 * A sign-in the player API refuses counts against the client's address in the
 * flood guard, as on the playlist, EPG and Enigma2 APIs, and one it accepts
 * does not. The Enigma2 API serves a line that is active, and of the catalogue
 * what the line's bouquets include.
 *
 * A request ends in exit(), so each one runs in a child PHP: the controller as
 * Public/index.php runs it, over the test's schema and a throwaway set of
 * cache directories.
 */
final class AuditClientApiTest extends TestCase {
	private const IP = '203.0.113.9';

	/** With this flood limit, four refused requests in a row (each within a minute of the one before) block the address. */
	private const SETTINGS = [
		'flood_limit' => 3, 'flood_seconds' => 60, 'flood_ips_exclude' => '', 'bruteforce_username_attempts' => 10, 'bruteforce_mac_attempts' => 5,
		'bruteforce_frequency' => 300, 'auth_flood_limit' => 0, 'enable_cache' => 0, 'redis_handler' => 0, 'case_sensitive_line' => 1,
		'county_override_1st' => 0, 'show_isps' => 0, 'force_epg_timezone' => 0, 'default_timezone' => 'UTC', 'disable_player_api' => 0,
		'legacy_panel_api' => 0, 'disable_enigma2' => 0, 'keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'message_of_day' => '',
		'server_name' => 'Panel', 'vod_sort_newest' => 0, 'channel_number_type' => 'bouquet', 'live_streaming_pass' => 'client-api-test-pass',
		'secure_stream_tokens' => 0, 'debug_show_errors' => 1,
	];

	private const CHILD = <<<'PHP'
<?php
// xcvm_core's part in a connection: the test's schema.
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

// What the endpoint's bootstrap leaves the controller.
define('SERVER_ID', 1);
defined('XC_VM_VERSION') || define('XC_VM_VERSION', 'test');
foreach (['CACHE_TMP_PATH' => 'cache', 'FLOOD_TMP_PATH' => 'flood', 'LINES_TMP_PATH' => 'lines', 'STREAMS_TMP_PATH' => 'streams', 'EPG_PATH' => 'epg'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
$_SERVER = $rIn['server'] + ['REMOTE_ADDR' => $rIn['ip'], 'HTTP_HOST' => 'panel.test', 'REQUEST_URI' => $rIn['uri'], 'SERVER_PROTOCOL' => 'HTTP/1.1'] + $_SERVER;
$rSettings = $rIn['settings'];
$rServers = [1 => ['server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443, 'rtmp_port' => 8880, 'server_type' => 0, 'is_main' => 1]];
$rCached = false;
$rRequest = $rIn['request'];
\XcVm\Core\Config\SettingsManager::set($rSettings);
\XcVm\Core\Http\RequestManager::set($rRequest);
\XcVm\Core\Cluster\NodeFlows::usePath($rIn['dir'] . 'flows.json'); // none: this server is no cluster node
\XcVm\Infrastructure\Database\DatabaseFactory::connect();

$rController = new $rIn['controller']();
register_shutdown_function([$rController, 'shutdown']);
$rController->index();
PHP;

	private TestDb $rDb;

	private string $rDir;

	/** @var array<string, mixed> the settings a request runs under */
	private array $rSettings = self::SETTINGS;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'mag_devices', 'activation_codes', 'output_formats', 'bouquets', 'blocked_ips', 'cluster_changes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?)', 'Basic', '[]', '[]', '[]', '[10]');
		$this->line('viewer');

		$this->rDir = sys_get_temp_dir() . '/xcvm-client-api-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['cache', 'flood', 'lines', 'streams', 'epg'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A line with the password `secret` and the bouquet 1. */
	private function line(string $rUsername, array $rColumns = []): void {
		$rColumns += ['username' => $rUsername, 'password' => 'secret', 'bouquet' => '[1]', 'allowed_outputs' => '[1,2]', 'allowed_ips' => '[]', 'allowed_ua' => '[]', 'access_token' => md5($rUsername)];
		$this->rDb->query('INSERT INTO `lines` (`' . implode('`, `', array_keys($rColumns)) . '`) VALUES (' . implode(', ', array_fill(0, count($rColumns), '?')) . ')', ...array_values($rColumns));
	}

	/**
	 * One request to a client API from the address IP: what it answered.
	 *
	 * @param class-string $rController
	 * @param array<string, mixed> $rRequest
	 */
	private function request(string $rController, array $rRequest, string $rUri = '/player_api.php', array $rServer = []): string {
		$rIn = ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'ip' => self::IP, 'uri' => $rUri, 'settings' => $this->rSettings, 'request' => $rRequest, 'controller' => $rController, 'server' => $rServer];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		return $rOut;
	}

	/** How many requests in a row the flood guard has counted against the address: null before the first. */
	private function counted(): ?int {
		$rFile = $this->rDir . 'flood/' . self::IP;
		return is_file($rFile) ? json_decode((string) file_get_contents($rFile), true)['requests'] : null;
	}

	/** @return list<string> the addresses blocked so far, each with its reason */
	private function blocked(): array {
		$this->rDb->query('SELECT CONCAT(`ip`, \' \', `notes`) FROM `blocked_ips`');
		return $this->rDb->get_column();
	}

	// ── player_api: a refused sign-in ───────────────────────────────

	public function testAWrongPasswordCountsAgainstTheAddress(): void {
		foreach ([0, 1, 2] as $rCount) {
			$rAnswer = json_decode($this->request(PlayerApiController::class, ['username' => 'viewer', 'password' => 'wrong' . $rCount]), true);
			$this->assertSame(['user_info' => ['auth' => 0, 'message' => 'Username or password is invalid.']], $rAnswer);
			$this->assertSame($rCount, $this->counted(), 'refused sign-in ' . ($rCount + 1) . ' of the same line');
		}
		$this->assertSame([], $this->blocked());
		$this->assertFileDoesNotExist($this->rDir . 'flood/block_' . self::IP);

		$this->request(PlayerApiController::class, ['username' => 'viewer', 'password' => 'wrong3']);
		$this->assertSame([self::IP . ' FLOOD ATTACK'], $this->blocked(), 'the address past the flood limit is blocked');
		$this->assertFileExists($this->rDir . 'flood/block_' . self::IP);
	}

	/** A token is a line's access token or an activation code: neither here. */
	public function testAnUnknownTokenCountsAgainstTheAddress(): void {
		foreach ([0, 1, 2] as $rCount) {
			$rAnswer = json_decode($this->request(PlayerApiController::class, ['token' => '10000' . $rCount]), true);
			$this->assertSame(0, $rAnswer['user_info']['auth'] ?? null);
			$this->assertSame($rCount, $this->counted(), 'refused token ' . ($rCount + 1));
		}

		$this->request(PlayerApiController::class, ['token' => str_repeat('a', 32)]);
		$this->assertSame([self::IP . ' FLOOD ATTACK'], $this->blocked());
	}

	/**
	 * A token nothing answers to is a guess, as a username is: an address may
	 * try fewer than `bruteforce_username_attempts` different ones in
	 * `bruteforce_frequency` seconds. The same one again is the same guess.
	 */
	public function testDifferentUnknownTokensCountAsDifferentUsernamesDo(): void {
		$this->rSettings['flood_limit'] = 40;

		for ($rTimes = 0; $rTimes < 12; $rTimes++) {
			$this->request(PlayerApiController::class, ['token' => 'GUESS0']);
		}
		foreach (range(1, 8) as $rGuess) {
			$this->request(PlayerApiController::class, ['token' => 'GUESS' . $rGuess]);
		}
		$this->assertSame([], $this->blocked(), 'nine different tokens');

		$this->request(PlayerApiController::class, ['token' => 'GUESS9']);
		$this->assertSame([self::IP . ' BRUTEFORCE USER ATTACK'], $this->blocked(), 'the tenth different token');
	}

	/**
	 * An activation code signs its line in. One that has run out or been
	 * suspended is the subscriber's own: it gets the answer such a line gets,
	 * and like it is not counted against the address.
	 */
	public function testACodeThatHasRunOutOrBeenSuspendedIsAnsweredAsSuchALineIs(): void {
		$this->rDb->exec(InstallSchema::table('users_packages'));
		$this->line('codeline', ['id' => 7, 'exp_date' => time() - 60]);
		$this->rDb->query(
			'INSERT INTO `activation_codes` (`activation_code`, `subscriber_id`, `status`, `package_id`, `mac`, `activated_at`) VALUES (?, 7, 2, 1, NULL, ?), (?, 7, 0, 1, NULL, NULL), (?, 1, 2, 1, ?, ?)',
			'RANOUT', time() - 3600, 'SUSPENDED', 'LOCKED', '00:1A:79:00:00:01', time() - 3600
		);

		foreach (['RANOUT' => ['Expired', 'Account has expired.'], 'SUSPENDED' => ['Disabled', 'Account has been disabled.']] as $rCode => [$rStatus, $rMessage]) {
			foreach ([['token' => $rCode], ['username' => $rCode, 'password' => 'any']] as $rRequest) {
				for ($rTimes = 0; $rTimes < 2; $rTimes++) {
					$rAnswer = json_decode($this->request(PlayerApiController::class, $rRequest), true);
					$this->assertSame(['user_info' => ['auth' => 0, 'status' => $rStatus, 'message' => $rMessage]], $rAnswer, json_encode($rRequest));
				}
			}
		}
		$this->assertNull($this->counted());
		$this->assertFileDoesNotExist($this->rDir . 'flood/' . self::IP . '_user');
		$this->assertSame([], $this->blocked());

		// A code locked to another device does not sign this one in: that is a refused sign-in.
		$rAnswer = json_decode($this->request(PlayerApiController::class, ['token' => 'LOCKED']), true);
		$this->assertSame(['user_info' => ['auth' => 0, 'message' => 'Username or password is invalid.']], $rAnswer);
		$this->assertSame(0, $this->counted());
	}

	/** An app that signs in again and again with the line's own credentials is never counted. */
	public function testAnAcceptedSignInIsNotCounted(): void {
		foreach ([['username' => 'viewer', 'password' => 'secret'], ['token' => md5('viewer')]] as $rRequest) {
			for ($rTimes = 0; $rTimes < 3; $rTimes++) {
				$rAnswer = json_decode($this->request(PlayerApiController::class, $rRequest), true);
				$this->assertSame(1, $rAnswer['user_info']['auth'] ?? null, json_encode($rRequest));
			}
		}
		$this->assertNull($this->counted());
		$this->assertSame([], $this->blocked());
	}

	// ── enigma2: what a line is served ──────────────────────────────

	/**
	 * Two categories of each kind and two series: the first of each is what
	 * bouquet 1, and so every line here, includes.
	 */
	private function catalogue(): void {
		foreach (['streams', 'streams_categories', 'streams_series', 'streams_episodes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`, `cat_order`) VALUES (1, 'live', 'Own Live', 1), (2, 'live', 'Other Live', 2), (3, 'movie', 'Own Movies', 3), (4, 'movie', 'Other Movies', 4), (5, 'series', 'Own Series', 5), (6, 'series', 'Other Series', 6)");
		$this->rDb->exec("INSERT INTO `streams_series` (`id`, `title`, `category_id`, `cover`) VALUES (10, 'Own Show', '[5]', 'http://images.test/own.jpg'), (20, 'Other Show', '[6]', 'http://images.test/other.jpg')");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `target_container`) VALUES (100, 5, 'Own Show S01E01', 'mp4'), (200, 5, 'Other Show S01E01', 'mp4')");
		$this->rDb->exec('INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 10, 100), (1, 1, 20, 200)');
		// The heavy cache pass's map of the categories each bouquet reaches.
		file_put_contents($this->rDir . 'cache/category_map', igbinary_serialize([1 => [1, 3, 5]]));
	}

	/** One Enigma2 request of the line $rUsername. */
	private function enigma2(string $rUsername, array $rRequest = []): string {
		return $this->request(Enigma2ApiController::class, ['username' => $rUsername, 'password' => 'secret'] + $rRequest, '/enigma2.php');
	}

	/** @return list<string> the titles of an Enigma2 list's entries */
	private function titles(string $rXml): array {
		$rList = @simplexml_load_string($rXml);
		$this->assertNotFalse($rList, $rXml);
		$rTitles = [];
		foreach ($rList->channel as $rEntry) {
			$rTitles[] = base64_decode((string) $rEntry->title);
		}
		return $rTitles;
	}

	/**
	 * The links of an Enigma2 list are the request's own scheme, and carry
	 * the line's credentials as a query can: SERVER_PROTOCOL ("HTTP/2.0") was
	 * read for the scheme, so every link was http://, and a username or
	 * password with `&`, `#` or a space was written as it is.
	 */
	public function testEnigma2LinksKeepTheSchemeAndTheCredentials(): void {
		$this->catalogue();
		$this->line('a&b', ['password' => 'p#1 x+y']);

		$rLinks = static function (string $rXml): array {
			preg_match_all('~<playlist_url><!\[CDATA\[(.*?)\]\]></playlist_url>~s', $rXml, $rFound);
			return $rFound[1];
		};
		$rPlain = $rLinks($this->request(Enigma2ApiController::class, ['username' => 'a&b', 'password' => 'p#1 x+y'], '/enigma2.php'));
		$rSecure = $rLinks($this->request(Enigma2ApiController::class, ['username' => 'a&b', 'password' => 'p#1 x+y'], '/enigma2.php', ['HTTPS' => 'on', 'SERVER_PROTOCOL' => 'HTTP/2.0']));

		$this->assertNotSame([], $rPlain);
		foreach ($rPlain as $rLink) {
			$this->assertStringStartsWith('http://panel.test/enigma2?', $rLink);
		}
		foreach ($rSecure as $rLink) {
			$this->assertStringStartsWith('https://panel.test/enigma2?', $rLink);
			parse_str((string) parse_url($rLink, PHP_URL_QUERY), $rQuery);
			$this->assertSame(['a&b', 'p#1 x+y'], [$rQuery['username'] ?? null, $rQuery['password'] ?? null], $rLink);
		}
	}

	public function testEnigma2ServesOnlyALineThatIsActive(): void {
		$this->catalogue();
		$this->line('running', ['exp_date' => time() + 3600]);
		$this->line('expired', ['exp_date' => time() - 60]);
		$this->line('banned', ['admin_enabled' => 0]);
		$this->line('disabled', ['enabled' => 0]);

		foreach (['viewer', 'running'] as $rUsername) {
			$this->assertSame(['TV Series'], $this->titles($this->enigma2($rUsername)), $rUsername);
		}
		foreach (['expired' => 'EXPIRED', 'banned' => 'BANNED', 'disabled' => 'DISABLED'] as $rUsername => $rError) {
			$this->assertStringContainsString('<h2>' . $rError . '</h2>', $this->enigma2($rUsername), $rUsername);
		}
		// Its credentials were right: the address is not counted.
		$this->assertNull($this->counted());
	}

	public function testEnigma2ListsTheSeasonsAndEpisodesOfASeriesInTheLinesBouquets(): void {
		$this->catalogue();

		$this->assertSame(['Season 1'], $this->titles($this->enigma2('viewer', ['type' => 'get_seasons', 'series_id' => '10'])));
		$rEpisodes = $this->enigma2('viewer', ['type' => 'get_series_streams', 'series_id' => '10', 'season' => '1']);
		$this->assertSame(['Episode 01'], $this->titles($rEpisodes));
		$this->assertStringContainsString('TV Series [ Own Show Season 1 ]', $rEpisodes);

		// Series 20 is in no bouquet of the line.
		$this->assertSame('', $this->enigma2('viewer', ['type' => 'get_seasons', 'series_id' => '20']));
		$this->assertSame('', $this->enigma2('viewer', ['type' => 'get_series_streams', 'series_id' => '20', 'season' => '1']));
		// As before, a request that names no series or no season is answered with nothing.
		$this->assertSame('', $this->enigma2('viewer', ['type' => 'get_seasons']));
		$this->assertSame('', $this->enigma2('viewer', ['type' => 'get_series_streams', 'series_id' => '10']));
	}

	public function testEnigma2ListsTheCategoriesTheLinesBouquetsReach(): void {
		$this->catalogue();

		$this->assertSame(['All', 'Own Live'], $this->titles($this->enigma2('viewer', ['type' => 'get_live_categories'])));
		$this->assertSame(['All', 'Own Movies'], $this->titles($this->enigma2('viewer', ['type' => 'get_vod_categories'])));
		$this->assertSame(['All', 'Own Series'], $this->titles($this->enigma2('viewer', ['type' => 'get_series_categories'])));

		// A series list is headed by its category's name when the category is one of the line's.
		$rOwn = $this->enigma2('viewer', ['type' => 'get_series', 'cat_id' => '5']);
		$this->assertStringContainsString('TV Series [ Own Series ]', $rOwn);
		$this->assertSame(['Own Show'], $this->titles($rOwn));
		$this->assertStringNotContainsString('Other Series', $this->enigma2('viewer', ['type' => 'get_series', 'cat_id' => '6']));
	}
}
