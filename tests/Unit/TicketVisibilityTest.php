<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TestDb;
use XcVm\Domain\User\TicketRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Tenant isolation for the ticket list.
 *
 * `users_groups`.`is_admin` is a group flag that more than one group can carry,
 * so reaching the admin panel must not mean seeing every tenant's tickets: only
 * the super-admin group (member_group_id = 1) gets the whole server, while any
 * other admin stays scoped to the users it owns.
 *
 * Hierarchy seeded: super-admin(1); sub-admin(2) owns reseller(3) who owns
 * sub-reseller(4); stranger-admin(90) owns stranger-reseller(91), an unrelated
 * branch that must stay invisible to the sub-admin.
 */
final class TicketVisibilityTest extends TestCase {

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
				(1,"superadmin",0,1),
				(2,"subadmin",1,3),
				(3,"reseller",2,4),
				(4,"subreseller",3,4),
				(90,"strangeradmin",1,3),
				(91,"strangerreseller",90,4);
			CREATE TABLE `tickets` (
				`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
				`member_id` INTEGER,
				`title` TEXT,
				`status` INTEGER DEFAULT 1,
				`admin_read` INTEGER DEFAULT 0,
				`user_read` INTEGER DEFAULT 0
			);
			INSERT INTO `tickets` (`id`,`member_id`,`title`) VALUES
				(10,2,"from subadmin"),
				(11,3,"from reseller"),
				(12,4,"from subreseller"),
				(13,91,"from stranger branch");
			CREATE TABLE `tickets_replies` (
				`id` INTEGER PRIMARY KEY AUTO_INCREMENT,
				`ticket_id` INTEGER,
				`date` INTEGER,
				`admin_reply` INTEGER DEFAULT 0,
				`message` TEXT
			);'
		);
		DatabaseFactory::set($this->db);
		$GLOBALS['rPermissions'] = ['all_reports' => []];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	public function testSuperAdminSeesEveryTicketOnTheServer(): void {
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];

		$this->assertSame([10, 11, 12, 13], $this->ticketIds(TicketRepository::getAll(1, true)));
	}

	public function testNonSuperAdminDoesNotSeeAnotherAdminsBranch(): void {
		// Regression guard: an `$rAdmin` call used to widen to every ticket on the
		// server, exposing other admins' tenants to any admin-panel user.
		$GLOBALS['rUserInfo'] = ['id' => 2, 'member_group_id' => 3];

		$rIds = $this->ticketIds(TicketRepository::getAll(2, true));

		$this->assertSame([10, 11], $rIds, 'own ticket plus the users it owns');
		$this->assertNotContains(13, $rIds, 'stranger branch must stay hidden');
	}

	public function testResellerSeesOwnTicketsPlusReports(): void {
		$GLOBALS['rUserInfo'] = ['id' => 3, 'member_group_id' => 4];
		$GLOBALS['rPermissions'] = ['all_reports' => [4]];

		$this->assertSame([11, 12], $this->ticketIds(TicketRepository::getAll(3)));
	}

	public function testMissingIdStillListsEverything(): void {
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];

		$this->assertSame([10, 11, 12, 13], $this->ticketIds(TicketRepository::getAll(null, true)));
	}

	public function testTicketSurvivesItsAuthorBeingDeleted(): void {
		$this->db->exec('DELETE FROM `users` WHERE `id` = 91;');
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];

		$rRows   = TicketRepository::getAll(1, true);
		$rOrphan = array_values(array_filter($rRows, static fn(array $r): bool => (int) $r['id'] === 13));

		$this->assertCount(1, $rOrphan, 'the ticket must not vanish with its author');
		$this->assertSame('Unknown', $rOrphan[0]['username']);
	}

	/**
	 * @param  array<int, array<string, mixed>> $rRows
	 * @return int[] Ticket ids, ascending.
	 */
	private function ticketIds(array $rRows): array {
		$rIds = array_map(static fn(array $r): int => (int) $r['id'], $rRows);
		sort($rIds);
		return $rIds;
	}
}
