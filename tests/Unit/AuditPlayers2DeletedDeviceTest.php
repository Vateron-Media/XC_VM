<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * The MAG portal knows a device for as long as the panel has it. The device's
 * cache entry is read again from the database every ten minutes: when the
 * device is gone by then, so is the entry, and the box is a stranger again.
 * A lookup that failed says nothing about the device, so the entry stays.
 *
 * getDevice() is a function of the procedural portal.php: it is taken from the
 * script as written and run in a child PHP against the test database, with
 * stand-ins for the line lookup and the bouquet cache.
 */
final class AuditPlayers2DeletedDeviceTest extends TestCase {
	private const MAC = '00:1A:79:00:00:07';

	private const HARNESS = <<<'PHP'
<?php
namespace XcVm\Infrastructure\Cache {
	class CacheReader {
		public static function get($rKey) {
			return [];
		}
	}
}
namespace XcVm\Domain\User {
	class UserRepository {
		public static function getStreamingUserInfo(...$rArgs) {
			return ['id' => 900, 'username' => 'line', 'password' => 'secret', 'bouquet' => [], 'allowed_ips' => '[]'];
		}
	}
}
namespace {
	%USES%

	require %BOOTSTRAP%;

	/** Database's query()/num_rows()/get_row(). Once $rDown is set a query fails as Database's does: false, and no rows. */
	final class PortalDb {
		public bool $rDown = false;
		public int $rQueries = 0;
		private ?\PDOStatement $rResult = null;

		public function __construct(private \PDO $rPdo) {
		}

		public function query($rQuery, ...$rArgs) {
			$this->rQueries++;
			$this->rResult = null;
			if ($this->rDown) {
				return false;
			}
			$this->rResult = $this->rPdo->prepare($rQuery);
			return $this->rResult->execute($rArgs);
		}

		public function num_rows() {
			return $this->rResult ? $this->rResult->rowCount() : 0;
		}

		public function get_row() {
			return $this->rResult ? ($this->rResult->fetch(\PDO::FETCH_ASSOC) ?: []) : false;
		}
	}

	$rIn = json_decode($argv[1], true);
	define('MINISTRA_TMP_PATH', $rIn['dir'] . '/');

	$db = new PortalDb(TestDb::connect($rIn['schema']));
	$rSettings = [];
	$rCached = false;
	$rIP = '203.0.113.9';

%FUNCTIONS%

	// The entry of a box that passed get_profile, last read from the database 601 s ago.
	$rDevice = getDevice(null, $rIn['mac']);
	$rDevice['token'] = 'TOKEN';
	$rDevice['authenticated'] = true;
	$rDevice['generated'] = time() - 601;
	updateCache();

	if ($rIn['deleted']) {
		$db->query('DELETE FROM `mag_devices`');
	}
	$db->rDown = $rIn['down'];

	$rFirst = getDevice(7);
	$db->rQueries = 0;
	$rSecond = getDevice(7);

	echo json_encode([
		'known' => [!empty($rFirst['mag_id']), !empty($rSecond['mag_id'])],
		'entry' => file_exists(MINISTRA_TMP_PATH . 'ministra_7'),
		'queries' => $db->rQueries,
	]);
}
PHP;

	private TestDb $rDb;
	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('mag_devices'));
		$this->rDb->query('INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (7, 900, ?)', self::MAC);

		$this->rDir = dirname(__DIR__) . '/.tmp/ministra_device_' . getmypid() . '_' . $this->rDb->schema();
		mkdir($this->rDir, 0775, true);

		$rPortal = (string) file_get_contents(MAIN_HOME . 'Ministra/portal.php');
		preg_match_all('/^use [^;]+;$/m', $rPortal, $rUses);
		$rFunctions = '';
		foreach (['getDevice', 'updateCache'] as $rName) {
			$this->assertSame(1, preg_match('/^function ' . $rName . '\(.*?^}$/ms', $rPortal, $rMatch), $rName . '() is not in portal.php');
			$rFunctions .= $rMatch[0] . "\n";
		}
		file_put_contents($this->rDir . '/portal.php', strtr(self::HARNESS, [
			'%BOOTSTRAP%' => var_export(dirname(__DIR__) . '/bootstrap.php', true),
			'%USES%' => implode("\n\t", $rUses[0]),
			'%FUNCTIONS%' => $rFunctions,
		]));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * A verified box whose entry is due for a refresh asks twice.
	 *
	 * @return array{known: array{0: bool, 1: bool}, entry: bool, queries: int} whether each request knew the device, whether its entry is left, and the queries of the second request
	 */
	private function refresh(bool $rDeleted, bool $rDown = false): array {
		$rIn = ['dir' => $this->rDir, 'schema' => $this->rDb->schema(), 'mac' => self::MAC, 'deleted' => $rDeleted, 'down' => $rDown];
		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $this->rDir . '/portal.php', json_encode($rIn)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$rResult = json_decode((string) $rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);

		return $rResult;
	}

	public function testADeviceThePanelNoLongerHasIsForgotten(): void {
		$rEnd = $this->refresh(true);

		$this->assertSame([false, false], $rEnd['known'], 'a deleted device was still known from its cache entry');
		$this->assertFalse($rEnd['entry'], 'the entry of a deleted device was kept');
		$this->assertSame(0, $rEnd['queries'], 'the deleted device was looked up again on its next request');
	}

	public function testADeviceThePanelStillHasIsReadAgain(): void {
		$rEnd = $this->refresh(false);

		$this->assertSame([true, true], $rEnd['known']);
		$this->assertTrue($rEnd['entry']);
	}

	public function testAFailedLookupKeepsTheDevice(): void {
		$rEnd = $this->refresh(true, true);

		$this->assertSame([true, true], $rEnd['known'], 'a device was dropped because the database did not answer');
		$this->assertTrue($rEnd['entry']);
	}
}
