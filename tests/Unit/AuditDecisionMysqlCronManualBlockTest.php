<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\BlocklistAjaxController;
use XcVm\Tests\Support\InstallSchema;

/**
 * A block an admin makes from a log page (System, Login or Restream Logs,
 * action=mysql_syslog) keeps the note the admin gave, as the Block IP form
 * does, or `Manual block` when none was given: it is not noted as a MySQL
 * brute force, which the panel no longer detects.
 *
 * The action ends the request itself, so it is driven through a subclass
 * whose json() throws the answer instead.
 */
final class AuditDecisionMysqlCronManualBlockTest extends TestCase {
	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		if (!defined('FLOOD_TMP_PATH')) {
			define('FLOOD_TMP_PATH', sys_get_temp_dir() . '/xcvm_flood_' . getmypid() . '/');
		}
		@mkdir(FLOOD_TMP_PATH, 0775, true);
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('blocked_ips'));
		$this->rDb->exec(InstallSchema::table('cluster_changes'));

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
	}

	protected function tearDown(): void {
		@unlink(FLOOD_TMP_PATH . 'block_203.0.113.7');
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/** @return array<string, array{0: mixed, 1: string}> the note sent (null: none) and the note stored */
	public static function notes(): array {
		return [
			'no note' => [null, 'Manual block'],
			'an empty note' => ['  ', 'Manual block'],
			'a list' => [['x'], 'Manual block'],
			'the admin\'s note' => ['Scanning the panel from a VPS', 'Scanning the panel from a VPS'],
		];
	}

	#[DataProvider('notes')]
	public function testTheBlockKeepsTheAdminsNoteOrSaysItIsManual(mixed $rNotes, string $rStored): void {
		RequestManager::set(array_filter(['sub' => 'block', 'ip' => '203.0.113.7', 'notes' => $rNotes], static fn($rSent): bool => $rSent !== null));
		try {
			(new AuditDecisionMysqlCronManualBlockPanel())->mysqlSyslog();
		} catch (AuditDecisionMysqlCronManualBlockAnswer $rAnswer) {
			$this->assertSame(['result' => true], $rAnswer->rData);
		}

		$this->assertSame([['203.0.113.7', $rStored]], $this->rDb->pdo->query('SELECT `ip`, `notes` FROM `blocked_ips`')->fetchAll(PDO::FETCH_NUM));
		$this->assertFileExists(FLOOD_TMP_PATH . 'block_203.0.113.7');
	}
}

/** The answer the action gave, thrown in place of ending the request. */
final class AuditDecisionMysqlCronManualBlockAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class AuditDecisionMysqlCronManualBlockPanel extends BlocklistAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditDecisionMysqlCronManualBlockAnswer($rData);
	}
}
