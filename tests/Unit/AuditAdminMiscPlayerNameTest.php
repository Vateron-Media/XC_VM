<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * The connection and activity tables show the player a viewer's device names
 * itself as: its user agent, up to the first parenthesis. The stream endpoints
 * store a user agent entity-encoded, and a page writes the column into its
 * cell as text (its helper, esc). So ./table sends the panel's pages the name
 * the device gave, decoded once, and a name with an ampersand, a quote or an
 * accented letter reads in the cell as the device sent it. A value that was
 * stored as it came, as a node may send one, reaches the page as the device
 * gave it too: it is the page that keeps either from being markup, so every
 * page that shows the column writes it through esc.
 *
 * The admin API's rows keep the value as it is stored. A table's search finds
 * a player by the name its row shows as well as by the text that is stored.
 *
 * The tables end the request themselves, so each runs in a child PHP over the
 * test's schema.
 */
final class AuditAdminMiscPlayerNameTest extends TestCase {
	/** Per viewer address: the user agent the device sent, and the player its row shows. */
	private const AGENTS = [
		'198.51.100.1' => ['Télé & "Co" it\'s/2.1 (Linux; Android 12)', 'Télé & "Co" it\'s/2.1'],
		'198.51.100.2' => ['<script>alert(1)</script>/1.0', '<script>alert'],
		'198.51.100.3' => ['VLC/3.0.20 LibVLC/3.0.20', 'VLC/3.0.20 LibVLC/3.0.20'],
	];

