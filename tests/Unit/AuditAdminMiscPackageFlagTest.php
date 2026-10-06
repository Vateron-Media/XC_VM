<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\PackageAjaxController;
use XcVm\Tests\Support\InstallSchema;

/**
 * The package action sets a package's trial or official flag, to 0 or 1 as the
 * package form stores it, and answers that it did only when the flag is one of
 * the table's: a name that is not a column of `users_packages` is refused.
 * It answers success for a change that was made: a package that does not
 * exist, a value that is neither on nor off, and a statement the database
 * refused are each answered as a failure, and nothing is stored.
 *
 * The action ends the request itself, so it is driven through a subclass
 * whose json() throws the answer instead.
 */
final class AuditAdminMiscPackageFlagTest extends TestCase {
	private const PACKAGE = 4;

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('users_packages'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `is_mag`, `is_e2`, `is_line`) VALUES (4, 'Gold', 0, 1, 0, 0, 1), (5, 'Silver', 0, 1, 0, 0, 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/** Sets a flag of the package through the action: whether it says it did. A null value is not sent. */
	private function set(string $rFlag, mixed $rValue, int $rPackageID = self::PACKAGE): bool {
		RequestManager::set(array_filter(['sub' => $rFlag, 'value' => $rValue, 'package_id' => (string) $rPackageID], static fn($rSent): bool => $rSent !== null));
		try {
			(new AuditAdminMiscPackageFlagPanel())->package();
		} catch (AuditAdminMiscPackageFlagAnswer $rAnswer) {
			return $rAnswer->rData === ['result' => true];
		}
	}

	/** @return array<string, mixed> the package as it is stored, without its id */
	private function stored(int $rPackageID = self::PACKAGE): array {
		$this->rDb->query('SELECT * FROM `users_packages` WHERE `id` = ?', $rPackageID);
		return array_diff_key($this->rDb->get_row(), ['id' => true]);
	}

	/** @return array<string, array{0: string, 1: string, 2: int}> the flag, the value sent and the value stored */
	public static function flags(): array {
		return [
			'the trial flag is set' => ['is_trial', '1', 1],
			'the trial flag is set by a word' => ['is_trial', 'true', 1],
			'the official flag is cleared' => ['is_official', '0', 0],
			'the official flag is cleared by a word' => ['is_official', 'false', 0],
		];
	}

	#[DataProvider('flags')]
	public function testItSetsTheFlagOfThePackage(string $rFlag, string $rValue, int $rStored): void {
		$rBefore = $this->stored();
		$rOther = $this->stored(5);

		$this->assertTrue($this->set($rFlag, $rValue));

		$this->assertEquals([$rFlag => $rStored] + $rBefore, $this->stored());
		$this->assertSame($rOther, $this->stored(5));
	}

	/** @return array<string, array{0: string}> */
	public static function namesThatAreNotColumns(): array {
		return ['can_gen_mag' => ['can_gen_mag'], 'can_gen_e2' => ['can_gen_e2'], 'only_mag' => ['only_mag'], 'only_e2' => ['only_e2']];
	}

	#[DataProvider('namesThatAreNotColumns')]
	public function testANameThatIsNotAColumnOfTheTableIsRefused(string $rName): void {
		$rBefore = $this->stored();
		$this->assertNotContains($rName, InstallSchema::columns('users_packages'));

		$this->assertFalse($this->set($rName, '1'));
		$this->assertSame($rBefore, $this->stored());
	}

	/** @return array<string, array{0: string}> */
	public static function otherColumns(): array {
		return ['another column' => ['max_connections'], 'a flag with more after it' => ['is_trial` = 1, `package_name']];
	}

	#[DataProvider('otherColumns')]
	public function testOnlyTheTwoFlagsAreSet(string $rName): void {
		$rBefore = $this->stored();

		$this->assertFalse($this->set($rName, '1'));
		$this->assertSame($rBefore, $this->stored());
	}

	public function testAPackageThatDoesNotExistIsRefused(): void {
		$rBefore = [$this->stored(), $this->stored(5)];

		$this->assertFalse($this->set('is_trial', '1', 999));
		$this->assertSame($rBefore, [$this->stored(), $this->stored(5)]);
	}

	/** @return array<string, array{0: mixed}> */
	public static function valuesThatAreNeitherOnNorOff(): array {
		return ['no value' => [null], 'an empty value' => [''], 'a number' => ['2'], 'a word' => ['undefined'], 'a list' => [['1']]];
	}

	/** The official flag of the package is on: a value that is not read is not taken for off. */
	#[DataProvider('valuesThatAreNeitherOnNorOff')]
	public function testAValueThatIsNeitherOnNorOffIsRefused(mixed $rValue): void {
		$rBefore = $this->stored();
		$this->assertEquals(1, $rBefore['is_official']);

		$this->assertFalse($this->set('is_official', $rValue));
		$this->assertSame($rBefore, $this->stored());
	}

	public function testAStatementTheDatabaseRefusedIsNotAnsweredAsSuccess(): void {
		// The action writes through the global connection: this one refuses the statement.
		$GLOBALS['db'] = new class {
			public function query(string $rQuery, mixed ...$rValues): bool {
				return false;
			}
		};

		$this->assertFalse($this->set('is_trial', '1'));
	}
}

/** The answer the action gave, thrown in place of ending the request. */
final class AuditAdminMiscPackageFlagAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class AuditAdminMiscPackageFlagPanel extends PackageAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminMiscPackageFlagAnswer($rData);
	}
}
