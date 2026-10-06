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
use XcVm\Tests\Support\QueryLogDb;

/**
 * What a reseller buys (a line, a MAG or Enigma2 device, a sub-reseller, a
 * batch of activation codes) is paid from the balance as it is stored when
 * the purchase is made: the price leaves the balance only if the balance
 * covers it, and nothing is sold when it does not. A reseller request reads
 * its account once, when it starts; that copy does not decide a purchase and
 * is never written back, so credits another request has spent or added since
 * stay spent or added, and a balance keeps its fraction. One price buys one
 * period: an extension is stored only while the line still has the expiry
 * the request read from it.
 */
final class AuditCreditsPurchaseTest extends TestCase {
	private const RESELLER = 5;
	private const PRICE = 10;
	private const SUB_RESELLER_PRICE = 4;

	/**
	 * One reseller request that buys a line, on the production database class:
	 * it reads the account as a request does when it starts, says so, and buys
	 * once it is told that every request has read the account. A request that
	 * extends a line (`edit`) reads that line as well before it says so: it
	 * waits as it goes to take the price.
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
		if ($this->rWait && str_contains($query, 'FOR UPDATE')) {
			$this->rWait = false;
			fwrite(STDOUT, "read\n");
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

if (isset($rIn['line']['edit'])) {
	$db->rWait = true;
} else {
	fwrite(STDOUT, "read\n");
	fgets(STDIN);
}

fwrite(STDOUT, (string) \XcVm\Domain\User\ResellerAPI::processLine($rIn['line'])['status']);
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
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `minimum_trial_credits`, `create_sub_resellers`, `create_sub_resellers_price`, `subresellers`) VALUES (2, 'Resellers', 1, 0, 0, 1, " . self::SUB_RESELLER_PRICE . ", '[2]')");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES (1, 'Month', 1, " . self::PRICE . ", 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 10, 0, '[]')");

		$this->use($this->rDb);
		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		ResellerAPI::$rSettings = ['mag_default_type' => 0];
		$this->startRequest();
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => [$rBefore]) {
			self::ownDb($rClass)->setValue(null, $rBefore);
		}
		DatabaseFactory::reset();
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		ResellerAPI::$rSettings = [];
		$GLOBALS['rSettings'] = $this->rSettings;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/**
	 * The database the code under test reaches, however it asks for one. Other
	 * tests give BouquetService and LineService a database of their own
	 * (setDb), which outlives them: they get this one for the test, and what
	 * they held is put back after it.
	 */
	private function use(XcVm\Core\Database\DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		DatabaseFactory::set($rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$this->rOwnDb[$rClass] ??= [self::ownDb($rClass)->getValue()];
			self::ownDb($rClass)->setValue(null, $rDb);
		}
	}

	/** @param class-string $rClass */
	private static function ownDb(string $rClass): ReflectionProperty {
		$rProperty = new ReflectionProperty($rClass, 'db');
		$rProperty->setAccessible(true);
		return $rProperty;
	}

