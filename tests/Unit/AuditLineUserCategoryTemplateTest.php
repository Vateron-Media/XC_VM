<?php

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
 * The category template a reseller picks on the line form or the MAG form is
 * applied to the line: one of the templates the reseller may use (its own,
 * those of the resellers under it, system and shared ones). "Reset" clears
 * the layout, no choice keeps it, and a layout is not taken from the request
 * as raw data. The lines of the activation codes a reseller issues follow
 * the same rule; an administrator's codes take any template or layout.
 */
final class AuditLineUserCategoryTemplateTest extends TestCase {
	private const RESELLER = 5;
	private const OWN_TEMPLATE = 1;
	private const FOREIGN_TEMPLATE = 2;
	private const SYSTEM_TEMPLATE = 3;

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
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'mag_devices', 'enigma2_devices', 'activation_codes', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('021_add_category_templates'));
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`) VALUES (2, 'Resellers', 1)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `check_compatible`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (1, 'admin', 1, 0, 0, '[]'), (5, 'reseller', 2, 100, 0, '[]'), (7, 'another', 2, 100, 0, '[]')");
		$this->rDb->exec("INSERT INTO `category_templates` (`id`, `owner_id`, `name`, `is_system`, `is_shared`, `created_at`, `updated_at`) VALUES (1, 5, 'Mine', 0, 0, NOW(), NOW()), (2, 7, 'Theirs', 0, 0, NOW(), NOW()), (3, 1, 'System', 1, 0, NOW(), NOW())");
		$this->rDb->exec("INSERT INTO `category_template_items` (`template_id`, `category_id`, `category_type`, `sort_order`) VALUES (1, 11, 'live', 1), (1, 12, 'live', 2), (2, 21, 'live', 1), (3, 31, 'live', 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		ResellerAPI::$rSettings = ['mag_default_type' => 0];
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => $rBefore) {
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
	 * The reseller saves the line form with $rExtra: the line's id.
	 *
	 * @param array<string, mixed> $rExtra
	 */
	private function saveLine(array $rExtra): int {
		$rResult = ResellerAPI::processLine($rExtra + ['package' => 1, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']);
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		return (int) $rResult['data']['insert_id'];
	}

	/** @return array<string, mixed>|null the layout the line holds */
	private function layout(int $rLineID): ?array {
		$this->rDb->query('SELECT `custom_data` FROM `lines` WHERE `id` = ?', $rLineID);
		$rLayout = $this->rDb->get_row()['custom_data'];
		return $rLayout === null ? null : json_decode($rLayout, true);
	}

	public function testANewLineTakesTheTemplateTheResellerPicked(): void {
		$rLine = $this->saveLine(['category_template_id' => (string) self::OWN_TEMPLATE]);

		$rLayout = $this->layout($rLine);
		$this->assertSame(self::OWN_TEMPLATE, $rLayout['template_id'] ?? null);
		$this->assertSame('11,12', $rLayout['live_cat']['order']);
	}

	public function testAnEditedLineTakesTheTemplateTheResellerPicked(): void {
		$rLine = $this->saveLine([]);
		$this->assertNull($this->layout($rLine));

		$rResult = ResellerAPI::processLine(['edit' => (string) $rLine, 'category_template_id' => (string) self::SYSTEM_TEMPLATE, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(self::SYSTEM_TEMPLATE, $this->layout($rLine)['template_id'] ?? null);
	}

	public function testANewMagDeviceTakesTheTemplateTheResellerPicked(): void {
		$rResult = ResellerAPI::processMAG(['category_template_id' => (string) self::OWN_TEMPLATE, 'package' => 1, 'mac' => '00:1A:79:00:00:01', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '', 'reseller_notes' => '']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->rDb->query('SELECT `user_id` FROM `mag_devices`');
		$this->assertSame(self::OWN_TEMPLATE, $this->layout((int) $this->rDb->get_row()['user_id'])['template_id'] ?? null);
	}

	public function testATemplateTheResellerMayNotUseIsNotApplied(): void {
		$rLine = $this->saveLine(['category_template_id' => (string) self::FOREIGN_TEMPLATE]);
		$this->assertNull($this->layout($rLine));

		ResellerAPI::processLine(['edit' => (string) $rLine, 'category_template_id' => (string) self::OWN_TEMPLATE, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']);
		ResellerAPI::processLine(['edit' => (string) $rLine, 'category_template_id' => (string) self::FOREIGN_TEMPLATE, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']);

		$this->assertSame(self::OWN_TEMPLATE, $this->layout($rLine)['template_id'] ?? null, 'the line keeps the layout it had');
	}

	public function testResetClearsTheLayoutAndNoChoiceKeepsIt(): void {
		$rLine = $this->saveLine(['category_template_id' => (string) self::OWN_TEMPLATE]);
		$rEdit = ['edit' => (string) $rLine, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => ''];

		ResellerAPI::processLine(['category_template_id' => ''] + $rEdit);
		$this->assertSame(self::OWN_TEMPLATE, $this->layout($rLine)['template_id'] ?? null, 'no choice');

		ResellerAPI::processLine(['category_template_id' => '0'] + $rEdit);
		$this->assertNull($this->layout($rLine), 'reset');
	}

	public function testALayoutIsNotTakenFromTheRequestAsRawData(): void {
		$rLine = $this->saveLine(['custom_data' => '{"live_cat":{"hide_ids":"","renamed":{},"order":"99"}}']);

		$this->assertNull($this->layout($rLine));
	}

	// ── activation codes ────────────────────────────────────────────

	/**
	 * One activation code on the package, issued with $rExtra: the layout its line holds.
	 *
	 * @param array<string, mixed> $rExtra
	 * @return array<string, mixed>|null
	 */
	private function codeLayout(array $rExtra, bool $rAsAdministrator = false): ?array {
		$rUser = $rAsAdministrator ? ['id' => 1, 'member_group_id' => 1] : UserRepository::getRegisteredUserById(self::RESELLER);
		$rResult = ActiveCodeService::generateCodes($rExtra + ['package_id' => 1, 'num_codes' => 1], $rUser, $rAsAdministrator);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		return $this->layout((int) $rResult['codes'][0]['line_id']);
	}

	public function testACodeTakesTheTemplateTheResellerPicked(): void {
		$this->assertSame(self::OWN_TEMPLATE, $this->codeLayout(['category_template_id' => (string) self::OWN_TEMPLATE])['template_id'] ?? null);
		$this->assertSame(self::SYSTEM_TEMPLATE, $this->codeLayout(['category_template_id' => (string) self::SYSTEM_TEMPLATE])['template_id'] ?? null);
	}

	public function testACodeDoesNotTakeATemplateTheResellerMayNotUse(): void {
		$this->assertNull($this->codeLayout(['category_template_id' => (string) self::FOREIGN_TEMPLATE]));
	}

	public function testACodeDoesNotTakeALayoutFromTheRequestAsRawData(): void {
		$rRaw = '{"template_id":' . self::FOREIGN_TEMPLATE . ',"live_cat":{"hide_ids":"","renamed":{},"order":"21"}}';

		$this->assertNull($this->codeLayout(['custom_data' => $rRaw]));
		$this->assertNull($this->codeLayout(['category_template_id' => (string) self::FOREIGN_TEMPLATE, 'custom_data' => $rRaw]));
	}

	public function testAnAdministratorsCodeTakesAnyTemplateOrLayout(): void {
		$this->assertSame(self::FOREIGN_TEMPLATE, $this->codeLayout(['category_template_id' => (string) self::FOREIGN_TEMPLATE], true)['template_id'] ?? null);
		$this->assertSame('99', $this->codeLayout(['custom_data' => '{"live_cat":{"hide_ids":"","renamed":{},"order":"99"}}'], true)['live_cat']['order'] ?? null);
	}
}
