<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\FanoutSyncCommand;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\LbDatabaseAccessException;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Database\DatabaseUnavailableException;
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Streaming\Fanout\FanoutConfig;

/**
 * The MariaDB server the handles below connect to: it can be taken away
 * and given back. Only the connect is replaced (the real one goes through
 * the xcvm_core extension); the handles are the panel's own, eager and
 * lazy, with their own queries, pings and reconnects.
 */
final class FanoutSyncOutageServer {
	public bool $rUp = true;

	/** @var list<array{0: bool, 1: bool|null}> each connect's [$migrate, $graceful] */
	public array $rConnects = [];

	/** @var list<array{0: string, 1: list<mixed>}> each statement run, with its values */
	public array $rStatements = [];

	/** @var list<array<string, mixed>> the open daemon-served rows of lines_live */
	public array $rLive = [];

	/** @var array<string, mixed> the settings row */
	public array $rSettings = [];

	/** @var (callable(): void)|null what happens while a handle's next ping or statement is on its way */
	public $rMeanwhile = null;

	/** A connect, as Database::db_connect() answers it: a connection, false (graceful), or the end of the process. */
	public function connect(bool $rMigrate, ?bool $rGraceful): FanoutSyncOutageConnection|false {
		$this->rConnects[] = [$rMigrate, $rGraceful];
		if ($this->rUp) {
			return new FanoutSyncOutageConnection($this);
		}
		if (($rGraceful ?? $rMigrate) !== true) {
			throw new \LogicException('this connect ends the process while the database is unreachable');
		}
		return false;
	}

	/** @return list<array<string, mixed>> */
	public function answer(string $rQuery): array {
		if (str_starts_with($rQuery, 'SELECT * FROM `lines_live`')) {
			return $this->rLive;
		}
		if (str_starts_with($rQuery, 'SELECT * FROM `settings`')) {
			return [$this->rSettings];
		}
		if (str_starts_with($rQuery, 'SELECT `stream_id`, `bitrate`')) {
			return [['stream_id' => 7, 'bitrate' => 1000]];
		}
		return [];
	}

	/** @return list<array{0: string, 1: list<mixed>}> the statements that change a row */
	public function writes(): array {
		return array_values(array_filter($this->rStatements, static fn(array $rRun): bool => (bool) preg_match('/^(INSERT|REPLACE|UPDATE|DELETE)/', $rRun[0])));
	}
}

/** What a handle holds in place of its PDO connection: gone with the server, as "MySQL server has gone away". */
final class FanoutSyncOutageConnection {
	public function __construct(private FanoutSyncOutageServer $rServer) {
	}

	public function prepare(string $rQuery): FanoutSyncOutageStatement {
		$this->alive();
		return new FanoutSyncOutageStatement($this->rServer, $rQuery);
	}

	public function query(string $rQuery): bool {
		$this->alive();
		return true;
	}

	public function exec(string $rQuery): int {
		return 0;
	}

	public function setAttribute(int $rAttribute, mixed $rValue): bool {
		return true;
	}

	private function alive(): void {
		if ($this->rServer->rMeanwhile !== null) {
			$rMeanwhile = $this->rServer->rMeanwhile;
			$this->rServer->rMeanwhile = null;
			$rMeanwhile();
		}
		if (!$this->rServer->rUp) {
			throw new \PDOException('MySQL server has gone away');
		}
	}
}

final class FanoutSyncOutageStatement {
	/** @var list<array<string, mixed>> */
	private array $rRows = [];

	public function __construct(private FanoutSyncOutageServer $rServer, private string $rQuery) {
	}

	/** @param list<mixed> $rValues */
	public function execute(array $rValues = []): bool {
		if (!$this->rServer->rUp) {
			throw new \PDOException('MySQL server has gone away');
		}
		$this->rServer->rStatements[] = [$this->rQuery, $rValues];
		$this->rRows = $this->rServer->answer($this->rQuery);
		return true;
	}

	public function rowCount(): int {
		return count($this->rRows);
	}

	/** @return list<array<string, mixed>> */
	public function fetchAll(int $rMode = 0): array {
		return $this->rRows;
	}

	/** @return array<string, mixed>|false */
	public function fetch(int $rMode = 0): array|false {
		return $this->rRows[0] ?? false;
	}
}

