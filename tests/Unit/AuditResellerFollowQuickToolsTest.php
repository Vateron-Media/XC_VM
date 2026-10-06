<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The administrator's quick tools that remove lines by a rule (expired ones,
 * expired trials, lines without a name, device lines without a device) remove
 * each of them as a line deleted from the list is removed: its live sessions
 * are closed, its logs go with it, the line cache is told, and a line that
 * was paired with it is paired with none. A line that expired a moment ago
 * can still have a viewer. The devices of the removed lines go with them, and
 * no other line or device is touched.
 */
final class AuditResellerFollowQuickToolsTest extends TestCase {
	private const EXPIRED = 10;
	private const EXPIRED_TRIAL = 11;
	private const TRIAL = 12;
	private const NO_EXPIRY = 13;
	private const PAIRED = 14;
	private const MAG_EXPIRED = 20;
	private const MAG_EXPIRED_TRIAL = 21;
	private const MAG = 22;
	private const MAG_UNLINKED = 23;
	private const ENIGMA_EXPIRED = 30;
	private const ENIGMA_EXPIRED_TRIAL = 31;
	private const ENIGMA = 32;
	private const ENIGMA_UNLINKED = 33;
	private const NAMELESS = 40;

	private const LINES = [10, 11, 12, 13, 14, 20, 21, 22, 23, 30, 31, 32, 33, 40];
	private const MAG_DEVICES = [20, 21, 22];
	private const ENIGMA_DEVICES = [30, 31, 32];

	private TestDb $rDb;