	/** The reseller's request begins: it reads the account once, as every reseller request does. */
	private function startRequest(): void {
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'create_sub_resellers' => 1,
			'create_sub_resellers_price' => self::SUB_RESELLER_PRICE, 'subresellers' => [2], 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	/** Another request of the panel changes the balance while this one runs. */
	private function setBalance(float $rCredits): void {
		$this->rDb->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?', $rCredits, self::RESELLER);
	}

	private function balance(): float {
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		return (float) $this->rDb->get_col();
	}

	private function rows(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** One purchase of $rKind by the reseller: the status the panel answers with. */
	private function buy(string $rKind): int {
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine(['package' => 1, 'username' => 'viewer' . $this->rows('lines'), 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']),
			'mag' => ResellerAPI::processMAG(['package' => 1, 'mac' => '00:1A:79:00:00:0' . $this->rows('mag_devices'), 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '', 'reseller_notes' => '']),
			'enigma' => ResellerAPI::processEnigma(['package' => 1, 'mac' => '00:1A:79:00:00:0' . $this->rows('enigma2_devices'), 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '', 'reseller_notes' => '']),
			'user' => ResellerAPI::processUser(['username' => 'subreseller' . $this->rows('users'), 'password' => 'secret', 'email' => '', 'reseller_dns' => '', 'notes' => '', 'owner_id' => 0]),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/** How many of $rKind the panel holds: what was sold. */
	private function sold(string $rKind): int {
		return match ($rKind) {
			'line' => $this->rows('lines'),
			'mag' => $this->rows('mag_devices'),
			'enigma' => $this->rows('enigma2_devices'),
			'user' => $this->rows('users') - 1,
		};
	}

	private static function price(string $rKind): int {
		return $rKind === 'user' ? self::SUB_RESELLER_PRICE : self::PRICE;
	}

	/** @return array<string, array{0: string}> */
	public static function kinds(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma'], 'a sub-reseller' => ['user']];
	}

	// ── lines, devices and sub-resellers ────────────────────────────

	#[DataProvider('kinds')]
	public function testAPurchaseTakesItsPriceFromTheBalance(string $rKind): void {
		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind));

		$this->assertSame(1, $this->sold($rKind));
		$this->assertSame(10.0 - self::price($rKind), $this->balance());
		$this->rDb->query('SELECT `cost`, `credits_after` FROM `users_logs`');
		$this->assertEquals([['cost' => self::price($rKind), 'credits_after' => 10 - self::price($rKind)]], $this->rDb->get_rows());
	}

	/**
	 * The request read a balance that covers the price; another request of the
	 * same reseller has spent it since. Nothing is sold.
	 */
	#[DataProvider('kinds')]
	public function testNothingIsSoldForCreditsSpentSinceTheRequestStarted(string $rKind): void {
		$this->setBalance(0);

		$this->assertSame(STATUS_INSUFFICIENT_CREDITS, $this->buy($rKind));

		$this->assertSame(0, $this->sold($rKind));
		$this->assertSame(0.0, $this->balance());
		$this->assertSame(0, $this->rows('users_logs'));
	}

	/** Two purchases from one copy of the balance that covers one of them: one is sold. */
	#[DataProvider('kinds')]
	public function testOneBalanceIsNotSpentTwice(string $rKind): void {
		$this->setBalance(self::price($rKind));
		$this->startRequest();

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind));
		$this->assertSame(STATUS_INSUFFICIENT_CREDITS, $this->buy($rKind));

		$this->assertSame(1, $this->sold($rKind));
		$this->assertSame(0.0, $this->balance());
	}

	/**
	 * Two requests of one reseller, each on its own connection, read a balance
	 * that covers one line and then buy a line each, at the same moment.
	 */
	public function testTwoRequestsAtOnceBuyOneLine(): void {
		$rStatuses = $this->atOnce([
			['package' => 1, 'username' => 'viewer-first', 'password' => 'secret', 'contact' => '', 'reseller_notes' => ''],
			['package' => 1, 'username' => 'viewer-second', 'password' => 'secret', 'contact' => '', 'reseller_notes' => ''],
		]);

		$this->assertSame([STATUS_SUCCESS, STATUS_INSUFFICIENT_CREDITS], $rStatuses);
		$this->assertSame(1, $this->sold('line'));
		$this->assertSame(0.0, $this->balance());
	}

	/**
	 * Two requests of one reseller, each on its own connection, read a balance
	 * that covers two extensions and the same line, and then extend it at the
	 * same moment: the line gains one period, for one price.
	 */
	public function testTwoRequestsAtOnceExtendALineOnce(): void {
		$rExpires = time() + 86400;
		$this->sell('line', $rExpires);
		$rLine = ['edit' => $this->id('lines', 'id'), 'package' => 1, 'username' => 'viewer0', 'password' => 'secret', 'contact' => '', 'reseller_notes' => ''];

		$rStatuses = $this->atOnce([$rLine, $rLine]);

		$this->assertSame([STATUS_FAILURE, STATUS_SUCCESS], $rStatuses);
		$this->assertSame(strtotime('+1 months', $rExpires), $this->expires());
		$this->assertSame(10.0, $this->balance());
	}

	/**
	 * Requests of the reseller, one for each of $rLines and each on its own
	 * connection, that all buy once every one of them has read what it reads.
	 *
	 * @param list<array<string, mixed>> $rLines
	 * @return list<int> the statuses they answer with, sorted
	 */
	private function atOnce(array $rLines): array {
		$rScript = sys_get_temp_dir() . '/xcvm-credits-request-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($rScript, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::REQUEST));

		$rRequests = [];
		try {
			foreach ($rLines as $rLine) {
				$rIn = ['schema' => $this->rDb->schema(), 'reseller' => self::RESELLER, 'permissions' => ResellerAPI::$rPermissions, 'line' => $rLine];
				$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $rScript, (string) json_encode($rIn)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
				$this->assertIsResource($rProc);
				$rRequests[] = [$rProc, $rPipes];
			}
			// Every request has read the account, and the line it extends, before any of them buys.
			foreach ($rRequests as [, $rPipes]) {
				$this->assertSame("read\n", fgets($rPipes[1]));
			}
			foreach ($rRequests as [, $rPipes]) {
				fwrite($rPipes[0], "buy\n");
			}
			$rStatuses = [];
			foreach ($rRequests as [, $rPipes]) {
				$rStatuses[] = (int) stream_get_contents($rPipes[1]);
				$this->assertSame('', (string) stream_get_contents($rPipes[2]));
			}
		} finally {
			foreach ($rRequests as [$rProc, $rPipes]) {
				fclose($rPipes[0]);
				proc_close($rProc);
			}
			unlink($rScript);
		}

		sort($rStatuses);
		return $rStatuses;
	}

	/** Credits the reseller received since the request started stay on the balance. */
	#[DataProvider('kinds')]
	public function testAPurchaseKeepsCreditsAddedSinceTheRequestStarted(string $rKind): void {
		$this->setBalance(30);

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind));