/** The handle of a daemon booted from the database (DatabaseStage). */
final class FanoutSyncOutageEagerDb extends DatabaseHandler {
	// One attempt a query: the loop is the retry, and the tests do not wait out the back-off.
	protected $maxReconnectAttempts = 1;

	public function __construct(private FanoutSyncOutageServer $rServer) {
		parent::__construct();
	}

	public function db_connect(bool $migrate = false, ?bool $graceful = null) {
		// As the real connect does first: refused on a node in mode 2, graceful or not.
		ConnectAudit::guard(ConnectAudit::SQL);
		$this->dbh = $this->rServer->connect($migrate, $graceful);
		$this->connected = $this->dbh !== false;
		return $this->connected;
	}
}

/** The handle of a daemon booted from the node's replica (ReplicaStage): nothing is opened until first used. */
final class FanoutSyncOutageLazyDb extends LazyDatabaseHandler {
	protected $maxReconnectAttempts = 1;

	public function __construct(private FanoutSyncOutageServer $rServer, bool $rGraceful = false) {
		parent::__construct($rGraceful);
	}

	public function db_connect(bool $migrate = false, ?bool $graceful = null) {
		// As the real connect does first: refused on a node in mode 2, graceful or not.
		ConnectAudit::guard(ConnectAudit::SQL);
		$this->dbh = $this->rServer->connect($migrate, $graceful);
		$this->connected = $this->dbh !== false;
		return $this->connected;
	}
}

/**
 * @covers \XcVm\Cli\Commands\FanoutSyncCommand
 * @covers \XcVm\Cli\DaemonTrait::refreshSettings
 * @covers \XcVm\Core\Database\LazyDatabaseHandler::tryOpen
 *
 * fanout_sync used to end with the database: reading its rows went through
 * DatabaseFactory::connect(), which replaces a dead handle with a new one
 * whose connect exits the process when MariaDB is unreachable. Nothing
 * started the daemon again, and in the legacy store nothing else notices
 * that a daemon-served TS viewer left, so every one who did stayed "online".
 *
 * Now the loop sits the outage out. It keeps the settings it holds, a pass
 * that cannot read the rows is skipped (never read as "no rows", never a
 * reason to close or to end), the connection is asked for again each pass,
 * always gracefully, on the eager handle and on a lazy one's first use, and
 * the first pass after MariaDB is back closes what the outage left behind.
 */
final class FanoutSyncDatabaseOutageTest extends TestCase {
	/** The tuning an admin set: none of it is the daemon's default. */
	private const TUNING = ['fanout_grace_sec' => 77, 'fanout_write_timeout_sec' => 33, 'fanout_hls_window' => 9, 'seg_time' => 4];

	private FanoutSyncOutageServer $rServer;

	private string $rConfig;

	private string $rFlows;

