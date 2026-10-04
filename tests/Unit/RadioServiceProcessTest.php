<?php

use XcVm\Domain\Stream\RadioService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use PHPUnit\Framework\TestCase;

// STATUS_* live in bootstrap.php (not loaded here); define the ones process() returns.
if (!defined('STATUS_FAILURE')) {
	define('STATUS_FAILURE', 0);
}
if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}
if (!defined('STATUS_NO_SOURCES')) {
	define('STATUS_NO_SOURCES', 4);
}
if (!defined('STATUS_INVALID_INPUT')) {
	define('STATUS_INVALID_INPUT', 34);
}

/**
 * RadioService::process() — characterization of the OUTER GUARD control flow,
 * the part being flattened into guard clauses.
 *
 * The success path and the auth-fail path (which exit()s) are out of scope here; the success-path internals are covered by the extracted
 * helpers in RadioServiceTest. These two branches — invalid input, and no source
 * — are exactly the guards the flattening restructures, so they guard the change.
 */
final class RadioServiceProcessTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_display_name TEXT, stream_icon TEXT);');
		$this->db->query('INSERT INTO streams (id, stream_display_name, stream_icon) VALUES (5, "old", "");');
		// Resolve every DatabaseAware class (RadioService, StreamRepository, ...) to the test db.
		DatabaseFactory::set($this->db);
		// Authorization::check() reads these globals + global $db.
		$GLOBALS['db'] = $this->db;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
	}

	protected function tearDown(): void {
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	public function testInvalidInputWhenValidationFails(): void {
		// Empty display name, no review, no upload → processRadio validation fails.
		$rResult = RadioService::process(['stream_display_name' => '']);
		$this->assertSame(STATUS_INVALID_INPUT, $rResult['status']);
	}

	public function testNoSourcesWhenStreamSourceEmpty(): void {
		// Edit path (avoids verifyPostTable): valid + authorized, but no source.
		$rResult = RadioService::process([
			'edit'                => 5,
			'stream_display_name' => 'My Radio',
			'stream_source'       => [''],
		]);
		$this->assertSame(STATUS_NO_SOURCES, $rResult['status']);
	}
}
