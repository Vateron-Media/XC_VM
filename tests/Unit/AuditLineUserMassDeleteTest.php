<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\LineRepository;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * Lines deleted together lose their live sessions, as a line deleted on its
 * own does: every one of them, switched on or not. A line that was only
 * paired with a deleted one stays, unpaired, and keeps its sessions.
 */
final class AuditLineUserMassDeleteTest extends TestCase {
	private const ON = 7;
	private const OFF = 8;
	private const PAIRED = 9;

	private TestDb $rDb;

	/** @var list<int> the lines whose live sessions were read to be closed */
	private array $rClosed = [];

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['lines', 'lines_live', 'lines_logs', 'lines_activity', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `enabled`, `admin_enabled`, `exp_date`, `pair_id`) VALUES"
			. " (7, 'on', 'secret', 1, 1, " . (time() + 86400) . ", NULL), (8, 'off', 'secret', 0, 1, NULL, NULL), (9, 'paired', 'secret', 1, 1, NULL, 7)");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

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

	/** @return list<int> the lines the panel holds */
	private function lines(): array {
		$this->rDb->query('SELECT `id` FROM `lines` ORDER BY `id`');
		return array_map('intval', $this->rDb->get_column());
	}

	/** @return list<int> */
	private function closed(): array {
		$rClosed = array_values(array_unique($this->rClosed));
		sort($rClosed);
		return $rClosed;
	}

	public function testEveryDeletedLineLosesItsSessions(): void {
		$this->assertTrue(LineRepository::deleteMany([self::ON, self::OFF]));

		$this->assertSame([self::PAIRED], $this->lines());
		$this->assertSame([self::ON, self::OFF], $this->closed());
	}

	public function testALineThatWasPairedWithADeletedOneKeepsItsSessions(): void {
		LineRepository::deleteMany([self::ON]);

		$this->rDb->query('SELECT `pair_id` FROM `lines` WHERE `id` = ?', self::PAIRED);
		$this->assertNull($this->rDb->get_row()['pair_id']);
		$this->assertSame([self::ON], $this->closed());
	}

	public function testTheSessionsAreClosedWhateverTheSettingForSwitchedOffLines(): void {
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 0]);

		LineRepository::deleteMany([self::ON, self::OFF]);

		$this->assertSame([self::ON, self::OFF], $this->closed());
	}

	public function testALineDeletedThroughTheMassFormLosesItsSessions(): void {
		defined('STATUS_SUCCESS') || define('STATUS_SUCCESS', XcVm\Core\Config\ConstantsInitializer::statuses()['STATUS_SUCCESS']);

		LineService::massDelete(['lines' => json_encode([self::ON])]);

		$this->assertSame([self::OFF, self::PAIRED], $this->lines());
		$this->assertSame([self::ON], $this->closed());
	}

	// ── the administrator's tools that remove every trial ───────────

	private const TRIAL_LINE = 20;
	private const TRIAL_MAG = 21;
	private const TRIAL_ENIGMA = 22;
	private const MAG = 23;

	/** The panel has its device tables, empty. */
	private function deviceTables(): void {
		foreach (['mag_devices', 'enigma2_devices'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
	}

	/** A trial line, a trial MAG device and a trial Enigma2 device, each switched on and not expired, and a MAG device that is no trial. */
	private function trials(): void {
		$this->deviceTables();
		$rLater = time() + 86400;
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `enabled`, `admin_enabled`, `exp_date`, `is_trial`, `is_mag`, `is_e2`) VALUES"
			. " (20, 'trial', 'secret', 1, 1, $rLater, 1, 0, 0), (21, 'trialmag', 'secret', 1, 1, $rLater, 1, 1, 0), (22, 'trialenigma', 'secret', 1, 1, $rLater, 1, 0, 1), (23, 'mag', 'secret', 1, 1, $rLater, 0, 1, 0)");
		$this->rDb->exec("INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (1, 21, '00:1A:79:00:00:01'), (2, 23, '00:1A:79:00:00:02')");
		$this->rDb->exec("INSERT INTO `enigma2_devices` (`device_id`, `user_id`, `mac`) VALUES (1, 22, '00:1A:79:00:00:03')");
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

	/** @return list<int> the lines that have a device of $rTable */
	private function devices(string $rTable): array {
		$this->rDb->query('SELECT `user_id` FROM `' . $rTable . '` ORDER BY `user_id`');
		return array_map('intval', $this->rDb->get_column());
	}

	/** @return array<string, array{0: string, 1: int}> the tool, and the trial it removes */
	public static function trialTools(): array {
		return ['of lines' => ['remove_trial', self::TRIAL_LINE], 'of MAG devices' => ['remove_trial_mag', self::TRIAL_MAG], 'of Enigma2 devices' => ['remove_trial_e2', self::TRIAL_ENIGMA]];
	}

	#[DataProvider('trialTools')]
	public function testATrialRemovedByTheToolLosesItsSessions(string $rTool, int $rTrial): void {
		$this->trials();

		$this->tool($rTool);

		$this->assertSame(array_values(array_diff([self::ON, self::OFF, self::PAIRED, self::TRIAL_LINE, self::TRIAL_MAG, self::TRIAL_ENIGMA, self::MAG], [$rTrial])), $this->lines());
		$this->assertSame(array_values(array_diff([self::TRIAL_MAG, self::MAG], [$rTrial])), $this->devices('mag_devices'));
		$this->assertSame(array_values(array_diff([self::TRIAL_ENIGMA], [$rTrial])), $this->devices('enigma2_devices'));
		$this->assertSame([$rTrial], $this->closed());
	}

	/** @return array<string, array{0: string, 1: string}> the tool, and the table of the devices it removes */
	public static function deviceTools(): array {
		return ['of MAG devices' => ['remove_trial_mag', 'mag_devices'], 'of Enigma2 devices' => ['remove_trial_e2', 'enigma2_devices']];
	}

	/**
	 * The tool removes the devices of the lines it removes. A trial device
	 * another request makes while the tool runs is none of them: it keeps its
	 * device as it keeps its line.
	 */
	#[DataProvider('deviceTools')]
	public function testATrialDeviceMadeWhileTheToolRunsKeepsItsDevice(string $rTool, string $rTable): void {
		$this->trials();
		$rLog = $GLOBALS['db'];
		$rRecord = $rLog->rBefore;
		$rLog->rBefore = function (string $rQuery, array $rArgs) use ($rLog, $rRecord, $rTable): void {
			if (str_starts_with($rQuery, 'DELETE FROM `' . $rTable . '`')) {
				$rLog->rBefore = $rRecord;
				$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `password`, `is_trial`, `is_mag`, `is_e2`) VALUES (30, 'new', 'secret', 1, " . (int) ($rTable === 'mag_devices') . ', ' . (int) ($rTable === 'enigma2_devices') . ')');
				$this->rDb->exec('INSERT INTO `' . $rTable . "` (`user_id`, `mac`) VALUES (30, '00:1A:79:00:00:09')");
			}
			$rRecord($rQuery, $rArgs);
		};

		$this->tool($rTool);

		$this->assertContains(30, $this->lines());
		$this->assertContains(30, $this->devices($rTable));
	}

	#[DataProvider('trialTools')]
	public function testTheToolAnswersWhenThereIsNoTrial(string $rTool): void {
		$this->deviceTables();

		$this->tool($rTool);

		$this->assertSame([self::ON, self::OFF, self::PAIRED], $this->lines());
		$this->assertSame([], $this->closed());
	}
}
