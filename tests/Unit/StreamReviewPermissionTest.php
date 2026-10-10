<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;

/**
 * Stream Review renames streams and changes their EPG, categories and
 * bouquets: it opens for an administrator who may import or mass-edit
 * streams. No rule named the page, and a page no rule names is open to every
 * administrator group.
 */
final class StreamReviewPermissionTest extends TestCase {
	private array $rGlobals;

	protected function setUp(): void {
		$this->rGlobals = [$GLOBALS['db'] ?? null, $GLOBALS['rUserInfo'] ?? null, $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['db'] = new stdClass();
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
	}

	protected function tearDown(): void {
		[$GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']] = $this->rGlobals;
	}

	public function testItNeedsImportOrMassEditOfStreams(): void {
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['manage_tickets']];
		$this->assertFalse(PageAuthorization::checkPermissions('stream_review', false), 'a group that only answers tickets');
		foreach (['import_streams', 'mass_edit_streams'] as $rPermission) {
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => [$rPermission]];
			$this->assertTrue(PageAuthorization::checkPermissions('stream_review', false), $rPermission);
		}
	}
}
