<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * The subscription an activation code carries starts when the code is
 * redeemed. Until then its line has no expiry, so it is kept switched off:
 * generating the code, enabling it or editing it does not switch the line on,
 * and neither does the reseller's own line switch, nor the switch of a device
 * paired with the line. Redeeming the code does, in the statement that starts
 * the countdown, and the line's cached copy follows. Whether a code was
 * redeemed is read on the code: a redeemed code's line that an administrator
 * gave no expiry is a line like any other. A code generated before this rule
 * keeps the line it has.
 */
final class AuditActiveCodeStockLineTest extends TestCase {
	private const RESELLER = 5;

	private TestDb $rDb;

	/** @var array<string, mixed> the reseller's account as its request read it */
	private array $rReseller;

	/** @var array<string, mixed> */
	private array $rAdmin = ['id' => 1, 'member_group_id' => 1];

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);
		defined('LINES_TMP_PATH') || define('LINES_TMP_PATH', dirname(__DIR__) . '/.tmp/lines/');
		is_dir(LINES_TMP_PATH) || mkdir(LINES_TMP_PATH, 0775, true);

		$this->rDb = new TestDb();
		foreach (['users', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'mag_devices', 'enigma2_devices', 'activation_codes', 'access_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll(), 'host' => $_SERVER['HTTP_HOST'] ?? null];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0, 'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443]];
		$GLOBALS['rSettings'] = ['keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'redis_handler' => 0];
		$_SERVER['HTTP_HOST'] = 'panel.test';
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
		$this->rReseller = UserRepository::getRegisteredUserById(self::RESELLER) + ['reports' => [self::RESELLER]];
		$GLOBALS['rUserInfo'] = $this->rReseller;
		$GLOBALS['rPermissions'] = ['create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => []];
	}

	protected function tearDown(): void {
		foreach ([LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		if ($this->rBefore['host'] === null) {
			unset($_SERVER['HTTP_HOST']);
		} else {
			$_SERVER['HTTP_HOST'] = $this->rBefore['host'];
		}
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The reseller buys one code: its row. */
	private function buy(): array {
		$rResult = ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 1], $this->rReseller, false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		return ActiveCodeService::getByCode($rResult['codes'][0]['code']);
	}

	/** A code as the panel generated them before: its line switched on from the start. */
	private function boughtBefore(): array {
		$rCode = $this->buy();
		$this->rDb->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?', $rCode['subscriber_id']);
		return $rCode;
	}

	/** @return array<string, mixed> the code's line as stored */
	private function line(array $rCode): array {
		return UserRepository::getLineById($rCode['subscriber_id']);
	}

	private function codeStatus(array $rCode): int {
		return (int) ActiveCodeService::getById((int) $rCode['id'])['status'];
	}

	public function testTheLineOfANewCodeIsOffUntilTheCodeIsRedeemed(): void {
		$rCode = $this->buy();

		$rLine = $this->line($rCode);
		$this->assertNull($rLine['exp_date'], 'the countdown has not started');
		$this->assertSame(0, (int) $rLine['enabled'], 'and the line does not play before it has');

		$rResult = ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message'] ?? '');
		$rLine = $this->line($rCode);
		$this->assertSame(1, (int) $rLine['enabled']);
		$this->assertGreaterThan(time() + 27 * 86400, (int) $rLine['exp_date']);
		$this->assertSame(1, (int) $rResult['line']['enabled'], 'the line handed to the caller is the one stored');
		$this->assertSame((int) $rLine['exp_date'], (int) $rResult['line']['exp_date']);
	}

	/** The cache held the line as it was before the code was redeemed. */
	public function testRedeemingTheCodeRefreshesTheCachedLine(): void {
		$rCode = $this->buy();
		SettingsManager::set(['enable_cache' => 1, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);
		$rCached = LINES_TMP_PATH . 'line_i_' . $rCode['subscriber_id'];
		file_put_contents($rCached, igbinary_serialize($this->line($rCode)));

		ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);

		$rLeft = file_exists($rCached);
		@unlink($rCached);
		$this->assertFalse($rLeft, 'the next read takes the line from the database');
		$this->rDb->query('SELECT `custom_data` FROM `signals` WHERE `server_id` = 1 AND `cache` = 1');
		$this->assertSame([['type' => 'update_line', 'id' => (int) $rCode['subscriber_id']]], array_map(static fn(string $rJob): array => json_decode($rJob, true), $this->rDb->get_column()));
	}

	public function testEnablingACodeNobodyRedeemedLeavesItsLineOff(): void {
		$rCode = $this->buy();

		foreach ([[$this->rReseller, false], [$this->rAdmin, true]] as [$rUser, $rIsAdmin]) {
			ActiveCodeService::massAction('disable', [$rCode['id']], $rUser, $rIsAdmin);
			$rResult = ActiveCodeService::massAction('enable', [$rCode['id']], $rUser, $rIsAdmin);

			$this->assertSame('SUCCESS', $rResult['status']);
			$this->assertSame(1, $this->codeStatus($rCode), 'the code is back in stock');
			$this->assertSame(0, (int) $this->line($rCode)['enabled']);
		}
	}

	public function testEnablingARedeemedCodeSwitchesItsLineBackOn(): void {
		$rCode = $this->buy();
		ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);
		ActiveCodeService::massAction('disable', [$rCode['id']], $this->rReseller, false);
		$this->assertSame(0, (int) $this->line($rCode)['enabled']);

		ActiveCodeService::massAction('enable', [$rCode['id']], $this->rReseller, false);

		$this->assertSame(2, $this->codeStatus($rCode));
		$this->assertSame(1, (int) $this->line($rCode)['enabled']);
	}

	/** The administrator's enable still lifts its own lock from the line. */
	public function testAnAdministratorsEnableStillLiftsTheLock(): void {
		$rCode = $this->buy();
		$this->rDb->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?', $rCode['subscriber_id']);

		ActiveCodeService::massAction('enable', [$rCode['id']], $this->rAdmin, true);

		$rLine = $this->line($rCode);
		$this->assertSame(1, (int) $rLine['admin_enabled']);
		$this->assertSame(0, (int) $rLine['enabled']);
	}

	public function testEditingACodeNobodyRedeemedLeavesItsLineOff(): void {
		$rCode = $this->buy();

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['batch_name' => 'Spring', 'status' => 2], $this->rAdmin, true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0, (int) $this->line($rCode)['enabled']);
	}

	/** An administrator who gives the line an expiry by hand has started its countdown. */
	public function testAnEditThatSetsAnExpirySwitchesTheLineOn(): void {
		$rCode = $this->buy();
		$rExpires = time() + 7 * 86400;

		ActiveCodeService::updateCode((int) $rCode['id'], ['exp_date' => (string) $rExpires], $this->rAdmin, true);

		$rLine = $this->line($rCode);
		$this->assertSame($rExpires, (int) $rLine['exp_date']);
		$this->assertSame(1, (int) $rLine['enabled']);
	}

	public function testACodeGeneratedBeforeKeepsItsLineUntilItIsDisabled(): void {
		$rCode = $this->boughtBefore();

		ActiveCodeService::updateCode((int) $rCode['id'], ['batch_name' => 'Spring'], $this->rAdmin, true);
		$this->assertSame(1, (int) $this->line($rCode)['enabled'], 'an edit leaves it as it was');

		ActiveCodeService::massAction('enable', [$rCode['id']], $this->rReseller, false);
		$this->assertSame(1, (int) $this->line($rCode)['enabled'], 'and so does enabling a code that was not disabled');

		$rResult = ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);
		$this->assertSame('SUCCESS', $rResult['status']);
		$this->assertSame(1, (int) $this->line($rCode)['enabled']);
		$this->assertNotNull($this->line($rCode)['exp_date']);
	}

	/** The reseller's line switch (API and panel) leaves such a line to its code. */
	public function testTheLineSwitchLeavesALineThatAwaitsItsCodeOff(): void {
		$rCode = $this->buy();
		$rLine = (int) $rCode['subscriber_id'];
		$this->assertTrue(ActiveCodeService::lineAwaitsRedemption($this->line($rCode)));
		$this->assertFalse(ActiveCodeService::lineAwaitsRedemption(['is_activecode' => 0, 'exp_date' => null]), 'a line without a code may have no expiry');

		$rKept = ResellerAPIWrapper::$db;
		ResellerAPIWrapper::$db = $this->rDb;
		try {
			$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::enableLine($rLine));
			$this->assertSame(0, (int) $this->line($rCode)['enabled']);

			ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);
			$this->assertFalse(ActiveCodeService::lineAwaitsRedemption($this->line($rCode)), 'redeemed: a line like any other');
			$this->rDb->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?', $rLine);
			$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableLine($rLine));
			$this->assertSame(1, (int) $this->line($rCode)['enabled']);
		} finally {
			ResellerAPIWrapper::$db = $rKept;
		}
	}

	/** A redeemed code whose line an administrator gave no expiry: the code's row. */
	private function redeemedWithoutExpiry(): array {
		$rCode = $this->buy();
		$this->assertSame('SUCCESS', ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9'])['status']);
		$this->rDb->query('UPDATE `lines` SET `exp_date` = NULL WHERE `id` = ?', $rCode['subscriber_id']);
		return $rCode;
	}

	/** No expiry on the line of a redeemed code is an administrator's grant, not a code in stock. */
	public function testEnablingARedeemedCodeSwitchesOnALineThatHasNoExpiry(): void {
		$rCode = $this->redeemedWithoutExpiry();

		ActiveCodeService::massAction('disable', [$rCode['id']], $this->rReseller, false);
		$this->assertSame(0, (int) $this->line($rCode)['enabled']);
		ActiveCodeService::massAction('enable', [$rCode['id']], $this->rReseller, false);

		$this->assertSame(2, $this->codeStatus($rCode));
		$this->assertSame(1, (int) $this->line($rCode)['enabled'], 'enabled from the list');
		$this->assertNull($this->line($rCode)['exp_date']);

		ActiveCodeService::updateCode((int) $rCode['id'], ['status' => 0], $this->rAdmin, true);
		$this->assertSame(0, (int) $this->line($rCode)['enabled']);
		ActiveCodeService::updateCode((int) $rCode['id'], ['status' => 2], $this->rAdmin, true);

		$this->assertSame(1, (int) $this->line($rCode)['enabled'], 'enabled by an edit');
	}

	public function testTheLineSwitchSwitchesOnARedeemedCodesLineThatHasNoExpiry(): void {
		$rCode = $this->redeemedWithoutExpiry();
		$rLine = (int) $rCode['subscriber_id'];
		$this->rDb->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?', $rLine);
		$this->assertFalse(ActiveCodeService::lineAwaitsRedemption($this->line($rCode)));

		$rKept = ResellerAPIWrapper::$db;
		ResellerAPIWrapper::$db = $this->rDb;
		try {
			$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableLine($rLine));
		} finally {
			ResellerAPIWrapper::$db = $rKept;
		}
		$this->assertSame(1, (int) $this->line($rCode)['enabled']);
	}

	/**
	 * A device of the reseller whose own line is on with a month left, paired
	 * with the line $rPair.
	 *
	 * @return array{0: int, 1: int} the device's id and its line's id
	 */
	private function pairedDevice(string $rTable, int $rPair): array {
		$this->rDb->query('INSERT INTO `lines` (`member_id`, `username`, `password`, `exp_date`, `enabled`, `is_mag`, `is_e2`, `pair_id`, `bouquet`, `allowed_outputs`) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)', self::RESELLER, 'device_' . $rTable, 'secret', time() + 30 * 86400, (int) ($rTable === 'mag_devices'), (int) ($rTable === 'enigma2_devices'), $rPair, '[]', '[1]');
		$rLine = (int) $this->rDb->last_insert_id();
		$this->rDb->query('INSERT INTO `' . $rTable . '` (`user_id`, `mac`) VALUES (?, ?)', $rLine, '00:1A:79:00:00:0' . $rLine);
		return [(int) $this->rDb->last_insert_id(), $rLine];
	}

	/**
	 * A line paired with another takes that line's state when it is saved. One
	 * paired with the line of a code in stock is then off with no expiry, as
	 * that line is, and the device's switch leaves it so.
	 */
	public function testTheDeviceSwitchesLeaveALineThatAwaitsACodeOff(): void {
		$rCode = $this->buy();
		$rStock = (int) $rCode['subscriber_id'];
		[$rMag, $rMagLine] = $this->pairedDevice('mag_devices', $rStock);
		[$rEnigma, $rEnigmaLine] = $this->pairedDevice('enigma2_devices', $rStock);
		MagService::syncLineDevices($rStock);
		foreach ([$rMagLine, $rEnigmaLine] as $rLine) {
			$rStored = UserRepository::getLineById($rLine);
			$this->assertNull($rStored['exp_date'], 'the paired line took the expiry of the line in stock: none');
			$this->assertSame(0, (int) $rStored['enabled']);
		}

		$rKept = ResellerAPIWrapper::$db;
		ResellerAPIWrapper::$db = $this->rDb;
		try {
			$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::enableMAG($rMag));
			$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::enableEnigma($rEnigma));
			$this->assertSame(0, (int) UserRepository::getLineById($rMagLine)['enabled']);
			$this->assertSame(0, (int) UserRepository::getLineById($rEnigmaLine)['enabled']);

			// Redeemed, the code's line has its term and the paired lines take it.
			ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);
			MagService::syncLineDevices($rStock);
			$this->rDb->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` IN (?, ?)', $rMagLine, $rEnigmaLine);
			$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableMAG($rMag));
			$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::enableEnigma($rEnigma));
			$this->assertSame(1, (int) UserRepository::getLineById($rMagLine)['enabled']);
			$this->assertSame(1, (int) UserRepository::getLineById($rEnigmaLine)['enabled']);
		} finally {
			ResellerAPIWrapper::$db = $rKept;
		}
	}

	/** The panel's three switches (line, MAG, Enigma2) answer and exit: they are read, not run. */
	public function testEveryPanelSwitchAsksBeforeItSwitchesALineOn(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Infrastructure/ResellerApiDispatcher.php');

		$this->assertSame(3, preg_match_all('/if \(\$rSub == \'enable\'\) \{(.*?)UPDATE `lines` SET `enabled` = 1/s', $rSource, $rSwitches));
		foreach ($rSwitches[1] as $rSwitch) {
			$this->assertStringContainsString('if (ActiveCodeService::lineAwaitsRedemption(', $rSwitch);
		}
	}
}
