<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A reseller group's trial allowance holds for requests made at the same
 * moment as it does for requests made one after another. A trial (a line, a
 * MAG or Enigma2 device, a trial activation code) is counted against the
 * allowance and then stored; a second request of the same reseller counts
 * after the first has stored its trial, so both count the same trials as
 * requests made in turn do. A request that is not for a trial waits for no one.
 */
final class AuditResellerCoreTrialAllowanceTest extends TestCase {
	private const RESELLER = 5;

	/**
	 * One reseller request for a trial, on the production database class.
	 * With `wait` it stops as it goes to store the trial's line, after the
	 * allowance was counted, says so, and stores once it is told to.
	 */
	private const REQUEST = <<<'PHP'
<?php
// xcvm_core's part in a connection: the test's schema.
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);
foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
	define($rName, $rValue);
}
define('SERVER_ID', 1);
$db = new class extends \XcVm\Core\Database\DatabaseHandler {
	public bool $rWait = false;

	public function query(string $query, mixed $buffered = false) {
		if ($this->rWait && preg_match('/^\s*(REPLACE|INSERT) INTO `lines`/', $query)) {
			$this->rWait = false;
			fwrite(STDOUT, "counted\n");
			fgets(STDIN);
		}
		return parent::query(...func_get_args());
	}
};
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);

$rSettings = ['disable_trial' => 0];
$rUserInfo = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['reseller']);
$rPermissions = $rIn['permissions'];
\XcVm\Domain\User\ResellerAPI::$rUserInfo = $rUserInfo;
\XcVm\Domain\User\ResellerAPI::$rPermissions = $rPermissions;
\XcVm\Domain\User\ResellerAPI::$rSettings = ['mag_default_type' => 0];
$db->rWait = $rIn['wait'];

