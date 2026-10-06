<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A reseller that switches a line off or on, or a device's line, sends the
 * line signal every other writer of a line sends (LineService::updateLineSignal,
 * as the administrator's same switch does): a line switched off loses its live
 * sessions and the line cache follows either way.
 *
 * Both entry points are held to it: the reseller REST API and the panel's
 * line, mag and enigma actions. A panel action ends the request itself, so it
 * runs in a child PHP over this test's schema, on the production database class.
 */
final class AuditResellerRestLineSwitchSignalTest extends TestCase {
	private const RESELLER = 5;

	private const SETTINGS = ['enable_cache' => 1, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1];

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
define('SERVER_ID', 1);

// The production database class, which notes each read of a line's live sessions.
$db = new class extends \XcVm\Core\Database\DatabaseHandler {
	public int $rLookups = 0;

	public function query(string $query, mixed $buffered = false) {
		$this->rLookups += (int) str_contains($query, 'FROM `lines_live` WHERE `user_id`');
		return parent::query(...func_get_args());
	}
};
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
\XcVm\Core\Http\RequestManager::set($rIn['request']);
\XcVm\Core\Config\SettingsManager::set($rIn['settings']);
$rServers = [1 => ['is_main' => 1, 'server_type' => 0]];
$rSettings = ['redis_handler' => 0];

// What the reseller bootstrap leaves the action.
$rUserInfo = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['reseller']);
$rPermissions = $rIn['permissions'];
$rUserInfo['reports'] = array_merge([$rUserInfo['id']], $rPermissions['all_reports']);

// After the action's answer, on a line of its own: the session reads it made.
register_shutdown_function(static function () use ($db): void {
	echo "\n" . $db->rLookups;
});

\XcVm\Infrastructure\ResellerApiDispatcher::dispatch($rIn['action'], $rUserInfo, $rPermissions);
PHP;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	private string $rChild;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['users', 'users_logs', 'lines', 'lines_live', 'mag_devices', 'enigma2_devices', 'activation_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`) VALUES (5, 'reseller', 2, 100, 0)");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0]];
		$GLOBALS['rSettings'] = ['redis_handler' => 0];
		SettingsManager::set(self::SETTINGS);

		$this->rLog = new QueryLogDb($this->rDb);
		$GLOBALS['db'] = $this->rLog;
		DatabaseFactory::set($this->rLog);
		ResellerAPIWrapper::$db = $this->rLog;
		foreach ([LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rLog);
		}
		// What the reseller's session leaves the request.
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(self::RESELLER);
		$GLOBALS['rPermissions'] = ['create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => []];

		$this->rChild = sys_get_temp_dir() . '/xcvm-line-switch-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($this->rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		@unlink($this->rChild);
		foreach ([LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		ResellerAPIWrapper::$db = null;
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/**
	 * A line of the reseller with a month left, on or off; with $rTable a device's line.
	 *
	 * @return array{0: int, 1: int} the id the switch takes (the line's, or the device's) and the line's id
	 */
	private function target(string $rTable, int $rEnabled): array {
		$this->rDb->query('INSERT INTO `lines` (`member_id`, `username`, `password`, `exp_date`, `enabled`, `is_mag`, `is_e2`, `bouquet`, `allowed_outputs`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', self::RESELLER, 'line_' . bin2hex(random_bytes(4)), 'secret', time() + 30 * 86400, $rEnabled, (int) ($rTable === 'mag_devices'), (int) ($rTable === 'enigma2_devices'), '[]', '[1]');
		$rLine = (int) $this->rDb->last_insert_id();
		if ($rTable === 'lines') {
			return [$rLine, $rLine];
		}
		$this->rDb->query('INSERT INTO `' . $rTable . '` (`user_id`, `mac`) VALUES (?, ?)', $rLine, '00:1A:79:00:00:01');
		return [(int) $this->rDb->last_insert_id(), $rLine];
	}

	/** @return list<array<string, mixed>> the cache jobs queued for this server */
	private function signalled(): array {
		$this->rDb->query('SELECT `custom_data` FROM `signals` WHERE `server_id` = 1 AND `cache` = 1 ORDER BY `signal_id`');
		return array_map(static fn(string $rJob): array => json_decode($rJob, true), $this->rDb->get_column());
	}

	/**
	 * A panel action of the reseller, in a child PHP.
	 *
	 * @param array<string, string> $rRequest
	 * @return array{0: mixed, 1: int} its JSON answer, and the reads of a line's live sessions it made
	 */
	private function panel(string $rAction, array $rRequest): array {
		$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => $GLOBALS['rPermissions'], 'settings' => self::SETTINGS, 'action' => $rAction, 'request' => $rRequest];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rOut);
		[$rAnswer, $rLookups] = explode("\n", $rOut) + [1 => ''];
		return [json_decode($rAnswer, true), (int) $rLookups];
	}

	/** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> the REST method, the panel action and its id field, the table, and whether the line ends up on */
	public static function switches(): array {
		return [
			'line off' => ['disableLine', 'line', 'user_id', 'lines', 0], 'line on' => ['enableLine', 'line', 'user_id', 'lines', 1],
			'mag off' => ['disableMAG', 'mag', 'mag_id', 'mag_devices', 0], 'mag on' => ['enableMAG', 'mag', 'mag_id', 'mag_devices', 1],
			'enigma off' => ['disableEnigma', 'enigma', 'e2_id', 'enigma2_devices', 0], 'enigma on' => ['enableEnigma', 'enigma', 'e2_id', 'enigma2_devices', 1],
		];
	}

	#[DataProvider('switches')]
	public function testTheRestApiSignalsTheLineItSwitched(string $rMethod, string $rAction, string $rField, string $rTable, int $rEnabled): void {
		[$rID, $rLine] = $this->target($rTable, 1 - $rEnabled);
		$this->rLog->rQueries = [];

		$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::$rMethod((string) $rID));

		$this->assertSame($rEnabled, (int) UserRepository::getLineById($rLine)['enabled']);
		$this->assertSame([['type' => 'update_line', 'id' => $rLine]], $this->signalled(), 'the line cache is told');
		$rLookups = array_filter($this->rLog->rQueries, static fn(string $rQuery): bool => str_contains($rQuery, 'FROM `lines_live` WHERE `user_id`'));
		$this->assertCount(1 - $rEnabled, $rLookups, 'the sessions of a line switched off are read to be closed, of one switched on none');
	}

	#[DataProvider('switches')]
	public function testThePanelSignalsTheLineItSwitched(string $rMethod, string $rAction, string $rField, string $rTable, int $rEnabled): void {
		[$rID, $rLine] = $this->target($rTable, 1 - $rEnabled);

		[$rAnswer, $rLookups] = $this->panel($rAction, [$rField => (string) $rID, 'sub' => $rEnabled ? 'enable' : 'disable']);

		$this->assertSame(['result' => true], $rAnswer);
		$this->assertSame($rEnabled, (int) UserRepository::getLineById($rLine)['enabled']);
		$this->assertSame([['type' => 'update_line', 'id' => $rLine]], $this->signalled(), 'the line cache is told');
		$this->assertSame(1 - $rEnabled, $rLookups, 'the sessions of a line switched off are read to be closed, of one switched on none');
	}
}
