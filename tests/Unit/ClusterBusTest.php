<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\ClusterBus;

/**
 * The cluster bus's wake-ups, against a real redis-server on a unix socket:
 * a wake is never lost, however it races the waiter, and without the bus
 * every caller is told to poll.
 */
final class ClusterBusTest extends TestCase {
	private static ?string $rDir = null;

	/** @var resource|null */
	private static $rProc = null;

	public static function setUpBeforeClass(): void {
		if (!class_exists(\Redis::class) || trim((string) shell_exec('command -v redis-server')) === '') {
			return;
		}
		self::$rDir = sys_get_temp_dir() . '/xcvm-bus-' . bin2hex(random_bytes(4));
		mkdir(self::$rDir);
		$rNull = ['file', '/dev/null', 'w'];
		self::$rProc = proc_open(['redis-server', '--port', '0', '--unixsocket', self::$rDir . '/cluster.sock', '--unixsocketperm', '700', '--save', '', '--appendonly', 'no'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 50 && !file_exists(self::$rDir . '/cluster.sock'); $i++) {
			usleep(50000);
		}
	}

	public static function tearDownAfterClass(): void {
		if (self::$rProc !== null) {
			proc_terminate(self::$rProc);
			proc_close(self::$rProc);
		}
		if (self::$rDir !== null) {
			exec('rm -rf ' . escapeshellarg(self::$rDir));
		}
	}

	protected function tearDown(): void {
		ClusterBus::useSocket(null);
	}

	private function bus(): \Redis {
		if (self::$rProc === null) {
			$this->markTestSkipped('redis-server or phpredis not available');
		}
		ClusterBus::useSocket(self::$rDir . '/cluster.sock');
		$rRedis = ClusterBus::client();
		$this->assertInstanceOf(\Redis::class, $rRedis);
		$rRedis->flushAll();
		return $rRedis;
	}

	public function testWithoutTheBusCallersPoll(): void {
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '.sock');
		$this->assertNull(ClusterBus::client());
		$this->assertFalse(ClusterBus::wakeNode(5));
		$this->assertNull(ClusterBus::waitNode(5, 0.1), 'null: poll instead');
		$this->assertNull(ClusterBus::waitAck(str_repeat('a', 32), 0.1));
	}

	public function testAWakeBeforeTheWaitIsNotLostAndWakesCollapse(): void {
		$rRedis = $this->bus();
		$this->assertTrue(ClusterBus::wakeNode(5));
		$this->assertTrue(ClusterBus::wakeNode(5));
		$this->assertSame(1, $rRedis->lLen('wake:5'), 'many wakes, one wake-up');
		$this->assertGreaterThan(0, $rRedis->ttl('wake:5'));
		$rStart = microtime(true);
		$this->assertTrue(ClusterBus::waitNode(5, 2.0));
		$this->assertLessThan(0.5, microtime(true) - $rStart);
		$this->assertFalse(ClusterBus::waitNode(6, 0.2), 'another node was not woken');
	}

	/**
	 * A blocking wait raises the connection's read timeout to outlast its
	 * block, and puts it back: a later call on the same connection gives up on
	 * a bus that hangs within a second, not within the last wait's length.
	 */
	public function testAWaitPutsTheReadTimeoutBack(): void {
		$rRedis = $this->bus();
		$rBefore = $rRedis->getOption(\Redis::OPT_READ_TIMEOUT);
		$this->assertFalse(ClusterBus::waitNode(7, 0.3));
		$this->assertSame($rBefore, $rRedis->getOption(\Redis::OPT_READ_TIMEOUT));
		$this->assertTrue(ClusterBus::wakeNode(7));
		$this->assertTrue(ClusterBus::waitNode(7, 2.0));
		$this->assertSame($rBefore, $rRedis->getOption(\Redis::OPT_READ_TIMEOUT), 'after a wake too');
	}

	public function testAWakeFromAnotherProcessEndsTheWait(): void {
		$this->bus();
		$rScript = tempnam(sys_get_temp_dir(), 'wake') . '.php';
		file_put_contents($rScript, '<?php require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';
usleep(300000);
XcVm\Domain\Cluster\ClusterBus::useSocket(' . var_export(self::$rDir . '/cluster.sock', true) . ');
XcVm\Domain\Cluster\ClusterBus::wakeAck(' . var_export(str_repeat('c', 32), true) . ');');
		$rChild = proc_open([PHP_BINARY, $rScript], [], $rPipes);
		$rStart = microtime(true);
		$rWoken = ClusterBus::waitAck(str_repeat('c', 32), 5.0);
		$rTook = microtime(true) - $rStart;
		proc_close($rChild);
		@unlink($rScript);
		$this->assertTrue($rWoken);
		$this->assertGreaterThan(0.2, $rTook);
		$this->assertLessThan(2.0, $rTook, 'woken, not timed out');
	}

	public function testTheLongPollHoldsNoDatabaseConnectionWhileBlocked(): void {
		$rDb = new class extends \XcVm\Core\Database\DatabaseHandler {
			public int $rClosed = 0;

			public function __construct() {
				$this->dbh = false;
			}

			public function close_mysql() {
				$this->rClosed++;
				return true;
			}
		};
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '.sock');
		$this->assertNull(ClusterBus::waitNodeReleasing(5, 0.1, $rDb));
		$this->assertSame(0, $rDb->rClosed, 'without the bus the caller polls: the connection stays');

		$this->bus();
		ClusterBus::wakeNode(5);
		$this->assertTrue(ClusterBus::waitNodeReleasing(5, 1.0, $rDb));
		$this->assertSame(1, $rDb->rClosed, 'released before blocking');
		$this->assertFalse(ClusterBus::waitNodeReleasing(5, 0.1, new stdClass()), 'any other handle is left alone');
	}

	/**
	 * A mark that root creates (a cron, a CLI command) goes to the owner of
	 * the bus's directory, xc_vm on MAIN: the workers cannot set the mtime of
	 * a file root owns, so they could never move it on. Root only.
	 */
	public function testAMarkRootCreatesGoesToTheOwnerOfTheBusDirectory(): void {
		if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
			$this->markTestSkipped('only root hands a mark on');
		}
		$rDir = sys_get_temp_dir() . '/xcvm-bus-owner-' . bin2hex(random_bytes(4));
		mkdir($rDir);
		try {
			// A mark is a file beside the socket: no bus is needed.
			ClusterBus::useSocket($rDir . '/cluster.sock');
			$this->assertTrue(ClusterBus::mark('root.stale', 1800000000000));
			$this->assertSame(0, fileowner($rDir . '/root.stale'), 'a directory root owns: the mark stays root\'s');

			$this->assertTrue(chown($rDir, 65534));
			$this->assertTrue(ClusterBus::mark('owner.stale', 1800000000000));
			clearstatcache();
			$this->assertSame(65534, fileowner($rDir . '/owner.stale'), 'the bus directory\'s owner');
			$this->assertTrue(ClusterBus::mark('owner.stale', 1800000005000));
			clearstatcache();
			$this->assertSame([1800000005, 65534], [ClusterBus::markedAt('owner.stale'), fileowner($rDir . '/owner.stale')], 'moved on, and still the owner\'s');
		} finally {
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}
}
