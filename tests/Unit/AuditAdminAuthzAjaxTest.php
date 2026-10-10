<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Reference\PermissionReference;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\ActiveCodeAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\BackupAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\CategoryTemplateAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\MultiAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\SearchAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\StatsAjaxController;
use XcVm\Tests\Support\QueryLogDb;

/**
 * An administrator whose group lists advanced permissions is held to them by
 * the admin-ajax actions: an action runs with the permission of the page that
 * offers it and with no other. Group 1 and a group that lists none keep it all.
 *
 * The actions end the request themselves, so each is driven through a subclass
 * whose json() throws the answer instead, over a database that stops the action
 * at its first statement: "ran" means it got that far.
 */
final class AuditAdminAuthzAjaxTest extends TestCase {
	/** Each action, a request for it and the permissions that open it. */
	private const ACTIONS = [
		'bulk delete of series' => [AuditAdminAuthzMulti::class, 'multi', ['type' => 'series', 'sub' => 'delete', 'ids' => '[5]'], ['edit_series']],
		'bulk action on activation codes' => [AuditAdminAuthzMulti::class, 'multi', ['type' => 'active_code', 'sub' => 'disable', 'ids' => '[5]'], ['edit_user', 'mass_edit_lines']],
		'generate activation codes' => [AuditAdminAuthzActiveCode::class, 'generate', ['package_id' => 1, 'num_codes' => 1], ['add_user']],
		'action on a batch of codes' => [AuditAdminAuthzActiveCode::class, 'batchAction', ['batch_name' => 'B1', 'sub_action' => 'disable'], ['edit_user', 'mass_edit_lines']],
		'export a batch of codes' => [AuditAdminAuthzActiveCode::class, 'exportTxt', ['batch_name' => 'B1'], ['users']],
		'download the panel log' => [AuditAdminAuthzBackup::class, 'downloadPanelLogs', [], ['panel_logs']],
		'the load graph of the dashboard and of a server' => [AuditAdminAuthzStats::class, 'graphStats', ['server_id' => 1], ['index', 'servers']],
	];

	/** The log tables clear_logs empties, each with the permission of the page that lists it. */
	private const LOG_TABLES = [
		'lines_logs' => 'client_request_log',
		'lines_activity' => 'connection_logs',
		'streams_errors' => 'stream_errors',
		'users_credits_logs' => 'credits_log',
		'users_logs' => 'reg_userlog',
		'panel_logs' => 'panel_logs',
	];

	/** Keys a module registers for itself (PermissionRegistry): not in the core list. */
	private const MODULE_KEYS = ['folder_watch_add'];

	private QueryLogDb $rDb;