		$this->assertSame(30.0 - self::price($rKind), $this->balance());
	}

	#[DataProvider('kinds')]
	public function testAPurchaseKeepsTheFractionOfTheBalance(string $rKind): void {
		$this->setBalance(10.75);
		$this->startRequest();

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind));

		$this->assertSame(10.75 - self::price($rKind), $this->balance());
	}

	/**
	 * The balance column is a FLOAT: it holds whole credits exactly up to
	 * 16 777 216 and is shown at six digits. A purchase is charged on what the
	 * column holds, and a balance too large to show the price still pays.
	 */
	public function testALargeBalanceIsChargedOnWhatItHolds(): void {
		$this->setBalance(1234567);
		$this->startRequest();

		$this->assertSame(STATUS_SUCCESS, $this->buy('line'));
		$this->rDb->query('SELECT ROUND(`credits`) FROM `users` WHERE `id` = ?', self::RESELLER);
		$this->assertSame('1234557', (string) (0 + $this->rDb->get_col()));

		$this->setBalance(1000000000);
		$this->startRequest();

		$this->assertSame(STATUS_SUCCESS, $this->buy('line'));
		$this->assertSame(2, $this->sold('line'));
	}

	/** The line could not be stored: the reseller keeps the credits. */
	#[DataProvider('kinds')]
	public function testAPurchaseThatCannotBeStoredCostsNothing(string $rKind): void {
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = $rKind === 'user' ? '/^INSERT INTO `users`/' : '/^REPLACE INTO `lines`/';
		$this->use($rLog);

		$this->assertSame(STATUS_FAILURE, $this->buy($rKind));

		$this->assertSame(0, $this->sold($rKind));
		$this->assertSame(10.0, $this->balance());
	}

	/**
	 * Saving a line that already has its package charges nothing: the balance
	 * is left as it is stored, whatever the request read when it started.
	 */
	public function testAnEditThatCostsNothingLeavesTheBalanceAlone(): void {
		$this->assertSame(STATUS_SUCCESS, $this->buy('line'));
		$this->startRequest();
		$this->setBalance(30.5);
		$this->rDb->query('SELECT `id` FROM `lines`');
		$rLineID = (int) $this->rDb->get_col();

		$rResult = ResellerAPI::processLine(['edit' => $rLineID, 'username' => 'viewer0', 'password' => 'secret', 'contact' => 'new contact', 'reseller_notes' => '']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(30.5, $this->balance());
		$this->rDb->query('SELECT `contact` FROM `lines` WHERE `id` = ?', $rLineID);
		$this->assertSame('new contact', $this->rDb->get_col());
	}

	// ── extensions ──────────────────────────────────────────────────

	/** @return array<string, array{0: string}> what has an expiry the reseller extends */
	public static function subscriptions(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	/** The reseller buys the package again for the one $rKind it has sold: the status the panel answers with. */
	private function extend(string $rKind): int {
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine(['edit' => $this->id('lines', 'id'), 'package' => 1, 'username' => 'viewer0', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']),
			'mag' => ResellerAPI::processMAG(['edit' => $this->id('mag_devices', 'mag_id'), 'package' => 1, 'mac' => '00:1A:79:00:00:00', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '', 'reseller_notes' => '']),
			'enigma' => ResellerAPI::processEnigma(['edit' => $this->id('enigma2_devices', 'device_id'), 'package' => 1, 'mac' => '00:1A:79:00:00:00', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '', 'reseller_notes' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	private function id(string $rTable, string $rColumn): int {
		$this->rDb->query('SELECT `' . $rColumn . '` FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** When the one line the panel holds expires: null for never. */
	private function expires(): ?int {
		$this->rDb->query('SELECT `exp_date` FROM `lines`');
		$rExpires = $this->rDb->get_col();
		return $rExpires === null ? null : (int) $rExpires;
	}

	/** The reseller has sold one $rKind and holds the price of two extensions; $rExpires is when it runs out. */
	private function sell(string $rKind, int|string $rExpires): void {
		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind));
		$this->rDb->exec('UPDATE `lines` SET `exp_date` = ' . $rExpires);
		$this->setBalance(2 * self::PRICE);
		$this->startRequest();
	}

	/** One price buys one period, added to the time the subscription has left. */
	#[DataProvider('subscriptions')]
	public function testAnExtensionAddsOnePeriodForOnePrice(string $rKind): void {
		$rExpires = time() + 86400;
		$this->sell($rKind, $rExpires);

		$this->assertSame(STATUS_SUCCESS, $this->extend($rKind));

		$this->assertSame(strtotime('+1 months', $rExpires), $this->expires());
		$this->assertSame(10.0, $this->balance());
		$this->assertSame(1, $this->rows('lines'));
	}

	/** A subscription that has run out starts its period now. */
	#[DataProvider('subscriptions')]
	public function testAnExtensionOfAnExpiredSubscriptionStartsNow(string $rKind): void {
		$this->sell($rKind, time() - 86400);

		$this->assertSame(STATUS_SUCCESS, $this->extend($rKind));

		$this->assertEqualsWithDelta(strtotime('+1 months'), $this->expires(), 5);
		$this->assertSame(10.0, $this->balance());
	}

	/** So does one that never runs out. */
	#[DataProvider('subscriptions')]
	public function testAnExtensionOfASubscriptionWithoutAnExpiryStartsNow(string $rKind): void {
		$this->sell($rKind, 'NULL');

		$this->assertSame(STATUS_SUCCESS, $this->extend($rKind));

		$this->assertEqualsWithDelta(strtotime('+1 months'), $this->expires(), 5);
		$this->assertSame(10.0, $this->balance());
	}

	/**
	 * An extension is priced on the expiry the request read from the line.
	 * Another request extends the same line, and pays, after this one has read
	 * it: this one sells nothing, so one period is not paid for twice.
	 */
	#[DataProvider('subscriptions')]
	public function testAnExtensionAnotherRequestHasJustSoldIsNotPaidForAgain(string $rKind): void {
		$rExpires = time() + 86400;
		$rExtended = strtotime('+1 months', $rExpires);
		$this->sell($rKind, $rExpires);

		$rLog = new QueryLogDb($this->rDb);
		$rOther = TestDb::connect($this->rDb->schema());
		$rLog->rBefore = static function (string $rQuery) use ($rLog, $rOther, $rExtended): void {
			if (str_contains($rQuery, 'FOR UPDATE')) {
				$rLog->rBefore = null;
				$rOther->exec('UPDATE `lines` SET `exp_date` = ' . $rExtended);
				$rOther->exec('UPDATE `users` SET `credits` = `credits` - ' . self::PRICE . ' WHERE `id` = ' . self::RESELLER);
			}
		};
		$this->use($rLog);

		$rStatus = $this->extend($rKind);

		$this->assertSame(10.0, $this->balance());
		$this->assertSame(STATUS_FAILURE, $rStatus);
		$this->assertSame($rExtended, $this->expires());
	}

	/** The extension could not be stored: the reseller keeps the credits, the line its expiry. */
	#[DataProvider('subscriptions')]
	public function testAnExtensionThatCannotBeStoredCostsNothing(string $rKind): void {
		$rExpires = time() + 86400;
		$this->sell($rKind, $rExpires);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^(REPLACE INTO|UPDATE) `lines`/';
		$this->use($rLog);

		$this->assertSame(STATUS_FAILURE, $this->extend($rKind));

		$this->assertSame(20.0, $this->balance());
		$this->assertSame($rExpires, $this->expires());
	}

	// ── activation codes ────────────────────────────────────────────

	/** @return array<string, mixed> what the reseller asks for: $rCount codes of the package */
	private static function codes(int $rCount): array {
		return ['package_id' => 1, 'num_codes' => $rCount];
	}

	public function testCodesTakeTheirPriceFromTheBalance(): void {
		$this->setBalance(25);
		$this->startRequest();

		$rResult = ActiveCodeService::generateCodes(self::codes(2), ResellerAPI::$rUserInfo, false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(2, $this->rows('activation_codes'));
		$this->assertSame(5.0, $this->balance());
	}

	public function testNoCodesAreSoldForCreditsSpentSinceTheRequestStarted(): void {
		$this->setBalance(0);

		$rResult = ActiveCodeService::generateCodes(self::codes(1), ResellerAPI::$rUserInfo, false);

		$this->assertSame('INSUFFICIENT_CREDITS', $rResult['status']);
		$this->assertSame(0, $this->rows('activation_codes'));
		$this->assertSame(0, $this->rows('lines'));
		$this->assertSame(0.0, $this->balance());
		$this->assertSame(0, $this->rows('users_credits_logs'));
	}

	public function testCodesKeepCreditsAddedSinceTheRequestStarted(): void {
		$this->setBalance(30.5);

		$rResult = ActiveCodeService::generateCodes(self::codes(1), ResellerAPI::$rUserInfo, false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(20.5, $this->balance());
	}

	/** A price with a fraction is paid by a balance that shows the same number. */
	public function testAFractionalPriceIsPaidByTheBalanceThatShowsIt(): void {
		$this->rDb->exec('UPDATE `users_packages` SET `official_credits` = 0.7');
		$this->setBalance(0.7);
		$this->startRequest();

		$rResult = ActiveCodeService::generateCodes(self::codes(1), ResellerAPI::$rUserInfo, false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		$this->assertSame('0', (string) (0 + $this->rDb->get_col()));
	}

	/** Three codes at 0.1 cost 0.3 (0.1 * 3 is 0.30000000000000004): a balance of 0.3 pays for them. */
	public function testCodesWhosePricesAddUpToTheBalanceArePaidByIt(): void {
		$this->rDb->exec('UPDATE `users_packages` SET `official_credits` = 0.1');
		$this->setBalance(0.3);
		$this->startRequest();

		$rResult = ActiveCodeService::generateCodes(self::codes(3), ResellerAPI::$rUserInfo, false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0.3, $rResult['total_cost']);
		$this->assertSame(3, $this->rows('activation_codes'));
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		$this->assertSame('0', (string) (0 + $this->rDb->get_col()));
		$this->rDb->query('SELECT `amount` FROM `users_credits_logs`');
		$this->assertEquals([['amount' => -0.3]], $this->rDb->get_rows());
	}

	/** One code more than the balance pays for is still refused. */
	public function testCodesWhosePricesAddUpToMoreThanTheBalanceAreNotSold(): void {
		$this->rDb->exec('UPDATE `users_packages` SET `official_credits` = 0.1');
		$this->setBalance(0.3);
		$this->startRequest();

		$rResult = ActiveCodeService::generateCodes(self::codes(4), ResellerAPI::$rUserInfo, false);

		$this->assertSame('INSUFFICIENT_CREDITS', $rResult['status']);
		$this->assertSame(0, $this->rows('activation_codes'));
		$this->assertSame(0.3, $this->balance());
	}
}
