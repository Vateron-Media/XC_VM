<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use TestDb;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

final class PackageServiceGroupsTest extends TestCase {
	private TestDb $db;
	private mixed $previousDb;
	private array $overrides;
	private mixed $globalDb;

	protected function setUp(): void {
		$this->previousDb = DatabaseFactory::get();
		$this->globalDb = $GLOBALS['db'] ?? null;
		foreach ([PackageService::class, UserRepository::class] as $class) {
			$property = new ReflectionProperty($class, 'db');
			$this->overrides[$class] = $property->getValue();
			$property->setValue(null, null);
		}
		$this->db = new TestDb();
		$this->db->exec(InstallSchema::table('users_packages'));
		$this->db->exec(InstallSchema::table('users'));
		DatabaseFactory::set($this->db);
		$GLOBALS['db'] = $this->db;
		defined('CACHE_TMP_PATH') || define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm-no-package-cache/');
	}

	protected function tearDown(): void {
		foreach ($this->overrides as $class => $value) {
			(new ReflectionProperty($class, 'db'))->setValue(null, $value);
		}
		DatabaseFactory::reset();
		if ($this->previousDb !== null) {
			DatabaseFactory::set($this->previousDb);
		}
		$GLOBALS['db'] = $this->globalDb;
	}

	public static function invalidGroups(): array {
		return [[null], [''], ['not-json'], ['null'], ['1'], ['true'], ['"2"'], ['{"group":2}'], ['{"0":2}'], ['[true]'], ['[2.5]'], ['["2oops"]'], ['[[2]]']];
	}

	#[DataProvider('invalidGroups')]
	public function testMalformedPackageDoesNotBreakGroupLookup(?string $groups): void {
		$statement = $this->db->pdo->prepare('INSERT INTO users_packages (id, `groups`, is_line, is_mag) VALUES (1, ?, 0, 1), (2, ?, 1, 0);');
		$statement->execute([$groups, '[2]']);
		$this->assertSame($groups, $this->db->pdo->query('SELECT `groups` FROM users_packages WHERE id = 1;')->fetchColumn());

		$this->assertSame([2], array_keys(PackageService::getAll(2)));
		$this->assertSame([1, 2], array_keys(PackageService::getAll()));
	}

	#[DataProvider('invalidGroups')]
	public function testMalformedPackageDoesNotRemoveValidCreatePermissions(?string $groups): void {
		$this->db->exec("INSERT INTO users (id, member_group_id) VALUES (5, 2);");
		$statement = $this->db->pdo->prepare('INSERT INTO users_packages (id, `groups`, is_line, is_mag) VALUES (1, ?, 0, 1), (2, ?, 1, 0);');
		$statement->execute([$groups, '["2"]']);
		$permissions = AuthRepository::getGroupPermissions(5, false, false);
		$this->assertTrue($permissions['create_line']);
		$this->assertFalse($permissions['create_mag']);
		$this->assertFalse($permissions['create_enigma']);
	}

	public function testGroupAndDeviceFiltersStillApply(): void {
		$this->db->exec("INSERT INTO users_packages (id, `groups`, is_line) VALUES (1, '[2]', 1), (2, '[\"2\"]', 1), (3, '[3]', 1), (4, '[]', 1), (5, '[2]', 0);");

		$this->assertSame([1, 2, 5], array_keys(PackageService::getAll(2)));
		$this->assertSame([1, 2], array_keys(PackageService::getAll(2, 'line')));
		$this->assertSame([], PackageService::getAll(9));
	}
}