	/** The first statement the action sent, or null while it sent none. */
	private ?string $rStatement = null;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new QueryLogDb(new TestDb());
		$this->rDb->rBefore = function (string $rQuery): void {
			$this->rStatement = $rQuery;
			throw new AuditAdminAuthzStop();
		};
		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		$this->rRequest = RequestManager::getAll();
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH']);
	}

	/**
	 * Signs in an administrator whose group lists $rAdvanced.
	 *
	 * @param list<string> $rAdvanced
	 */
	private function signIn(array $rAdvanced, int $rGroup = 5): void {
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => $rGroup];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => array_values($rAdvanced)];
	}

	/**
	 * Whether the action ran for the signed-in administrator. False when it
	 * answered {"result":false} and sent the database nothing.
	 *
	 * @param class-string         $rClass
	 * @param array<string, mixed> $rRequest
	 */
	private function runs(string $rClass, string $rAction, array $rRequest): bool {
		RequestManager::set($rRequest);
		$this->rStatement = null;
		$rAnswer = null;
		try {
			(new $rClass())->$rAction();
		} catch (AuditAdminAuthzAnswer $rThrown) {
			$rAnswer = $rThrown->rData;
		} catch (\Throwable $rThrown) {
			if ($this->rStatement === null) {
				throw $rThrown;
			}
		}
		return $this->rStatement !== null || $rAnswer !== ['result' => false];
	}

	public function testAnActionIsRefusedWithoutThePermissionOfItsPage(): void {
		$rRan = [];
		foreach (self::ACTIONS as $rName => [$rClass, $rAction, $rRequest, $rOpening]) {
			$this->signIn(array_diff(PermissionReference::keys(), $rOpening));
			if ($this->runs($rClass, $rAction, $rRequest)) {
				$rRan[] = $rName . ' with every permission but ' . implode(' / ', $rOpening);
			}
		}
		$this->assertSame([], $rRan);
	}

	public function testAnActionRunsWithThePermissionOfItsPage(): void {
		foreach (self::ACTIONS as $rName => [$rClass, $rAction, $rRequest, $rOpening]) {
			foreach ($rOpening as $rKey) {
				$this->assertContains($rKey, PermissionReference::keys());
				$this->signIn([$rKey]);
				$this->assertTrue($this->runs($rClass, $rAction, $rRequest), $rName . ' with ' . $rKey);
			}
		}
	}

	public function testGroupOneAndAGroupWithNoListKeepEveryAction(): void {
		$rActions = self::ACTIONS + [
			'bulk action on lines' => [AuditAdminAuthzMulti::class, 'multi', ['type' => 'line', 'sub' => 'enable', 'ids' => '[5]']],
			'clear a log table' => [AuditAdminAuthzBackup::class, 'clearLogs', ['type' => 'users_logs', 'from' => '', 'to' => '']],
		];
		foreach ($rActions as $rName => [$rClass, $rAction, $rRequest]) {
			$this->signIn(['ticket'], 1);
			$this->assertTrue($this->runs($rClass, $rAction, $rRequest), $rName . ' for group 1');
			$this->signIn([]);
			$this->assertTrue($this->runs($rClass, $rAction, $rRequest), $rName . ' for a group that lists no permissions');
		}
	}

	/** The Lines page is opened with `users` and its rows are changed with `edit_user`: so are its bulk actions. */
	public function testBulkActionsOnLinesRunWithThePermissionThatEditsALine(): void {
		$rRequest = ['type' => 'line', 'sub' => 'enable', 'ids' => '[5]'];
		$this->signIn(['users', 'edit_user']);
		$this->assertTrue($this->runs(AuditAdminAuthzMulti::class, 'multi', $rRequest));
		$this->assertStringContainsString('UPDATE `lines` SET `enabled` = 1', (string) $this->rStatement);
		$this->signIn(array_diff(PermissionReference::keys(), ['edit_user']));
		$this->assertFalse($this->runs(AuditAdminAuthzMulti::class, 'multi', $rRequest), 'with every permission but edit_user');
	}

	/** A key no group can be given opens what it guards for group 1 alone: actions, pages and tables name catalogue keys. */
	/** A search asks only for the kinds of record the group's pages show it: it listed every kind to every group. */
	public function testASearchAsksOnlyForWhatTheGroupsPagesShow(): void {
		foreach ([['series'], ['users'], ['movies']] as $rAdvanced) {
			$this->signIn($rAdvanced);
			$this->assertTrue($this->runs(AuditAdminAuthzSearch::class, 'search', ['search' => 'matrix']), implode(',', $rAdvanced));
			$this->assertStringContainsString(['series' => 'FROM `streams_series`', 'users' => 'FROM `lines`', 'movies' => 'FROM `streams`'][$rAdvanced[0]], (string) $this->rStatement, 'the first table asked');
		}

		$this->signIn(['panel_logs']);
		$this->runs(AuditAdminAuthzSearch::class, 'search', ['search' => 'matrix']);
		$this->assertStringNotContainsString('MATCH(', (string) $this->rStatement, 'none of its pages lists a record: no table is searched');
	}

	/** The category templates' actions ran for any signed-in group: they take the page's permission. */
	public function testTheCategoryTemplateActionsTakeThePagesPermission(): void {
		$rAnswer = function (array $rAdvanced): mixed {
			$this->signIn($rAdvanced);
			RequestManager::set(['id' => 1]);
			$this->rStatement = null;
			try {
				(new AuditAdminAuthzTemplates())->get();
			} catch (AuditAdminAuthzAnswer $rThrown) {
				return $rThrown->rData;
			} catch (\Throwable) {
				return 'ran';
			}
			return null;
		};

		$this->assertSame(['result' => false, 'message' => 'No permission'], $rAnswer(['streams']));
		$this->assertNotSame(['result' => false, 'message' => 'No permission'], $rAnswer(['categories']));
	}

	public function testAdminControllersNameOnlyPermissionsAGroupCanHold(): void {
		$rKnown = array_merge(PermissionReference::keys(), self::MODULE_KEYS);
		$rUnknown = [];
		$rChecked = 0;
		$rDir = MAIN_HOME . 'Public/Controllers/Admin/';
		foreach (array_merge(glob($rDir . '*.php') ?: [], glob($rDir . 'Ajax/*.php') ?: []) as $rFile) {
			// Every literal ('adv', '<key>') pair; a key built at run time ('edit_' . $rType) is not one.
			preg_match_all('/[\'"]adv[\'"]\s*,\s*[\'"](\w+)[\'"](?!\s*\.)/', (string) file_get_contents($rFile), $rPairs);
			$rChecked += count($rPairs[1]);
			foreach (array_diff(array_unique($rPairs[1]), $rKnown) as $rKey) {
				$rUnknown[] = basename($rFile) . ': ' . $rKey;
			}
		}
		$this->assertGreaterThan(150, $rChecked);
		$this->assertSame([], $rUnknown);
	}

	public function testALogTableIsClearedOnlyWithThePermissionOfItsPage(): void {
		$rCleared = [];
		foreach (self::LOG_TABLES as $rTable => $rKey) {
			$this->assertContains($rKey, PermissionReference::keys());
			$rRequest = ['type' => $rTable, 'from' => '', 'to' => ''];
			$this->signIn(array_diff(PermissionReference::keys(), [$rKey]));
			if ($this->runs(AuditAdminAuthzBackup::class, 'clearLogs', $rRequest)) {
				$rCleared[] = $rTable . ' with every permission but ' . $rKey;
			}
			$this->signIn([$rKey]);
			$this->assertTrue($this->runs(AuditAdminAuthzBackup::class, 'clearLogs', $rRequest), $rTable . ' with ' . $rKey);
			$this->assertMatchesRegularExpression('/^TRUNCATE `?' . $rTable . '`?;$/', (string) $this->rStatement);
		}
		$this->assertSame([], $rCleared);
	}

	public function testOnlyALogTableIsCleared(): void {
		foreach (['users', 'lines', 'settings', ''] as $rTable) {
			foreach ([[PermissionReference::keys(), 5], [[], 1]] as [$rAdvanced, $rGroup]) {
				$this->signIn($rAdvanced, $rGroup);
				$this->runs(AuditAdminAuthzBackup::class, 'clearLogs', ['type' => $rTable, 'from' => '', 'to' => '']);
				$this->assertNull($this->rStatement, 'type "' . $rTable . '" for group ' . $rGroup);
			}
		}
	}
}

/** The answer an action gave, thrown in place of ending the request. */
final class AuditAdminAuthzAnswer extends RuntimeException {
	/** @param array<string, mixed> $rData */
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

/** Ends an action at its first statement. */
final class AuditAdminAuthzStop extends RuntimeException {
}

trait AuditAdminAuthzAnswers {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new AuditAdminAuthzAnswer($rData);
	}
}

final class AuditAdminAuthzMulti extends MultiAjaxController {
	use AuditAdminAuthzAnswers;
}

final class AuditAdminAuthzActiveCode extends ActiveCodeAjaxController {
	use AuditAdminAuthzAnswers;
}

final class AuditAdminAuthzBackup extends BackupAjaxController {
	use AuditAdminAuthzAnswers;
}

final class AuditAdminAuthzStats extends StatsAjaxController {
	use AuditAdminAuthzAnswers;
}

final class AuditAdminAuthzSearch extends SearchAjaxController {
	use AuditAdminAuthzAnswers;
}

final class AuditAdminAuthzTemplates extends CategoryTemplateAjaxController {
	use AuditAdminAuthzAnswers;
}