	/** @var list<int> the lines whose live sessions were read to be closed */
	private array $rClosed = [];

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['lines', 'lines_live', 'lines_logs', 'lines_activity', 'mag_devices', 'enigma2_devices', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 1, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$rLog = new QueryLogDb($this->rDb);
		$rLog->rBefore = function (string $rQuery, array $rArgs): void {
			if (str_contains($rQuery, 'FROM `lines_live` WHERE `user_id`')) {
				$this->rClosed[] = (int) $rArgs[0];
			}
		};
		$GLOBALS['db'] = $rLog;
		DatabaseFactory::set($rLog);
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $rLog);
		}
	}

	protected function tearDown(): void {
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		unset($GLOBALS['db']);
	}

	/**
	 * The panel's lines: of plain lines, MAG devices and Enigma2 devices one
	 * that expired a minute ago, one such that is a trial and one with a month
	 * left; a trial with a month left, a line with no expiry, a line paired
	 * with the expired one, a device line of each kind that has no device, and
	 * a line without a name. Each line has one entry in the logs.
	 */
	private function panel(): void {
		$rAgo = time() - 60;
		$rLater = time() + 30 * 86400;
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `exp_date`, `is_trial`, `is_mag`, `is_e2`, `pair_id`) VALUES"
			. " (10, 'expired', 'secret', $rAgo, 0, 0, 0, NULL), (11, 'expiredtrial', 'secret', $rAgo, 1, 0, 0, NULL), (12, 'trial', 'secret', $rLater, 1, 0, 0, NULL), (13, 'noexpiry', 'secret', NULL, 0, 0, 0, NULL), (14, 'paired', 'secret', $rLater, 0, 0, 0, 10),"
			. " (20, 'magexpired', 'secret', $rAgo, 0, 1, 0, NULL), (21, 'magexpiredtrial', 'secret', $rAgo, 1, 1, 0, NULL), (22, 'mag', 'secret', $rLater, 0, 1, 0, NULL), (23, 'magunlinked', 'secret', $rLater, 0, 1, 0, NULL),"
			. " (30, 'e2expired', 'secret', $rAgo, 0, 0, 1, NULL), (31, 'e2expiredtrial', 'secret', $rAgo, 1, 0, 1, NULL), (32, 'e2', 'secret', $rLater, 0, 0, 1, NULL), (33, 'e2unlinked', 'secret', $rLater, 0, 0, 1, NULL),"
			. ' (40, NULL, NULL, NULL, 0, 0, 0, NULL)');
		$this->rDb->exec("INSERT INTO `mag_devices` (`user_id`, `mac`) VALUES (20, '00:1A:79:00:00:20'), (21, '00:1A:79:00:00:21'), (22, '00:1A:79:00:00:22')");
		$this->rDb->exec("INSERT INTO `enigma2_devices` (`user_id`, `mac`) VALUES (30, '00:1A:79:00:00:30'), (31, '00:1A:79:00:00:31'), (32, '00:1A:79:00:00:32')");
		$this->rDb->exec('INSERT INTO `lines_logs` (`user_id`, `client_status`) SELECT `id`, \'USER_EXPIRED\' FROM `lines`');
	}

	/**
	 * The branch of the quick tools that $rTool selects, run as post.php holds
	 * it, up to its answer: post.php ends the request itself. What is run is
	 * this repository's own source, with the imports of the file it is in.
	 */
	private function tool(string $rTool): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/post.php');
		preg_match_all('/^use [^;]+;$/m', $rSource, $rImports);
		$this->assertSame(1, preg_match('/^\t+if \(isset\(\$rData\[\'' . $rTool . '\'\]\)\) \{\n(.*?)^\t+echo json_encode/ms', $rSource, $rBranch), $rTool);

		$db = $GLOBALS['db'];
		eval(implode("\n", $rImports[0]) . "\n" . $rBranch[1]);
	}

	/** @return list<int> the values of an integer column, in order */
	private function column(string $rQuery): array {
		$this->rDb->query($rQuery);
		$rOut = array_map('intval', $this->rDb->get_column());
		sort($rOut);
		return $rOut;
	}

	/** @return list<int> */
	private function closed(): array {
		$rClosed = array_values(array_unique($this->rClosed));
		sort($rClosed);
		return $rClosed;
	}

	/** @return array<string, array{0: string, 1: list<int>}> the tool, and the lines it removes */
	public static function tools(): array {
		return [
			'lines without a name' => ['remove_null_lines', [self::NAMELESS]],
			'expired lines' => ['remove_expired', [self::EXPIRED, self::EXPIRED_TRIAL]],
			'expired trial lines' => ['remove_expired_trial', [self::EXPIRED_TRIAL]],
			'expired MAG devices' => ['remove_expired_mag', [self::MAG_EXPIRED, self::MAG_EXPIRED_TRIAL]],
			'expired trial MAG devices' => ['remove_expired_trial_mag', [self::MAG_EXPIRED_TRIAL]],
			'expired Enigma2 devices' => ['remove_expired_e2', [self::ENIGMA_EXPIRED, self::ENIGMA_EXPIRED_TRIAL]],
			'expired trial Enigma2 devices' => ['remove_expired_trial_e2', [self::ENIGMA_EXPIRED_TRIAL]],
			'MAG lines without a device' => ['purge_unlinked_lines_mag', [self::MAG_UNLINKED]],
			'Enigma2 lines without a device' => ['purge_unlinked_lines_e2', [self::ENIGMA_UNLINKED]],
		];
	}

	/** @param list<int> $rRemoved */
	#[DataProvider('tools')]
	public function testALineRemovedByTheToolLeavesAsLinesDo(string $rTool, array $rRemoved): void {
		$this->panel();
		$rKept = array_values(array_diff(self::LINES, $rRemoved));

		$this->tool($rTool);

		$this->assertSame($rKept, $this->column('SELECT `id` FROM `lines`'), 'the lines the rule names are removed, no other');
		$this->assertSame(array_values(array_diff(self::MAG_DEVICES, $rRemoved)), $this->column('SELECT `user_id` FROM `mag_devices`'));
		$this->assertSame(array_values(array_diff(self::ENIGMA_DEVICES, $rRemoved)), $this->column('SELECT `user_id` FROM `enigma2_devices`'));
		$this->assertSame($rRemoved, $this->closed(), 'their sessions are read to be closed');
		$this->assertSame($rKept, $this->column('SELECT `user_id` FROM `lines_logs`'), 'their logs go with them');
		$this->rDb->query('SELECT `custom_data` FROM `signals` WHERE `server_id` = 1 AND `cache` = 1 ORDER BY `signal_id` LIMIT 1');
		$this->assertEquals(['type' => 'update_lines', 'id' => $rRemoved], json_decode((string) $this->rDb->get_col(), true), 'the cache drops them');
	}

	public function testALinePairedWithARemovedOneIsPairedWithNone(): void {
		$this->panel();

		$this->tool('remove_expired');

		$this->rDb->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?', self::PAIRED);
		$this->assertNull($this->rDb->get_row()['pair_id']);
	}

	/** @param list<int> $rRemoved */
	#[DataProvider('tools')]
	public function testTheToolAnswersWhenItsRuleNamesNoLine(string $rTool, array $rRemoved): void {
		$this->panel();
		$this->rDb->exec('DELETE FROM `lines` WHERE `id` IN (' . implode(',', $rRemoved) . ')');
		$rKept = array_values(array_diff(self::LINES, $rRemoved));

		$this->tool($rTool);

		$this->assertSame($rKept, $this->column('SELECT `id` FROM `lines`'));
		$this->assertSame([], $this->closed());
		$this->assertSame(array_values(array_diff(self::MAG_DEVICES, $rRemoved)), array_values(array_diff($this->column('SELECT `user_id` FROM `mag_devices`'), $rRemoved)));
		$this->assertSame(array_values(array_diff(self::ENIGMA_DEVICES, $rRemoved)), array_values(array_diff($this->column('SELECT `user_id` FROM `enigma2_devices`'), $rRemoved)));
	}
}
