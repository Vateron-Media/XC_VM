<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

final class CustomerCategoryTemplateTest extends TestCase {
	private TestDb $db;
	private mixed $lineDb;
	private array $settings;
	private mixed $servers;

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['users', 'lines', 'streams_categories'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		$this->db->exec(InstallSchema::migration('021_add_category_templates'));
		$this->db->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->db->exec(InstallSchema::migration('077_add_admin_audit'));
		$this->db->exec('INSERT INTO `users` (`id`,`username`,`owner_id`,`member_group_id`) VALUES (1,"admin",0,1),(5,"reseller",0,2),(6,"child",5,2),(7,"other",0,2)');
		$this->db->exec('INSERT INTO `streams_categories` (`id`,`category_type`,`category_name`,`cat_order`) VALUES (10,"live","News",1),(11,"live","Sports",2),(20,"movie","Movies",1),(30,"radio","Radio",1)');
		DatabaseFactory::set($this->db);
		$this->lineDb = (new ReflectionProperty(LineService::class, 'db'))->getValue();
		(new ReflectionProperty(LineService::class, 'db'))->setValue(null, $this->db);
		$this->settings = SettingsManager::getAll();
		$this->servers = $GLOBALS['rServers'] ?? null;
		$GLOBALS['rServers'] = [1 => ['id' => 1, 'is_main' => 1, 'server_type' => 0]];
		SettingsManager::set(['enable_cache' => 0, 'cluster_kill_on_line_disable' => 0]);
		$GLOBALS['rUserInfo'] = ['id' => 5, 'username' => 'reseller'];
	}

	protected function tearDown(): void {
		(new ReflectionProperty(LineService::class, 'db'))->setValue(null, $this->lineDb);
		SettingsManager::set($this->settings);
		$GLOBALS['rServers'] = $this->servers;
		DatabaseFactory::reset();
		unset($GLOBALS['rUserInfo']);
	}

	private function template(int $owner, string $name, int $system = 0, int $shared = 0): int {
		$result = CategoryTemplateService::createTemplate($owner, $name, (bool) $system, (bool) $shared);
		$this->assertTrue($result['success']);
		return (int) $result['id'];
	}

	private function addLine(int $id, int $owner, ?int $template = null, string|array|null $layout = null): void {
		if ($template !== null) {
			$layout = CategoryTemplateService::buildCustomData($template);
		}
		$customData = is_array($layout) ? json_encode($layout, JSON_UNESCAPED_UNICODE) : $layout;
		$this->db->query('INSERT INTO `lines` (`id`,`member_id`,`username`,`custom_data`) VALUES (?,?,?,?)', $id, $owner, 'line' . $id, $customData);
	}

	private function layout(int $lineId): ?array {
		$this->db->query('SELECT `custom_data` FROM `lines` WHERE `id` = ?', $lineId);
		$value = $this->db->get_col();
		return $value === null ? null : json_decode((string) $value, true);
	}

	public function testDefaultLayoutGetsAPrivateCustomerTemplateAndAuditEntry(): void {
		$this->addLine(10, 5);

		$result = CategoryTemplateService::prepareCustomerTemplate(10, ['id' => 5]);

		$this->assertTrue($result['success']);
		$template = CategoryTemplateService::getTemplateById((int) $result['id']);
		$this->assertSame(5, (int) $template['owner_id']);
		$this->assertSame(0, (int) $template['is_shared']);
		$this->assertSame((int) $result['id'], $this->layout(10)['template_id']);
		$this->db->query("SELECT `detail` FROM `admin_audit` WHERE `action` = 'customer_category_order'");
		$detail = json_decode((string) $this->db->get_col(), true);
		$this->assertSame([10, 0, (int) $result['id']], [(int) $detail['line_id'], (int) $detail['previous_template_id'], (int) $detail['new_template_id']]);
	}

	public function testSharedAndMultiSubscriberTemplatesAreClonedForOnlyOneCustomer(): void {
		$shared = $this->template(1, 'System', 1, 1);
		$this->addLine(11, 5, $shared);
		$sharedResult = CategoryTemplateService::prepareCustomerTemplate(11, ['id' => 5]);
		$this->assertNotSame($shared, (int) $sharedResult['id']);
		$this->assertSame($shared, (int) CategoryTemplateService::buildCustomData($shared)['template_id']);

		$mine = $this->template(5, 'Reusable');
		$this->addLine(12, 5, $mine);
		$this->addLine(13, 5, $mine);
		$result = CategoryTemplateService::prepareCustomerTemplate(12, ['id' => 5]);

		$this->assertNotSame($mine, (int) $result['id']);
		$this->assertSame((int) $result['id'], $this->layout(12)['template_id']);
		$this->assertSame($mine, $this->layout(13)['template_id']);
	}

	public function testPrivateSingleCustomerTemplateIsEditedInPlace(): void {
		$template = $this->template(5, 'Private');
		$this->addLine(14, 5, $template);

		$result = CategoryTemplateService::prepareCustomerTemplate(14, ['id' => 5]);

		$this->assertTrue($result['success']);
		$this->assertSame($template, (int) $result['id']);
		$this->assertSame(1, CategoryTemplateService::getSubscriberCount($template));
	}

