<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TestDb;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Coverage for the shared custom_data resolver used by line, mag and enigma
 * saves (CategoryTemplateService::applyCustomData). The column must only be
 * touched when the request carries `category_template_id` or `custom_data`,
 * so an untouched edit preserves whatever layout is already stored.
 */
final class CategoryTemplateApplyCustomDataTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE `category_template_items` (
				`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
				`template_id` INTEGER,
				`category_type` TEXT,
				`category_id` INTEGER,
				`custom_name` TEXT,
				`is_visible` INTEGER DEFAULT 1,
				`sort_order` INTEGER DEFAULT 0
			);
			INSERT INTO `category_template_items`
				(`id`,`template_id`,`category_type`,`category_id`,`custom_name`,`is_visible`,`sort_order`) VALUES
				(1,5,"live",10,"Renamed",1,1),
				(2,5,"live",11,NULL,0,2);'
		);
		DatabaseFactory::set($this->db);
	}

	public function testTemplateIdZeroOrNoneClearsCustomData(): void {
		$this->assertNull(
			CategoryTemplateService::applyCustomData(['category_template_id' => '0'], [])['custom_data']
		);
		$this->assertNull(
			CategoryTemplateService::applyCustomData(['category_template_id' => 'none'], [])['custom_data']
		);
	}

	public function testPositiveTemplateIdBuildsCustomDataJson(): void {
		$row = CategoryTemplateService::applyCustomData(['category_template_id' => '5'], []);

		$this->assertArrayHasKey('custom_data', $row);
		$decoded = json_decode((string) $row['custom_data'], true);
		$this->assertSame(5, $decoded['template_id']);
		// Hidden category (is_visible=0) lands in the live hide list.
		$this->assertStringContainsString('11', (string) $decoded['live_cat']['hide_ids']);
		$this->assertSame('10,11', $decoded['live_cat']['order']);
	}

	public function testInvalidTemplateIdLeavesCustomDataUntouched(): void {
		// Empty / non-numeric template id must not overwrite an existing layout.
		$row = CategoryTemplateService::applyCustomData(
			['category_template_id' => ''],
			['custom_data' => 'KEEP']
		);
		$this->assertSame('KEEP', $row['custom_data']);
	}

	public function testRawCustomDataStringAndArrayAndEmpty(): void {
		$this->assertSame(
			'{"x":1}',
			CategoryTemplateService::applyCustomData(['custom_data' => '{"x":1}'], [])['custom_data']
		);
		$this->assertSame(
			'{"x":1}',
			CategoryTemplateService::applyCustomData(['custom_data' => ['x' => 1]], [])['custom_data']
		);
		$this->assertNull(
			CategoryTemplateService::applyCustomData(['custom_data' => ''], [])['custom_data']
		);
	}

	public function testNeitherKeyPreservesTarget(): void {
		$target = ['username' => 'bob', 'custom_data' => 'EXISTING'];
		$this->assertSame($target, CategoryTemplateService::applyCustomData([], $target));
	}
}