$rResult = match ($rIn['kind']) {
	'line' => \XcVm\Domain\User\ResellerAPI::processLine($rIn['form']),
	'mag' => \XcVm\Domain\User\ResellerAPI::processMAG($rIn['form']),
	'enigma' => \XcVm\Domain\User\ResellerAPI::processEnigma($rIn['form']),
	'codes' => \XcVm\Domain\Line\ActiveCodeService::generateCodes($rIn['form'], $rUserInfo, false),
};
fwrite(STDOUT, (string) $rResult['status']);
PHP;

	private TestDb $rDb;

	/** @var array<class-string, array{0: mixed}> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

	/** The panel settings global as the test found it. */
	private mixed $rSettings;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'mag_devices', 'enigma2_devices', 'activation_codes', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		// One trial a day.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `minimum_trial_credits`) VALUES (2, 'Resellers', 1, 1, 'day', 0)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES"
			. " (1, 'Trial', 1, 0, 0, 0, 1, 'days', 0, NULL, '[2]', '[]', '[1]', 1, 1, 1, 0), (2, 'Month', 0, 1, 0, 10, 0, NULL, 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rProperty = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] = [$rProperty->getValue()];
			$rProperty->setValue(null, $this->rDb);
		}
		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		ResellerAPI::$rSettings = ['mag_default_type' => 0];
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => [$rBefore]) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $rBefore);
		}
		DatabaseFactory::reset();
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		ResellerAPI::$rSettings = [];
		$GLOBALS['rSettings'] = $this->rSettings;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/**
	 * What the reseller's $rNth request for a $rKind sends.
	 *
	 * @return array<string, mixed>
	 */
	private static function form(string $rKind, int $rNth, int $rPackage = 1): array {
		$rSale = ($rPackage == 1 ? ['trial' => 1] : []) + ['package' => $rPackage, 'reseller_notes' => ''];
		return match ($rKind) {
			'line' => $rSale + ['username' => 'viewer' . $rNth, 'password' => 'secret', 'contact' => ''],
			'mag' => $rSale + ['mac' => '00:1A:79:00:00:0' . $rNth, 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => ''],
			'enigma' => $rSale + ['mac' => '00:1A:79:00:00:0' . $rNth, 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => ''],
			'codes' => ['package_id' => $rPackage, 'num_codes' => 1],
		};
	}

	/** What a request for a $rKind answers when it made the trial, and when the allowance refused it. */
	private static function answers(string $rKind): array {
		return $rKind === 'codes' ? ['SUCCESS', 'ERROR'] : [(string) STATUS_SUCCESS, (string) STATUS_NO_TRIALS];
	}

	/** The reseller's request for a $rKind, in this process: what it answers. */
	private function ask(string $rKind, int $rNth, int $rPackage = 1): string {
		$rForm = self::form($rKind, $rNth, $rPackage);
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm),
			'mag' => ResellerAPI::processMAG($rForm),
			'enigma' => ResellerAPI::processEnigma($rForm),
			'codes' => ActiveCodeService::generateCodes($rForm, ResellerAPI::$rUserInfo, false),
		};
		return (string) $rResult['status'];
	}

	private function trials(): int {
		$this->rDb->query('SELECT COUNT(*) FROM `lines` WHERE `is_trial` = 1 AND `member_id` = ?', self::RESELLER);
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string}> what a reseller makes a trial of */
	public static function kinds(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma'], 'an activation code' => ['codes']];
	}

	#[DataProvider('kinds')]
	public function testTwoRequestsAtOnceMakeTheOneTrialAllowed(string $rKind): void {
		$rAnswers = $this->atOnce($rKind);

		$rExpected = self::answers($rKind);
		sort($rExpected);
		$this->assertSame(1, $this->trials());
		$this->assertSame($rExpected, $rAnswers);
	}

	#[DataProvider('kinds')]
	public function testRequestsOneAfterAnotherMakeTheOneTrialAllowed(string $rKind): void {
		$this->assertSame(self::answers($rKind), [$this->ask($rKind, 1), $this->ask($rKind, 2)]);

		$this->assertSame(1, $this->trials());
	}

	/** A request has answered: the next one of the reseller, on another connection, does not wait for it. */
	#[DataProvider('kinds')]
	public function testTheNextRequestDoesNotWaitForOneThatHasAnswered(string $rKind): void {
		$rOther = TestDb::connect($this->rDb->schema());
		$rFree = static fn(): int => (int) $rOther->query("SELECT IS_FREE_LOCK(CONCAT(DATABASE(), '.trials_" . self::RESELLER . "'))")->fetchColumn();

		$this->ask($rKind, 1);
		$this->assertSame(1, $rFree(), 'after a trial was made');

		$this->ask($rKind, 2);
		$this->assertSame(1, $rFree(), 'after a trial was refused');
	}

	/** A subscription is sold while another request of the reseller makes a trial. */
	#[DataProvider('kinds')]
	public function testARequestThatIsNotForATrialWaitsForNoOne(string $rKind): void {
		$rOther = TestDb::connect($this->rDb->schema());
		$this->assertSame(1, (int) $rOther->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.trials_" . self::RESELLER . "'), 0)")->fetchColumn());

		$rStarted = microtime(true);
		$this->assertSame(self::answers($rKind)[0], $this->ask($rKind, 1, 2));

		$this->assertLessThan(5.0, microtime(true) - $rStarted);
	}

	/**
	 * Two requests of the reseller for a trial $rKind, each on its own
	 * connection. The first has counted the allowance and not yet stored its
	 * trial when the second is made; it stores once the second has answered,
	 * or waits for it.
	 *
	 * @return list<string> what they answer, sorted
	 */
	private function atOnce(string $rKind): array {
		$rScript = sys_get_temp_dir() . '/xcvm-trial-request-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($rScript, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::REQUEST));

		$rRequests = [];
		$rStart = function (int $rNth, bool $rWait) use (&$rRequests, $rKind, $rScript): array {
			$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => ResellerAPI::$rPermissions, 'kind' => $rKind, 'form' => self::form($rKind, $rNth), 'wait' => $rWait];
			$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $rScript, (string) json_encode($rIn)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
			$this->assertIsResource($rProc);
			$rRequests[] = [$rProc, $rPipes];
			return $rPipes;
		};

		try {
			$rFirst = $rStart(1, true);
			$this->assertSame("counted\n", fgets($rFirst[1]));
			$rSecond = $rStart(2, false);

			for ($i = 0; $i < 80; $i++) {
				$rRead = [$rSecond[1]];
				$rNone = null;
				if (0 < stream_select($rRead, $rNone, $rNone, 0, 100000)) {
					break;
				}
				$this->rDb->query("SELECT COUNT(*) FROM `information_schema`.`PROCESSLIST` WHERE `DB` = ? AND `STATE` = 'User lock'", $this->rDb->schema());
				if (0 < (int) $this->rDb->get_col()) {
					break;
				}
			}
			fwrite($rFirst[0], "store\n");

			$rAnswers = [];
			foreach ($rRequests as [, $rPipes]) {
				$rAnswers[] = (string) stream_get_contents($rPipes[1]);
				$this->assertSame('', (string) stream_get_contents($rPipes[2]));
			}
		} finally {
			foreach ($rRequests as [$rProc, $rPipes]) {
				fclose($rPipes[0]);
				proc_close($rProc);
			}
			unlink($rScript);
		}

		sort($rAnswers);
		return $rAnswers;
	}
}
