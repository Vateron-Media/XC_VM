<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Reference\PermissionReference;

/**
 * The Created Channels page shows its list to an administrator whose group
 * lists advanced permissions when the group holds the permission that views
 * created channels, or the one that edits them. The page names permissions a
 * group can be given: a key no group can hold opens what it guards for group
 * 1 alone.
 */
final class AuditAdminViewsCreatedChannelsTest extends TestCase {
	private string $rSource;

	protected function setUp(): void {
		$this->rSource = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/created_channels.php');
		$GLOBALS['db'] = new stdClass();
	}

	protected function tearDown(): void {
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['db']);
	}

	/**
	 * Whether the page shows its list to an administrator whose group lists
	 * $rAdvanced: the page decides it in one condition, run here as it stands there.
	 *
	 * @param array<string> $rAdvanced
	 */
	private function shown(array $rAdvanced, int $rGroup = 5): bool {
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => $rGroup];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => array_values($rAdvanced)];
		$this->assertSame(1, preg_match('/^if \((!Authorization::check\(.*)\):$/m', $this->rSource, $rRefused));
		return !eval('use XcVm\Core\Auth\Authorization; return ' . $rRefused[1] . ';');
	}

	public function testTheListIsShownWithThePermissionThatViewsCreatedChannels(): void {
		$this->assertContains('manage_cchannels', PermissionReference::keys());
		$this->assertTrue($this->shown(['streams', 'manage_cchannels']), 'with manage_cchannels');
		$this->assertTrue($this->shown(['streams', 'edit_cchannel']), 'with edit_cchannel');
		$this->assertFalse($this->shown(array_diff(PermissionReference::keys(), ['manage_cchannels', 'edit_cchannel'])), 'with every permission but the two');
		$this->assertTrue($this->shown(['ticket'], 1), 'group 1');
		$this->assertTrue($this->shown([]), 'a group that lists no permissions');
	}

	public function testThePageNamesOnlyPermissionsAGroupCanHold(): void {
		preg_match_all('/[\'"]adv[\'"]\s*,\s*[\'"](\w+)[\'"]/', $this->rSource, $rPairs);
		$this->assertGreaterThan(3, count($rPairs[1]));
		$this->assertSame([], array_values(array_diff(array_unique($rPairs[1]), PermissionReference::keys())));
	}
}