	/** @var array<string, mixed> the state other tests left, put back as it was */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);
		defined('CONS_TMP_PATH') || define('CONS_TMP_PATH', sys_get_temp_dir() . '/xcvm-no-cons/');
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm_settings_cache_test/');
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);

		$this->rBefore = [
			'globals'  => array_intersect_key($GLOBALS, ['db' => 1, 'rSettings' => 1, 'rServers' => 1]),
			'settings' => SettingsManager::getAll(),
			'factory'  => DatabaseFactory::get(),
			'tracker'  => (new \ReflectionProperty(ConnectionTracker::class, 'db'))->getValue(),
			'cache'    => FileCache::getCache('settings'),
			'cached'   => @filemtime(FileCache::defaultPath() . 'settings'),
		];

		// MAIN's build: its connects are never refused (NodeRole), whatever this checkout holds.
		NodeRole::useMainBuild(true);
		ReplicaBoot::reset();
		FileCache::delCache('settings');
		$this->rFlows = sys_get_temp_dir() . '/xcvm-fanout-sync-flows-' . bin2hex(random_bytes(4)) . '.json';
		$this->rConfig = rtrim(BIN_PATH, '/') . '/xc_fanout/config.json';
		$this->rBefore['dir'] = is_dir(dirname($this->rConfig));
		$this->rBefore['config'] = is_file($this->rConfig) ? (string) file_get_contents($this->rConfig) : null;
		@mkdir(dirname($this->rConfig), 0775, true);
		@unlink($this->rConfig);

		$this->rServer = new FanoutSyncOutageServer();
		// The legacy store, fanout switched off: a pass then works from an
		// EMPTY set of daemon viewers, the one that closes every row it reads.
		$this->rServer->rSettings = ['redis_handler' => 0, 'fanout_enabled' => 0, 'save_closed_connection' => 0] + self::TUNING;
		$this->boot(new FanoutSyncOutageEagerDb($this->rServer));
	}

	protected function tearDown(): void {
		NodeRole::useMainBuild(null);
		NodeFlows::usePath(null);
		ReplicaBoot::reset();
		@unlink($this->rFlows);
		// The settings cache and the fanout tuning file, as they were found.
		if ($this->rBefore['cache'] !== false) {
			FileCache::setCache('settings', $this->rBefore['cache']);
			// With its age: a cache that had expired is not made fresh again.
			touch(FileCache::defaultPath() . 'settings', (int) $this->rBefore['cached']);
			clearstatcache();
		} else {
			FileCache::delCache('settings');
		}
		if ($this->rBefore['config'] !== null) {
			file_put_contents($this->rConfig, $this->rBefore['config']);
		} else {
			@unlink($this->rConfig);
			if (!$this->rBefore['dir']) {
				@rmdir(dirname($this->rConfig));
			}
		}

		(new \ReflectionProperty(ConnectionTracker::class, 'db'))->setValue(null, $this->rBefore['tracker']);
		DatabaseFactory::reset();
		if ($this->rBefore['factory'] !== null) {
			DatabaseFactory::set($this->rBefore['factory']);
		}
		SettingsManager::set($this->rBefore['settings']);
		foreach (['db', 'rSettings', 'rServers'] as $rName) {
			unset($GLOBALS[$rName]);
			if (array_key_exists($rName, $this->rBefore['globals'])) {
				$GLOBALS[$rName] = $this->rBefore['globals'][$rName];
			}
		}
	}

	/** What the bootstrap leaves a daemon: its handle, and the settings and servers read with it. */
	private function boot(DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		$GLOBALS['rSettings'] = SettingsRepository::decode($this->rServer->rSettings);
		$GLOBALS['rServers'] = [SERVER_ID => ['time_offset' => 0]];
		SettingsManager::set($GLOBALS['rSettings']);
		DatabaseFactory::set($rDb);
		ConnectionTracker::setDb($rDb);
		$this->rServer->rConnects = [];
	}

	/** A daemon-served TS row whose viewer was last heard an hour ago. */
	private static function row(string $rUUID, int $rActivityID): array {
		return ['activity_id' => $rActivityID, 'uuid' => $rUUID, 'server_id' => SERVER_ID, 'stream_id' => 7, 'user_id' => 3, 'pid' => 0, 'container' => 'ts', 'hls_end' => 0, 'hls_last_read' => time() - 3600, 'date_start' => time() - 7200];
	}

	private static function call(FanoutSyncCommand $rSync, string $rMethod, mixed ...$rArgs): mixed {
		return (new \ReflectionMethod(FanoutSyncCommand::class, $rMethod))->invoke($rSync, ...$rArgs);
	}

	/** @return list<int> the activity ids of the rows closed so far */
	private function closed(): array {
		$rOut = [];
		foreach ($this->rServer->writes() as [$rQuery, $rValues]) {
			$this->assertSame('DELETE FROM `lines_live` WHERE `activity_id` = ?', $rQuery);
			$rOut[] = (int) $rValues[0];
		}
		return $rOut;
	}

	private function assertEveryConnectWasGraceful(int $rAtLeast): void {
		$this->assertGreaterThanOrEqual($rAtLeast, count($this->rServer->rConnects));
		$this->assertSame([[false, true]], array_values(array_unique($this->rServer->rConnects, SORT_REGULAR)), 'the panel\'s schema (never $migrate), and never the connect that ends the process');
	}

	/**
	 * The loop, with the real settings refresh at each pass and whatever
	 * $rStage does to the server before it; it ends after $rPasses passes,
	 * as a deploy ends it.
	 *
	 * @param callable(int): void $rStage called before pass n, and once more after the last
	 * @param int|null $rEndsIn the pass the loop is expected to leave in by itself
	 */
	private function loop(int $rPasses, callable $rStage, ?int $rEndsIn = null): FanoutSyncCommand {
		$rSync = new class ($rPasses, $rStage) extends FanoutSyncCommand {
			public int $rPass = 0;

			public int $rRestarts = 0;

			/** @param callable(int): void $rStage */
			public function __construct(private int $rPasses, private $rStage) {
			}

			// The checks of the real one that read the host (nginx, the
			// command's file) are left out; its settings refresh is not.
			protected function refreshOrBreak(): bool {
				($this->rStage)(++$this->rPass);
				if ($this->rPass > $this->rPasses) {
					return false;
				}
				$this->refreshSettings();
				return true;
			}

			// Ten seconds a pass in the daemon; none here.
			protected function pause(): void {
			}

			public function refreshedAt(): ?int {
				return $this->rLastCheck;
			}

			protected function assertRunAsXcVm(): bool {
				return true;
			}

			protected function acquireDaemonLock(string $rLockName): bool {
				return true;
			}

			protected function killStaleProcesses(string $rPattern): void {
			}

			protected function setProcessTitle(string $rTitle): void {
			}

			protected function initDaemonMD5(): void {
			}

			protected function initRedisIfEnabled(): void {
			}

			protected function restartDaemon(string $rCommandName): void {
				$this->rRestarts++;
			}
		};

		ob_start();
		try {
			$rCode = $rSync->execute([]);
		} finally {
			ob_end_clean();
		}
		$this->assertSame(0, $rCode);
		$this->assertSame($rEndsIn ?? $rPasses + 1, $rSync->rPass, 'the loop ran every pass, and ended where the test expects');
		$this->assertSame(1, $rSync->rRestarts);
		return $rSync;
	}

	public function testRowsThatCannotBeReadAreNeverAnEmptySet(): void {
		$rSync = new FanoutSyncCommand();
		$this->rServer->rLive = [self::row('aaaa', 1)];
		$this->assertSame(['aaaa'], array_column(self::call($rSync, 'daemonConnections'), 'uuid'));
		$this->assertSame([], $this->rServer->rConnects, 'a live connection is not opened again');

		$this->rServer->rUp = false;
		$this->assertNull(self::call($rSync, 'daemonConnections'), 'null skips the pass; [] would have every daemon viewer dropped as an orphan');
		$this->assertNull(self::call($rSync, 'daemonConnections'), 'and again at the next pass');
		$this->assertEveryConnectWasGraceful(2);
		$this->assertSame([], $this->rServer->writes());

		$this->rServer->rUp = true;
		$this->assertSame(['aaaa'], array_column(self::call($rSync, 'daemonConnections'), 'uuid'), 'the same handle, connected again');
		$this->assertSame($GLOBALS['db'], DatabaseFactory::get(), 'the handle every DatabaseAware class resolves');
	}

	public function testTheDivergenceWriteIsSkippedWhileTheDatabaseIsDown(): void {
		$rSync = new FanoutSyncCommand();
		self::call($rSync, 'writeDivergence', [self::row('aaaa', 1)], ['aaaa' => 57]);
		$this->assertCount(2, $this->rServer->writes(), 'with the database up: lines_divergence and the live row, as before');

		$this->rServer->rUp = false;
		self::call($rSync, 'writeDivergence', [self::row('aaaa', 1)], ['aaaa' => 57]);
		$this->assertCount(2, $this->rServer->writes());
		$this->assertEveryConnectWasGraceful(1);
	}

	/** A daemon that is running when MariaDB goes away, and still is when it comes back. */
	public function testTheLoopSitsOutAnOutageAndClosesTheGhostsWhenTheDatabaseIsBack(): void {
		$rClosed = [];
		$rHeld = [];
		$rSync = $this->loop(4, function (int $rPass) use (&$rClosed, &$rHeld): void {
			$rClosed[$rPass] = $this->closed();
			$rHeld[$rPass] = SettingsManager::get('fanout_grace_sec');
			match ($rPass) {
				1 => $this->rServer->rLive = [self::row('aaaa', 1)],
				2 => [$this->rServer->rUp = false, $this->rServer->rLive = [self::row('bbbb', 2)]],
				4 => $this->rServer->rUp = true,
				default => null,
			};
		});

		$this->assertSame([1], $rClosed[2], 'the database up: the viewer who left is closed');
		$this->assertSame([1], $rClosed[3], 'pass 2, without the database: nothing is closed, or touched');
		$this->assertSame([1], $rClosed[4], 'pass 3, the same');
		$this->assertSame([1, 2], $rClosed[5], 'the first pass with the database back closes the viewer who left meanwhile');
		$this->assertSame([77, 77, 77, 77], array_map('intval', array_slice($rHeld, 1)), 'and the settings it held all along');
		$this->assertEveryConnectWasGraceful(3);
		$this->assertNotNull($rSync->refreshedAt());
	}

	/**
	 * The settings refresh over an outage, with no settings cache to fall
	 * back on: the daemon keeps what it read, and the fanout daemon's tuning
	 * file is not written from an empty row (every value its default).
	 */
	public function testAFailedRefreshKeepsTheSettingsAndTheFanoutTuning(): void {
		$this->rServer->rSettings['fanout_enabled'] = 1;
		$this->boot($GLOBALS['db']);
		$rTuning = [];
		$rRead = [];
		$this->loop(4, function (int $rPass) use (&$rTuning, &$rRead): void {
			$rTuning[$rPass] = is_file($this->rConfig) ? json_decode((string) file_get_contents($this->rConfig), true) : null;
			$rRead[$rPass] = [SettingsManager::get('fanout_grace_sec'), FileCache::getCache('settings') !== false];
			if ($rPass === 2) {
				// MariaDB goes away, and the settings cache is gone too (a tmpfs emptied, a sweep).
				$this->rServer->rUp = false;
				FileCache::delCache('settings');
			}
		});

		$rAdmin = ['grace_sec' => 77, 'write_timeout_sec' => 33, 'hls_window' => 9, 'hls_target_sec' => 4];
		$this->assertEquals($rAdmin, array_intersect_key($rTuning[2], $rAdmin), 'the first refresh wrote the admin\'s tuning');
		foreach ([3, 4, 5] as $rPass) {
			$this->assertEquals($rAdmin, array_intersect_key($rTuning[$rPass], $rAdmin), 'pass ' . ($rPass - 1) . ' without the database: not the defaults (10, 15, 6, 6)');
			$this->assertSame([77, false], [(int) $rRead[$rPass][0], $rRead[$rPass][1]], 'the settings in memory are kept, and no cache is written from a failed read');
		}
	}

	/** A daemon whose first settings read fails works from settings nobody read: no row is asked for, none closed. */
	public function testADaemonThatNeverReadItsSettingsDoesNothing(): void {
		$this->rServer->rLive = [self::row('aaaa', 1)];
		$this->rServer->rUp = false;
		$rAsked = [];
		$rSync = $this->loop(3, function (int $rPass) use (&$rAsked): void {
			$rAsked[$rPass] = array_column($this->rServer->rStatements, 0);
			if ($rPass === 3) {
				$this->rServer->rUp = true;
			}
		});

		$this->assertSame([], $rAsked[3], 'two passes without the database, and without a settings read of its own: nothing');
		$this->assertSame(['SELECT * FROM `settings`', 'SELECT * FROM `lines_live` WHERE `server_id` = ? AND `pid` = 0 AND `hls_end` = 0', 'DELETE FROM `lines_live` WHERE `activity_id` = ?'], $rAsked[4], 'the database back: the settings first, then the pass');
		$this->assertNotNull($rSync->refreshedAt());
		$this->assertEveryConnectWasGraceful(1);
	}

	/** After the outage the rows are read afresh: a viewer the daemon still lists keeps its row. */
	public function testAViewerStillConnectedAfterTheOutageIsKept(): void {
		$rSync = new FanoutSyncCommand();
		$this->rServer->rLive = [self::row('aaaa', 1), self::row('bbbb', 2)];
		$this->rServer->rUp = false;
		$this->assertNull(self::call($rSync, 'daemonConnections'));
		$this->rServer->rUp = true;

		$rRows = self::call($rSync, 'daemonConnections');
		self::call($rSync, 'reconcile', ['aaaa' => 0], $rRows);
		$this->assertSame([2], $this->closed(), 'only the viewer who left');
	}

	/** A viewer first seen without a row before the outage is judged on the rows read after it. */
	public function testAnOrphanSeenBeforeTheOutageIsJudgedOnTheRowsReadAfterIt(): void {
		$rSync = new FanoutSyncCommand();
		$rDropped = [];
		$rDrop = static function (string $rUUID) use (&$rDropped): void {
			$rDropped[] = $rUUID;
		};
		ob_start();
		try {
			$rSync->dropOrphans(['kept', 'gone'], [], 1000, $rDrop);
			// The outage: no pass reaches dropOrphans(). Then the rows again, far past the grace.
			$rSync->dropOrphans(['kept', 'gone'], [['uuid' => 'kept', 'hls_end' => 0]], 1600, $rDrop);
		} finally {
			ob_end_clean();
		}
		$this->assertSame(['gone'], $rDropped);
	}

	// ── A node booted from its replica: the handle is lazy ───────────────

	/** The lazy handle itself: opened without ending the process, tried again after a failure, and connected once. */
	public function testALazyHandleOpensGracefullyAndRetries(): void {
		$this->rServer->rUp = false;
		$rDb = new FanoutSyncOutageLazyDb($this->rServer);
		$this->assertFalse($rDb->tryOpen());
		$this->assertFalse($rDb->isOpen(), 'a failed try leaves it unopened');
		$this->assertFalse($rDb->tryOpen(), 'and is tried again');
		$this->assertSame([[false, true], [false, true]], $this->rServer->rConnects);

		$this->rServer->rUp = true;
		$this->assertTrue($rDb->tryOpen());
		$this->assertTrue($rDb->isOpen());
		$this->assertTrue($rDb->query('SELECT * FROM `settings`'));
		$this->assertTrue($rDb->tryOpen(), 'open and alive: nothing to do');
		$this->assertCount(3, $this->rServer->rConnects, 'the first query does not connect a second time');

		// The connection dies under an open handle: opened again.
		$this->rServer->rUp = false;
		$this->assertFalse($rDb->tryOpen());
		$this->rServer->rUp = true;
		$this->assertTrue($rDb->tryOpen());
		$this->assertTrue($rDb->query('SELECT * FROM `settings`'));
		$this->assertEveryConnectWasGraceful(5);
	}

	/** A graceful handle's own failed open is final (LazyDatabaseHandlerTest); tryOpen() is the way back. */
	public function testAGracefulLazyHandleThatFailedCanBeOpenedAgain(): void {
		$rDb = new FanoutSyncOutageLazyDb($this->rServer, true);
		foreach ([false, true] as $rUp) {
			$this->rServer->rUp = $rUp;
			try {
				$rDb->query('SELECT * FROM `settings`');
				$this->fail('a failed graceful handle answers no query');
			} catch (DatabaseUnavailableException) {
			}
		}
		$this->assertTrue($rDb->tryOpen());
		$this->assertTrue($rDb->query('SELECT * FROM `settings`'));
	}

	/** The daemon's two first uses of an unopened lazy handle: its settings refresh, and its rows. */
	public function testTheDaemonsFirstUseOfALazyHandleSurvivesAnOutage(): void {
		$this->rServer->rUp = false;
		$this->rServer->rLive = [self::row('aaaa', 1)];
		$rDb = new FanoutSyncOutageLazyDb($this->rServer);
		$this->boot($rDb);
		$rSync = new FanoutSyncCommand();

		$this->assertFalse(self::call($rSync, 'refreshSettings'), 'the settings read comes first in a pass');
		$this->assertSame(77, (int) SettingsManager::get('fanout_grace_sec'), 'the settings it booted with are kept');
		$this->assertNull(self::call($rSync, 'daemonConnections'));
		$this->assertFalse($rDb->isOpen());
		$this->assertEveryConnectWasGraceful(2);

		$this->rServer->rUp = true;
		$this->rServer->rSettings['fanout_grace_sec'] = 78;
		$this->assertTrue(self::call($rSync, 'refreshSettings'));
		$this->assertSame(78, (int) SettingsManager::get('fanout_grace_sec'));
		$this->assertSame(['aaaa'], array_column(self::call($rSync, 'daemonConnections'), 'uuid'));
		$this->assertCount(3, $this->rServer->rConnects, 'opened once, by the first use that found the database');
	}

	/** The loop on a lazy handle, started as the database went away, then recovered. */
	public function testTheLoopOnALazyHandleSitsOutAnOutage(): void {
		$this->rServer->rUp = false;
		$this->rServer->rLive = [self::row('aaaa', 1)];
		$this->boot(new FanoutSyncOutageLazyDb($this->rServer));
		$rClosed = [];
		$this->loop(3, function (int $rPass) use (&$rClosed): void {
			$rClosed[$rPass] = $this->closed();
			if ($rPass === 3) {
				$this->rServer->rUp = true;
			}
		});
		$this->assertSame([[], [], [], [1]], array_values($rClosed));
		$this->assertEveryConnectWasGraceful(3);
	}

	/**
	 * A process booted from the replica reads its settings from the node's
	 * cache alone. That cache gone (with the record of who owns it), there
	 * is nothing to read: the daemon keeps the settings it holds, its
	 * refresh time and the fanout tuning, and no SQL is tried.
	 */
	public function testAReplicaBootedDaemonKeepsItsSettingsWhenItsCachesAreGone(): void {
		$this->rServer->rSettings['fanout_enabled'] = 1;
		$this->boot(new FanoutSyncOutageLazyDb($this->rServer));
		ReplicaBoot::start(ReplicaBoot::ALWAYS);
		$this->assertTrue(ReplicaBoot::active() && !ReplicaBoot::hybrid());
		$rHeld = SettingsManager::getAll();
		FanoutConfig::sync($rHeld);
		$rTuning = (string) file_get_contents($this->rConfig);
		$this->assertSame(77, json_decode($rTuning, true)['grace_sec']);

		$rSync = new FanoutSyncCommand();
		$rAt = new \ReflectionProperty(FanoutSyncCommand::class, 'rLastCheck');
		$rAt->setValue($rSync, 1000);

		$this->assertFalse(FileCache::getCache('settings'));
		$this->assertFalse(self::call($rSync, 'refreshSettings'));
		$this->assertSame($rHeld, SettingsManager::getAll());
		$this->assertSame(1000, $rAt->getValue($rSync), 'not taken as a refresh: the loop syncs the tuning only after one');
		$this->assertSame([], SettingsRepository::getAll(true), 'every other caller: an empty array, as before');
		$this->assertSame([], $this->rServer->rConnects, 'and no connect, graceful or not');

		// The loop over the same loss: the cache answers pass 1, and is gone from pass 2.
		FileCache::setCache('settings', $rHeld);
		$rSeen = [];
		$this->loop(3, function (int $rPass) use (&$rSeen): void {
			$rSeen[$rPass] = [(int) SettingsManager::get('fanout_grace_sec'), (string) @file_get_contents($this->rConfig)];
			if ($rPass === 2) {
				FileCache::delCache('settings');
			}
		});
		foreach ([2, 3, 4] as $rPass) {
			$this->assertSame([77, $rTuning], $rSeen[$rPass], 'pass ' . ($rPass - 1));
		}
		$this->assertSame([], $this->rServer->rConnects);
		$this->assertSame([], $this->rServer->rStatements);
	}

	/** A node in mode 2 has no database of MAIN's: not an outage, and no connect is tried (each one is refused, and counted). */
	public function testANodeInModeTwoIsNeverAskedToConnect(): void {
		NodeRole::useMainBuild(false);
		file_put_contents($this->rFlows, (string) json_encode(['mode' => 2, 'flows' => 255, 'state' => 'active']));
		NodeFlows::usePath($this->rFlows, true);
		clearstatcache();
		$this->assertTrue(NodeRole::refusesConnects());

		$this->rServer->rUp = false;
		$rSync = new FanoutSyncCommand();
		$this->assertFalse(self::call($rSync, 'databaseReady'));
		$this->assertNull(self::call($rSync, 'daemonConnections'));
		$this->assertSame([], $this->rServer->rConnects);
	}

	/** The node enters mode 2: its connects are refused from now on. */
	private function enterModeTwo(): void {
		NodeRole::useMainBuild(false);
		file_put_contents($this->rFlows, (string) json_encode(['mode' => 2, 'flows' => 0, 'state' => 'active']));
		NodeFlows::usePath($this->rFlows, true);
		clearstatcache();
	}

	/** A node in mode 1 (no flow on: the legacy store), which may still connect to MAIN's database. */
	private function modeOne(): void {
		NodeRole::useMainBuild(false);
		file_put_contents($this->rFlows, (string) json_encode(['mode' => 1, 'flows' => 0, 'state' => 'active']));
		NodeFlows::usePath($this->rFlows, true);
		clearstatcache();
		$this->assertFalse(NodeRole::refusesConnects());
	}

	/**
	 * The node enters mode 2 inside a pass, after the pass asked whether the
	 * mode changed, while the daemon's statement finds its connection dead:
	 * the handle's own reconnect is refused with an exception, graceful or
	 * not. The daemon leaves its loop as on a mode change and is started
	 * again; it connects to nothing, and the settings it held are untouched.
	 */
	public function testAModeChangeDuringAReconnectEndsTheLoopAsAModeChangeDoes(): void {
		$this->modeOne();
		$rHeld = SettingsManager::getAll();
		$rSync = $this->loop(4, function (int $rPass): void {
			if ($rPass === 2) {
				$this->rServer->rConnects = [];
				$this->rServer->rMeanwhile = function (): void {
					$this->rServer->rUp = false;
					$this->enterModeTwo();
				};
			}
		}, 2);

		$this->assertTrue(NodeRole::refusesConnects());
		$this->assertSame([], $this->rServer->rConnects, 'the refused connect reached no server');
		$this->assertSame((int) $rHeld['fanout_grace_sec'], (int) SettingsManager::get('fanout_grace_sec'));
		$this->assertSame([], $this->rServer->writes());
	}

	/** The same on a lazy handle that is open: the refusal comes out of tryOpen(), in the settings refresh. */
	public function testAModeChangeDuringALazyHandlesPingEndsTheLoopAsAModeChangeDoes(): void {
		$this->modeOne();
		$rDb = new FanoutSyncOutageLazyDb($this->rServer);
		$this->boot($rDb);
		ReplicaBoot::start(ReplicaBoot::WHEN_READY);
		$this->assertTrue(ReplicaBoot::hybrid());
		$this->assertTrue($rDb->tryOpen());

		$this->loop(4, function (int $rPass): void {
			if ($rPass === 2) {
				$this->rServer->rConnects = [];
				$this->rServer->rMeanwhile = function (): void {
					$this->rServer->rUp = false;
					$this->enterModeTwo();
				};
			}
		}, 2);
		$this->assertSame([], $this->rServer->rConnects);
		$this->assertSame(77, (int) SettingsManager::get('fanout_grace_sec'));

		// What the loop caught, and nothing wider: the refusal itself.
		$this->rServer->rUp = false;
		$this->expectException(LbDatabaseAccessException::class);
		$rDb->tryOpen();
	}

	/**
	 * The same node, its process booted from the replica with leave to read
	 * MAIN's database while the node was in mode 1 (WHEN_READY): in mode 2 that
	 * leave is gone, so a refresh with no cache left asks for no connection
	 * (which would be refused with an exception) and keeps what it holds.
	 */
	public function testAReplicaBootedDaemonInModeTwoRefreshesWithoutAConnect(): void {
		$rDb = new FanoutSyncOutageLazyDb($this->rServer);
		$this->boot($rDb);
		ReplicaBoot::start(ReplicaBoot::WHEN_READY);
		$this->assertTrue(ReplicaBoot::hybrid(), 'mode 1: it may read MAIN\'s database');

		NodeRole::useMainBuild(false);
		file_put_contents($this->rFlows, (string) json_encode(['mode' => 2, 'flows' => 255, 'state' => 'active']));
		NodeFlows::usePath($this->rFlows, true);
		clearstatcache();
		$this->assertTrue(NodeRole::refusesConnects());
		$this->assertFalse(ReplicaBoot::hybrid());

		$rHeld = SettingsManager::getAll();
		$rSync = new FanoutSyncCommand();
		$this->assertFalse(self::call($rSync, 'refreshSettings'));
		$this->assertSame($rHeld, SettingsManager::getAll());
		$this->assertFalse($rDb->isOpen());
		$this->assertSame([], $this->rServer->rConnects);
	}

	/** The exit that ended the daemon: DatabaseFactory::connect() builds a handle whose connect is not graceful. */
	public function testTheLoopNeverReplacesItsHandle(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/FanoutSyncCommand.php');
		// In the code, not in the comments that explain it.
		$this->assertDoesNotMatchRegularExpression('/^\s*(?!\*|\/\/)\S.*DatabaseFactory::(connect|connectLazy|open)\(/m', $rSource);
		$this->assertStringNotContainsString('new DatabaseHandler(', $rSource);
		$this->assertSame(2, substr_count($rSource, 'if (!$this->databaseReady()) {'), 'both readers of the database ask first');
	}
}
