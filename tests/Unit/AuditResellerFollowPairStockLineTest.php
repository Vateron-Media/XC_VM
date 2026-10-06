<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A line paired with another takes that line's term when it is saved. The
 * line of an activation code nobody has redeemed has no term yet: it has no
 * expiry and is kept switched off until the code starts its countdown. So a
 * reseller's line or device is not paired with such a line, and keeps the
 * term it was bought with. Once the code is redeemed its line is a line like
 * any other, and is paired with as one.
 */
final class AuditResellerFollowPairStockLineTest extends TestCase {
	private const RESELLER = 5;
	private const PACKAGE = 1;

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'mag_devices', 'enigma2_devices', 'activation_codes', 'access_codes', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`) VALUES (2, 'Resellers', 1)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll(), 'host' => $_SERVER['HTTP_HOST'] ?? null];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0, 'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443]];
		$GLOBALS['rSettings'] = ['disable_trial' => 0, 'keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'redis_handler' => 0];
		$_SERVER['HTTP_HOST'] = 'panel.test';
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
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
		foreach ([BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		ResellerAPI::$rSettings = [];
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		if ($this->rBefore['host'] === null) {
			unset($_SERVER['HTTP_HOST']);
		} else {
			$_SERVER['HTTP_HOST'] = $this->rBefore['host'];
		}
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The reseller buys one code: its row. Its line is the first line there is. */
	private function buyCode(): array {
		$rResult = ActiveCodeService::generateCodes(['package_id' => self::PACKAGE, 'num_codes' => 1], UserRepository::getRegisteredUserById(self::RESELLER) + ['reports' => [self::RESELLER]], false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		return ActiveCodeService::getByCode($rResult['codes'][0]['code']);
	}

	private function lines(): int {
		$this->rDb->query('SELECT COUNT(*) FROM `lines`');
		return (int) $this->rDb->get_col();
	}

	/**
	 * The reseller's form for $rKind with $rForm filled in, saved: the status
	 * the panel answers with.
	 *
	 * @param array<string, mixed> $rForm
	 */
	private function save(string $rKind, array $rForm): int {
		$rForm += ['reseller_notes' => '', 'mac' => '00:1A:79:00:00:0A'];
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => (isset($rForm['edit']) ? '' : 'viewer' . $this->lines()), 'password' => (isset($rForm['edit']) ? '' : 'secret'), 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/** The reseller saves a line again as it is: the lines paired with it take what it holds. */
	private function saveAgain(int $rLineID): void {
		$this->assertSame(STATUS_SUCCESS, $this->save('line', ['edit' => (string) $rLineID]));
	}

	/** @return array<string, mixed> what line $rLineID holds of a pair and of a term */
	private function held(int $rLineID): array {
		$this->rDb->query('SELECT `pair_id`, `exp_date`, `enabled`, `is_activecode` FROM `lines` WHERE `id` = ?', $rLineID);
		return $this->rDb->get_row();
	}

	/** @return array<string, array{0: string}> */
	public static function kinds(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	/** @return array<string, array{0: string}> */
	public static function devices(): array {
		return ['a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	#[DataProvider('kinds')]
	public function testASubscriptionIsNotPairedWithTheLineOfACodeNobodyRedeemed(string $rKind): void {
		$rStock = (int) $this->buyCode()['subscriber_id'];

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['package' => self::PACKAGE, 'pair_id' => (string) $rStock]));
		$this->saveAgain($rStock);

		$rHeld = $this->held($rStock + 1);
		$this->assertNull($rHeld['pair_id'], 'paired with nothing');
		$this->assertGreaterThan(time() + 27 * 86400, (int) $rHeld['exp_date'], 'the month it was bought with');
		$this->assertEquals(1, $rHeld['enabled']);
		$this->assertEquals(0, $rHeld['is_activecode']);
	}

	/** A device the reseller has, saved again with the line of such a code as its pair. */
	#[DataProvider('devices')]
	public function testADeviceThereIsIsNotPairedWithItEither(string $rKind): void {
		$rStock = (int) $this->buyCode()['subscriber_id'];
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['package' => self::PACKAGE]));
		$rTerm = (int) $this->held($rStock + 1)['exp_date'];

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => '1', 'pair_id' => (string) $rStock]));
		$this->saveAgain($rStock);

		$this->assertEquals(['pair_id' => null, 'exp_date' => $rTerm, 'enabled' => 1, 'is_activecode' => 0], $this->held($rStock + 1));
	}

	#[DataProvider('kinds')]
	public function testTheLineOfARedeemedCodeIsPairedWithAsAnyLine(string $rKind): void {
		$rCode = $this->buyCode();
		$rLine = (int) $rCode['subscriber_id'];
		$this->assertSame('SUCCESS', ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9'])['status']);

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['package' => self::PACKAGE, 'pair_id' => (string) $rLine]));

		$this->assertEquals($rLine, $this->held($rLine + 1)['pair_id']);
	}

	/** The request is read once for the three forms: the pair is dropped there. */
	#[DataProvider('kinds')]
	public function testTheRequestLosesAPairThatNamesSuchALine(string $rKind): void {
		$rStock = (int) $this->buyCode()['subscriber_id'];

		$this->assertArrayNotHasKey('pair_id', ResellerAPI::processData($rKind, ['pair_id' => (string) $rStock, 'package' => (string) self::PACKAGE]));
	}
}
