<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * An administrator's `is_trial` is a switch, read as a reseller's is: on at
 * 1, true, on or yes; off at 0, false, off, no, empty or left out. A request
 * of the API or of the panel's form encodes it as text, so "false" is off.
 * Codes of a package with its Trial switch on are trial codes when the
 * request names no `is_trial`.
 */
final class AuditDecisionAdminTrialSwitchTest extends TestCase {
	private const STANDARD = 2;
	private const BOTH = 4;

	private TestDb $rDb;

	/** @var array<class-string, mixed> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

	/** The panel settings global as the test found it. */
	private mixed $rSettings;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'activation_codes', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('021_add_category_templates'));
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `check_compatible`) VALUES"
			. " (2, 'Month', 0, 1, 0, 10, 0, 'hours', 1, 'months', '[]', '[]', '[1]', 1, 0),"
			. " (4, 'Both', 1, 1, 0, 10, 1, 'days', 1, 'months', '[]', '[]', '[1]', 1, 0)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => $rBefore) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $rBefore);
		}
		DatabaseFactory::reset();
		$GLOBALS['rSettings'] = $this->rSettings;
		unset($GLOBALS['db']);
	}

	/**
	 * An administrator asks for a code of $rPackage: whether the code and its line are trials.
	 *
	 * @param array<string, mixed> $rAsked what else the request names
	 * @return list<int> [the code's is_trial, its line's is_trial]
	 */
	private function trial(int $rPackage, array $rAsked = []): array {
		$rResult = ActiveCodeService::generateCodes($rAsked + ['package_id' => $rPackage, 'num_codes' => 1], ['id' => 1, 'member_group_id' => 1], true);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message'] ?? '');
		$this->rDb->query('SELECT c.`is_trial`, l.`is_trial` AS `line_is_trial` FROM `activation_codes` c JOIN `lines` l ON l.`id` = c.`subscriber_id`');
		return array_map('intval', array_values($this->rDb->get_raw_row() ?? []));
	}

	/** @return array<string, array{0: mixed, 1: int}> what is sent as is_trial, whether that asks for trial codes */
	public static function switches(): array {
		$rOut = [];
		foreach (['1', 'true', 'on', 'yes', 1, true] as $rOn) {
			$rOut['on at ' . var_export($rOn, true)] = [$rOn, 1];
		}
		foreach (['0', 'false', 'off', 'no', '', 0, false] as $rOff) {
			$rOut['off at ' . var_export($rOff, true)] = [$rOff, 0];
		}
		return $rOut;
	}

	#[DataProvider('switches')]
	public function testIsTrialIsReadAsASwitchOnAPackageWithoutTrials(mixed $rSent, int $rTrial): void {
		$this->assertSame([$rTrial, $rTrial], $this->trial(self::STANDARD, ['is_trial' => $rSent]));
	}

	public function testCodesOfAPackageWithoutTrialsAreOfficialWhenIsTrialIsLeftOut(): void {
		$this->assertSame([0, 0], $this->trial(self::STANDARD));
	}

	public function testCodesOfAPackageWithTrialsAreTrialsWhenIsTrialIsLeftOut(): void {
		$this->assertSame([1, 1], $this->trial(self::BOTH));
	}
}
