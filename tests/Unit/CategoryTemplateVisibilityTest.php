<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TestDb;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Regression coverage for hierarchical category-template visibility: a
 * reseller must see templates shared by ANY ancestor reseller above them,
 * not only by their direct parent (imported from PR #198).
 *
 * Hierarchy seeded: admin(1) -> grandparent(2) -> parent(3) -> me(4);
 * stranger(99) sits directly under admin, outside the caller's chain.
 */
final class CategoryTemplateVisibilityTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE `users` (
				`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
				`username` TEXT,
				`owner_id` INTEGER,
				`member_group_id` INTEGER
			);
			INSERT INTO `users` (`id`,`username`,`owner_id`,`member_group_id`) VALUES
				(2,"grandparent",1,3),
				(3,"parent",2,3),
				(4,"me",3,3),
				(99,"stranger",1,3);
			CREATE TABLE `category_templates` (
				`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
				`owner_id` INTEGER,
				`name` TEXT,
				`is_system` INTEGER DEFAULT 0,
				`is_shared` INTEGER DEFAULT 0,
				`created_at` TEXT,
				`updated_at` TEXT
			);
			INSERT INTO `category_templates` (`id`,`owner_id`,`name`,`is_system`,`is_shared`) VALUES
				(10,2,"GP Shared",0,1),
				(11,4,"Mine",0,0),
				(12,99,"Stranger Shared",0,1);
			CREATE TABLE `lines` (
				`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
				`custom_data` TEXT
			);'
		);
		DatabaseFactory::set($this->db);
	}

	public function testResellerSeesTemplateSharedByAnAncestorNotJustDirectParent(): void {
		$rTemplates = CategoryTemplateService::getTemplatesForUser(['id' => 4, 'owner_id' => 3], false);

		$byId = [];
		foreach ($rTemplates as $rTmpl) {
			$byId[(int) $rTmpl['id']] = $rTmpl;
		}

		// Grandparent-shared template is now visible (ancestor chain, not only direct parent).
		$this->assertArrayHasKey(10, $byId);
		$this->assertSame('shared', $byId[10]['scope_type']);

		// The caller's own template stays classified as "mine".
		$this->assertArrayHasKey(11, $byId);
		$this->assertSame('mine', $byId[11]['scope_type']);

		// A shared template owned by a reseller outside the caller's chain is not visible.
		$this->assertArrayNotHasKey(12, $byId);
	}

	public function testCanAccessTemplateAcceptsAncestorSharedButRejectsStranger(): void {
		$user = ['id' => 4, 'owner_id' => 3];

		$ancestorShared = ['id' => 10, 'owner_id' => 2, 'is_shared' => 1, 'is_system' => 0];
		$strangerShared = ['id' => 12, 'owner_id' => 99, 'is_shared' => 1, 'is_system' => 0];

		$this->assertTrue(CategoryTemplateService::canAccessTemplate($ancestorShared, $user, false));
		$this->assertFalse(CategoryTemplateService::canAccessTemplate($strangerShared, $user, false));
	}
}