	public function testStoredLayoutIsPreservedWhenItHasNoTemplate(): void {
		$this->addLine(15, 5, null, ['live_cat' => ['order' => '11,30,10', 'hide_ids' => '10,30', 'renamed' => ['11' => 'My Sports', '30' => 'My Radio']]]);

		$result = CategoryTemplateService::prepareCustomerTemplate(15, ['id' => 5]);
		$layout = $this->layout(15);

		$this->assertSame('11,10', $layout['live_cat']['order']);
		$this->assertSame('10', $layout['live_cat']['hide_ids']);
		$this->assertSame('My Sports', $layout['live_cat']['renamed']['11']);
		$this->assertSame('30', $layout['radio_cat']['order']);
		$this->assertSame('30', $layout['radio_cat']['hide_ids']);
		$this->assertSame('My Radio', $layout['radio_cat']['renamed']['30']);
		$this->assertSame((int) $result['id'], $layout['template_id']);
	}

	public function testParentCreatesAChildOwnedTemplateBothCanEdit(): void {
		$shared = $this->template(1, 'Shared', 1, 1);
		$this->addLine(21, 6, $shared);

		$result = CategoryTemplateService::prepareCustomerTemplate(21, ['id' => 5]);
		$template = CategoryTemplateService::getTemplateById((int) $result['id']);

		$this->assertTrue($result['success']);
		$this->assertSame(6, (int) $template['owner_id']);
		$this->assertSame((int) $result['id'], (int) CategoryTemplateService::prepareCustomerTemplate(21, ['id' => 6])['id']);
		$this->db->query('SELECT * FROM `lines` WHERE `id` = 21');
		$this->assertSame('customer', CategoryTemplateService::getLineTemplateStatus($this->db->get_raw_row())['type']);
		$this->assertTrue(CategoryTemplateService::saveTemplate((int) $result['id'], [
			'name' => 'Child Layout',
			'categories' => [['category_id' => 10, 'category_type' => 'live', 'sort_order' => 1, 'is_visible' => 1]],
		], ['id' => 5], false)['success']);
	}

	public function testAnotherResellersCustomerAndPrivateTemplateAreRejected(): void {
		$this->addLine(16, 7);
		$this->assertFalse(CategoryTemplateService::prepareCustomerTemplate(16, ['id' => 5])['success']);

		$foreign = $this->template(7, 'Foreign');
		$this->addLine(17, 5, $foreign);
		$this->assertFalse(CategoryTemplateService::prepareCustomerTemplate(17, ['id' => 5])['success']);
		$this->assertSame($foreign, $this->layout(17)['template_id']);
	}

	public function testFailedAssignmentKeepsThePreviousLayout(): void {
		$source = $this->template(1, 'System', 1, 1);
		$this->addLine(18, 5, $source);
		$before = $this->layout(18);
		$refusing = new QueryLogDb($this->db);
		$refusing->rRefuse = '/^UPDATE `lines` SET `custom_data`/';
		DatabaseFactory::set($refusing);

		$result = CategoryTemplateService::prepareCustomerTemplate(18, ['id' => 5]);

		$this->assertFalse($result['success']);
		$this->assertSame($before, $this->layout(18));
	}

	public function testFailedCloneAndSaveKeepThePreviousLayout(): void {
		$source = $this->template(1, 'System', 1, 1);
		$this->addLine(19, 5, $source);
		$before = $this->layout(19);
		$refusing = new QueryLogDb($this->db);
		$refusing->rRefuse = '/^INSERT INTO `category_templates`/';
		DatabaseFactory::set($refusing);
		$this->assertFalse(CategoryTemplateService::prepareCustomerTemplate(19, ['id' => 5])['success']);
		$this->assertSame($before, $this->layout(19));

		DatabaseFactory::set($this->db);
		$private = $this->template(5, 'Private');
		$this->addLine(20, 5, $private);
		$before = $this->layout(20);
		$refusing = new QueryLogDb($this->db);
		$refusing->rRefuse = '/^INSERT INTO `category_template_items`/';
		DatabaseFactory::set($refusing);
		$result = CategoryTemplateService::saveTemplate($private, [
			'name' => 'Changed',
			'categories' => [['category_id' => 10, 'category_type' => 'live', 'sort_order' => 1, 'is_visible' => 0]],
		], ['id' => 5], false);
		$this->assertFalse($result['success']);
		$this->assertSame($before, $this->layout(20));
		$this->assertSame('10,11', CategoryTemplateService::buildCustomData($private)['live_cat']['order']);
	}

	public function testAllResellerProfilesExposeTheSameBrowserAction(): void {
		foreach (['line', 'mag', 'enigma'] as $view) {
			$source = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/' . $view . '.php');
			$this->assertStringContainsString('name="category_template_id"', $source, $view);
			$this->assertStringContainsString('id="custom-category-order"', $source, $view);
			$this->assertStringContainsString('action=category_template_customer', $source, $view);
		}
	}
}
