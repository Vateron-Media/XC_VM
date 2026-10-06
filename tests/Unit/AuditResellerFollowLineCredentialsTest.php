<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A line's username and its password are each one segment of the line's
 * playback addresses (/live/<username>/<password>/<id>) and of the play
 * tokens built from them, so neither is set to a value that holds the
 * character that separates the segments: not by the administrator's line
 * form or API, not by a reseller's. A line that has such a value from before
 * is saved again as it is, and keeps the value until it is changed.
 */
final class AuditResellerFollowLineCredentialsTest extends TestCase {
	private const ADMIN = 1;
	private const RESELLER = 5;
	private const PACKAGE = 1;

	/** A line made before the rule, with the separator in both values. */
	private const BEFORE = 7;

	/** A line whose values hold no separator. */
	private const PLAIN = 8;

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`) VALUES (2, 'Resellers', 1)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `check_compatible`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (1, 'admin', 1, 0, 0, '[]'), (5, 'reseller', 2, 100, 0, '[]')");
		$rLater = time() + 30 * 86400;
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `enabled`, `admin_enabled`, `bouquet`, `allowed_outputs`, `package_id`, `created_at`) VALUES"
			. " (7, 5, 'old/name', 'old/pass', $rLater, 1, 1, '[]', '[1]', 1, 1700000000), (8, 5, 'plainname', 'plainpass', $rLater, 1, 1, '[]', '[1]', 1, 1700000000)");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
	}

	protected function tearDown(): void {
		foreach ([BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions']);
	}

	/**
	 * The line form of $rWho with $rForm filled in, saved: the status the
	 * panel answers with. The administrator's API and the reseller's save
	 * through the same two functions.
	 *
	 * @param array<string, mixed> $rForm
	 */
	private function save(string $rWho, array $rForm): int {
		if ($rWho === 'an administrator') {
			$GLOBALS['rUserInfo'] = ['id' => self::ADMIN, 'member_group_id' => 1];
			$GLOBALS['rAdminUserInfo'] = $GLOBALS['rUserInfo'];
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
			$rResult = LineService::process($rForm + ['member_id' => (string) self::RESELLER, 'bouquets_selected' => '[]', 'isp_clear' => '', 'exp_date' => date('Y-m-d', time() + 30 * 86400)]);
		} else {
			ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
			ResellerAPI::$rPermissions = [
				'create_line' => true, 'all_reports' => [], 'allow_change_username' => 1, 'allow_change_password' => 1,
				'minimum_username_length' => 4, 'minimum_password_length' => 4, 'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
			];
			$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
			$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
			$rResult = ResellerAPI::processLine($rForm + ['contact' => '', 'reseller_notes' => ''] + (isset($rForm['edit']) ? [] : ['package' => self::PACKAGE]));
		}
		$this->assertIsArray($rResult, $rWho);
		return $rResult['status'];
	}

	/** @return array<string, mixed> the line as stored, [] when there is none */
	private function line(int $rID): array {
		$this->rDb->query('SELECT `username`, `password`, `reseller_notes`, `admin_notes` FROM `lines` WHERE `id` = ?', $rID);
		return $this->rDb->get_raw_row() ?? [];
	}

	private function lines(): int {
		$this->rDb->query('SELECT COUNT(*) FROM `lines`');
		return (int) $this->rDb->get_col();
	}

	private function balance(): float {
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		return (float) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> who saves, the value that holds the separator, the status that refuses it */
	public static function refusals(): array {
		$rOut = [];
		foreach (['an administrator', 'a reseller'] as $rWho) {
			$rOut[$rWho . ', the username'] = [$rWho, 'username', 'STATUS_INVALID_USERNAME'];
			$rOut[$rWho . ', the password'] = [$rWho, 'password', 'STATUS_INVALID_PASSWORD'];
		}
		return $rOut;
	}

	#[DataProvider('refusals')]
	public function testNoLineIsMadeWithTheSeparatorInItsUsernameOrPassword(string $rWho, string $rField, string $rStatus): void {
		$rAnswer = $this->save($rWho, [$rField => 'view/er1'] + ['username' => 'viewer1', 'password' => 'secret1']);

		$this->assertSame(constant($rStatus), $rAnswer);
		$this->assertSame(2, $this->lines(), 'the two lines there were');
		$this->assertSame(100.0, $this->balance(), 'nothing was sold');
	}

	#[DataProvider('refusals')]
	public function testAnEditDoesNotGiveALineSuchAUsernameOrPassword(string $rWho, string $rField, string $rStatus): void {
		$rAnswer = $this->save($rWho, [$rField => 'plain/er'] + ['edit' => (string) self::PLAIN, 'username' => 'plainname', 'password' => 'plainpass']);

		$this->assertSame(constant($rStatus), $rAnswer);
		$this->assertSame(['username' => 'plainname', 'password' => 'plainpass'], array_slice($this->line(self::PLAIN), 0, 2));
	}

	/** @return array<string, array{0: string, 1: string}> who saves, and the note of the line that the save changes */
	public static function savers(): array {
		return ['an administrator' => ['an administrator', 'admin_notes'], 'a reseller' => ['a reseller', 'reseller_notes']];
	}

	/** The form posts the username and the password it showed: the ones the line has. */
	#[DataProvider('savers')]
	public function testALineThatHasSuchValuesIsSavedAgainAsItIs(string $rWho, string $rNote): void {
		$rAnswer = $this->save($rWho, ['edit' => (string) self::BEFORE, 'username' => 'old/name', 'password' => 'old/pass', $rNote => 'called']);

		$this->assertSame(STATUS_SUCCESS, $rAnswer);
		$rLine = $this->line(self::BEFORE);
		$this->assertSame(['username' => 'old/name', 'password' => 'old/pass'], array_slice($rLine, 0, 2));
		$this->assertSame('called', $rLine[$rNote], 'the rest of the save is stored');
	}

	#[DataProvider('refusals')]
	public function testSuchAValueIsNotChangedForAnotherOne(string $rWho, string $rField, string $rStatus): void {
		$rAnswer = $this->save($rWho, [$rField => 'new/value'] + ['edit' => (string) self::BEFORE, 'username' => 'old/name', 'password' => 'old/pass']);

		$this->assertSame(constant($rStatus), $rAnswer);
		$this->assertSame(['username' => 'old/name', 'password' => 'old/pass'], array_slice($this->line(self::BEFORE), 0, 2));
	}

	#[DataProvider('savers')]
	public function testSuchAValueIsChangedForOneWithoutTheSeparator(string $rWho): void {
		$rAnswer = $this->save($rWho, ['edit' => (string) self::BEFORE, 'username' => 'newname', 'password' => 'newpass']);

		$this->assertSame(STATUS_SUCCESS, $rAnswer);
		$this->assertSame(['username' => 'newname', 'password' => 'newpass'], array_slice($this->line(self::BEFORE), 0, 2));
	}

	#[DataProvider('savers')]
	public function testAnyOtherUsernameAndPasswordAreStored(string $rWho): void {
		$rAnswer = $this->save($rWho, ['username' => 'View.er-1_a', 'password' => 'Ab.c-d_9!']);

		$this->assertSame(STATUS_SUCCESS, $rAnswer);
		$this->rDb->query("SELECT `password` FROM `lines` WHERE `username` = 'View.er-1_a'");
		$this->assertSame('Ab.c-d_9!', $this->rDb->get_col());
	}
}
