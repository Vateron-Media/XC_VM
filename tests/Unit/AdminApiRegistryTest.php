<?php

use XcVm\Core\Module\AdminApiRegistry;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use PHPUnit\Framework\TestCase;

/**
 * Module actions of the Admin REST API: registered by name, refused when
 * malformed, and run by core with the numeric status turned into its
 * STATUS_* name and show/hide_columns applied as the action declared.
 */
final class AdminApiRegistryTest extends TestCase {

	protected function setUp(): void {
		AdminApiRegistry::reset();
		$GLOBALS['_ERRORS'] = [0 => 'STATUS_FAILURE', 1 => 'STATUS_SUCCESS'];
	}

	protected function tearDown(): void {
		AdminApiRegistry::reset();
	}

	public function testAnActionIsFoundByItsName(): void {
		AdminApiRegistry::add('get_things', static fn(array $rData): array => ['status' => 1]);

		$this->assertNotNull(AdminApiRegistry::get('get_things'));
		$this->assertNull(AdminApiRegistry::get('get_other'));
	}

	public function testMalformedActionsAreRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		AdminApiRegistry::add('Bad Action', static fn(array $rData): array => []);
	}

	public function testAnUnknownColumnModeIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		AdminApiRegistry::add('get_things', static fn(array $rData): array => [], 'table');
	}

	public function testCoreRunsTheActionWithItsDataAndNamesTheStatus(): void {
		AdminApiRegistry::add('echo_id', static fn(array $rData): array => ['status' => 1, 'data' => ['id' => $rData['id']]]);

		$this->assertSame(['status' => 'STATUS_SUCCESS', 'data' => ['id' => 7]], AdminAPIWrapper::moduleAction(AdminApiRegistry::get('echo_id'), ['id' => 7], null, null));
	}

	public function testColumnsApplyToARowOrToRows(): void {
		AdminApiRegistry::add('get_thing', static fn(array $rData): array => ['status' => 1, 'data' => ['id' => 1, 'name' => 'a', 'secret' => 'x']], 'row');
		AdminApiRegistry::add('get_things', static fn(array $rData): array => ['status' => 1, 'data' => [['id' => 1, 'secret' => 'x'], ['id' => 2, 'secret' => 'y']]], 'rows');

		$this->assertSame(['status' => 'STATUS_SUCCESS', 'data' => ['id' => 1, 'name' => 'a']], AdminAPIWrapper::moduleAction(AdminApiRegistry::get('get_thing'), [], null, ['secret']));
		$this->assertSame([['id' => 1], ['id' => 2]], AdminAPIWrapper::moduleAction(AdminApiRegistry::get('get_things'), [], ['id'], null));
	}
}