	/** A viewer whose user agent a node sent as it came. */
	private const RAW = ['198.51.100.9', '<img src=x onerror=alert(1)> & Co', '<img src=x onerror=alert'];

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

\XcVm\Core\Config\SettingsManager::set(['redis_handler' => 0]);
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
$rProxyServers = [];
// What DataTables sends with a page of a table.
\XcVm\Core\Http\RequestManager::set(['draw' => 1, 'order' => [['column' => '', 'dir' => 'asc']], 'search' => ['value' => $rIn['search']]]);
$rReturn = ['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];

if ($rIn['panel'] === 'admin') {
	$rUserInfo = ['id' => 1, 'member_group_id' => 1];
	$rPermissions = ['is_admin' => 1, 'advanced' => []];
	$rHandler = new ReflectionMethod(\XcVm\Public\Controllers\Admin\TableController::class, $rIn['handler']);
	$rHandler->setAccessible(true);
	$rHandler->invoke(new \XcVm\Public\Controllers\Admin\TableController(), $rReturn, 0, 1000, $rIn['api']);
} else {
	$rHandler = new ReflectionMethod(\XcVm\Infrastructure\ResellerTableRenderer::class, $rIn['handler']);
	$rHandler->setAccessible(true);
	$rArguments = [&$rReturn, false, ['id' => 5, 'reports' => [5]], ['reseller_client_connection_logs' => 1, 'can_view_vod' => 0], 0, 1000];
	$rHandler->invokeArgs(null, $rArguments);
}
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'streams', 'servers', 'mag_devices', 'enigma2_devices', 'lines_activity', 'lines_live'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `member_id`) VALUES (7, 'viewer', 'secret', 5)");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (3, 1, 'News')");
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`) VALUES (1, 'Main')");

		// A stream endpoint stores the user agent entity-encoded; a node may send one as it came.
		$rStored = array_map(static fn(array $rAgent): string => htmlentities($rAgent[0]), self::AGENTS) + [self::RAW[0] => self::RAW[1]];
		foreach ($rStored as $rIP => $rUserAgent) {
			$this->rDb->query('INSERT INTO `lines_activity` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `date_start`, `date_end`) VALUES (7, 3, 1, ?, ?, ?, 100, 160)', $rUserAgent, $rIP, 'ts');
			$this->rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `date_start`, `hls_end`, `uuid`) VALUES (7, 3, 1, ?, ?, ?, 100, 0, ?)', $rUserAgent, $rIP, 'ts', md5($rIP));
		}

		$this->rDir = sys_get_temp_dir() . '/xcvm-player-name-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * The player column of one table of ./table, as the panel's page or the admin API receives it,
	 * with what is typed in the table's search box.
	 *
	 * @return array<string, string> per viewer address
	 */
	private function players(string $rPanel, string $rHandler, bool $rIsAPI = false, string $rSearch = ''): array {
		$rIn = ['schema' => $this->rDb->schema(), 'panel' => $rPanel, 'handler' => $rHandler, 'api' => $rIsAPI, 'search' => $rSearch];
		$rProc = proc_open([...xcvm_test_child_php(), $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rRows = json_decode($rOut, true)['data'] ?? null;
		$this->assertIsArray($rRows, $rOut . $rErr);

		return array_column($rRows, 'player', 'user_ip');
	}

	/** @return array<string, array{0: string, 1: string}> the panel and the table's handler */
	public static function tables(): array {
		return [
			'the connection logs' => ['admin', 'handleLineActivity'],
			'the live connections' => ['admin', 'handleLiveConnections'],
			'a reseller\'s connection logs' => ['reseller', 'handleLineActivity'],
			'a reseller\'s live connections' => ['reseller', 'handleLiveConnections'],
		];
	}

	#[DataProvider('tables')]
	public function testThePlayerIsTheNameTheDeviceGave(string $rPanel, string $rHandler): void {
		$this->assertSame(array_map(static fn(array $rAgent): string => $rAgent[1], self::AGENTS) + [self::RAW[0] => self::RAW[2]], $this->players($rPanel, $rHandler));
	}

	/** The search is over the stored user agent: it matches the name as the row shows it too. */
	#[DataProvider('tables')]
	public function testAPlayerIsFoundByTheNameItsRowShows(string $rPanel, string $rHandler): void {
		$rFound = fn(string $rSearch): array => array_keys($this->players($rPanel, $rHandler, false, $rSearch));

		$this->assertSame(['198.51.100.1'], $rFound('Télé & "Co"'));
		$this->assertSame(['198.51.100.1'], $rFound("it's/2.1"));
		$this->assertSame(['198.51.100.1'], $rFound('T&eacute;l&eacute; &amp;'), 'the stored text still finds it');
		$this->assertSame(['198.51.100.3'], $rFound('LibVLC'));
		$this->assertSame([self::RAW[0]], $rFound('onerror=alert'), 'a value stored as it came');
		$this->assertSame([], $rFound('no such player'));
	}

	public function testTheAdminApiKeepsThePlayerAsStored(): void {
		$rPlayers = $this->players('admin', 'handleLineActivity', true);

		$this->assertSame('T&eacute;l&eacute; &amp; &quot;Co&quot; it&#039;s/2.1', $rPlayers['198.51.100.1']);
		$this->assertSame('&lt;script&gt;alert', $rPlayers['198.51.100.2']);
	}

	/** The name is sent as the device gave it, so every page that shows the column writes it as text. */
	public function testEveryPageWritesThePlayerAsText(): void {
		$rPages = [];
		$rAsMarkup = [];
		$rReadElsewhere = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(MAIN_HOME . 'Public/Views', FilesystemIterator::SKIP_DOTS)) as $rFile) {
			$rPage = substr((string) $rFile, strlen(MAIN_HOME . 'Public/Views/'), -4);
			$rSource = (string) file_get_contents((string) $rFile);
			// One definition: `{ data: 'player', ... }` in either quote, a render function's body being the only braces inside.
			preg_match_all('/\{\s*data:\s*[\'"]player[\'"]((?:[^{}]|\{[^{}]*\})*)\}/', $rSource, $rColumns);
			foreach ($rColumns[1] as $rRest) {
				$rPages[] = $rPage;
				if (!preg_match('/render:\s*esc\b/', $rRest)) {
					$rAsMarkup[] = $rPage;
				}
			}
			// Any other read of a row's player; window.player is the stream page's preview function.
			if (preg_match('/(?<!window)\.player\b|\[\s*[\'"]player[\'"]\s*\]/', $rSource)) {
				$rReadElsewhere[] = $rPage;
			}
		}
		sort($rPages);

		$this->assertSame([], $rAsMarkup);
		$this->assertSame([], $rReadElsewhere, 'the player is read in a column definition only');
		// The pages that show the column: one that is added, or that the scan stops seeing, is looked at.
		$this->assertSame(['admin/line_activity', 'admin/live_connections', 'admin/server_view', 'admin/stream_view', 'reseller/dashboard', 'reseller/line_activity', 'reseller/live_connections'], $rPages);
	}
}
