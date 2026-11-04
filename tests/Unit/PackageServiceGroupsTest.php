<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Line\PackageService;
use XcVm\Tests\Support\InstallSchema;

final class PackageServiceGroupsTest extends TestCase {
	private TestDb $db;
	private mixed $previousDb;

	protected function setUp(): void {
		$this->previousDb = (new ReflectionProperty(PackageService::class, 'db'))->getValue();
		$this->db = new TestDb();
		$this->db->exec(InstallSchema::table('users_packages'));
		PackageService::setDb($this->db);
	}

	protected function tearDown(): void {
		(new ReflectionProperty(PackageService::class, 'db'))->setValue(null, $this->previousDb);
	}

	public static function invalidGroups(): array {
		return [[null], [''], ['not-json'], ['null'], ['1'], ['true'], ['"2"']];
	}

	#[DataProvider('invalidGroups')]
	public function testMalformedPackageDoesNotBreakGroupLookup(?string $groups): void {
		$this->db->query('INSERT INTO users_packages (id, `groups`, is_line) VALUES (1, ?, 1), (2, ?, 1)', $groups, '[2]');

		$this->assertSame([2], array_keys(PackageService::getAll(2)));
		$this->assertSame([1, 2], array_keys(PackageService::getAll()));
	}

	public function testGroupAndDeviceFiltersStillApply(): void {
		$this->db->exec("INSERT INTO users_packages (id, `groups`, is_line) VALUES (1, '[2]', 1), (2, '[\"2\"]', 1), (3, '[3]', 1), (4, '[]', 1), (5, '[2]', 0)");

		$this->assertSame([1, 2, 5], array_keys(PackageService::getAll(2)));
		$this->assertSame([1, 2], array_keys(PackageService::getAll(2, 'line')));
		$this->assertSame([], PackageService::getAll(9));
	}
}
