<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Reference\PermissionReference;

/**
 * The form of a created channel and the form of an episode each show a record
 * no list shows whole (its sources), so each opens, and takes a save, with the
 * permission that adds one or the permission that edits one, whichever the
 * request is, and with no other: not the permission of the access codes, the
 * rule written after the created channel's, nor the one that lists the
 * episodes. Group 1 and a group that lists no permissions keep both forms.
 */
final class AuditAdminMisc2FormRulesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['db'] = new stdClass();
	}

	protected function tearDown(): void {
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** @param array<int, string> $rAdvanced */
	private function signIn(array $rAdvanced, int $rGroup = 5): void {
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => $rGroup];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => array_values($rAdvanced)];
	}

	/**
	 * What the form's rule answers the page and the save, for a new record and for one that exists.
	 *
	 * @return array{0: bool, 1: bool, 2: bool, 3: bool} the page and the save of a new record, then of an existing one
	 */
	private function rule(string $rForm): array {
		return [
			PageAuthorization::checkPermissions($rForm, false),
			PageAuthorization::checkPostAction($rForm, false),
			PageAuthorization::checkPermissions($rForm, true),
			PageAuthorization::checkPostAction($rForm, true),
		];
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> the form, the permission that adds a record and the one that edits it */
	public static function forms(): array {
		return [
			'a created channel' => ['created_channel', 'create_channel', 'edit_cchannel'],
			'an episode' => ['episode', 'add_episode', 'edit_episode'],
		];
	}

	#[DataProvider('forms')]
	public function testTheFormTakesThePermissionThatAddsOrEditsItsRecord(string $rForm, string $rAdd, string $rEdit): void {
		$this->signIn([$rAdd]);
		$this->assertSame([true, true, false, false], $this->rule($rForm), 'with ' . $rAdd);

		$this->signIn([$rEdit]);
		$this->assertSame([false, false, true, true], $this->rule($rForm), 'with ' . $rEdit);

		$this->signIn(array_diff(PermissionReference::keys(), [$rAdd, $rEdit]));
		$this->assertSame([false, false, false, false], $this->rule($rForm), 'with every other permission');
	}

	#[DataProvider('forms')]
	public function testAFullAdministratorKeepsTheForm(string $rForm): void {
		$this->signIn(['ticket'], 1);
		$this->assertSame([true, true, true, true], $this->rule($rForm), 'group 1');

		$this->signIn([]);
		$this->assertSame([true, true, true, true], $this->rule($rForm), 'a group that lists no permissions');
	}

	/** The rules written after the two forms keep their own permission. */
	public function testTheAccessCodesAndTheListOfEpisodesKeepTheirRules(): void {
		$this->signIn(['add_code']);
		$this->assertTrue(PageAuthorization::checkPermissions('code', false));
		$this->assertTrue(PageAuthorization::checkPermissions('codes', false));

		$this->signIn(['episodes']);
		$this->assertTrue(PageAuthorization::checkPermissions('episodes', false));
	}
}
