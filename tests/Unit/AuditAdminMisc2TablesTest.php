<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Reference\PermissionReference;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Tests\Support\InstallSchema;

/**
 * A table of ./table answers an administrator whose group holds the
 * permission of a page that shows it. Each mass-edit page shows the table of
 * the list it edits, so that table takes the permission of the mass-edit page
 * as well as the one of its list, and no other: the table of lines is not
 * read with the permission that mass-edits the users. The table of the
 * providers' streams, which the stream and movie forms show, takes the
 * permissions the admin API asks for the same rows. Group 1 and a group that
 * lists no permissions read every table.
 *
 * A table ends the request itself, so each runs in a child PHP over the
 * test's schema: it prints its rows, or nothing when it refuses.
 */
final class AuditAdminMisc2TablesTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
// xcvm_core's part in a connection: the test's schema.
final class XC_VM {
	public static function db_connect(bool $rMigrate = false) {
		return TestDb::connect($GLOBALS['rIn']['schema']);
	}
}

require %BOOTSTRAP%;

$rIn = json_decode($argv[1], true);

\XcVm\Core\Config\SettingsManager::set(['redis_handler' => 0]);
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
$rSettings = ['date_format' => 'Y-m-d'];
$rUserInfo = ['id' => 9, 'member_group_id' => $rIn['group']];
$rPermissions = ['is_admin' => 1, 'advanced' => $rIn['advanced']];
// What DataTables sends with a page of a table.
\XcVm\Core\Http\RequestManager::set(['draw' => 1]);

$rHandler = new ReflectionMethod(\XcVm\Public\Controllers\Admin\TableController::class, $rIn['handler']);
$rHandler->setAccessible(true);
$rHandler->invoke(new \XcVm\Public\Controllers\Admin\TableController(), ['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], 0, 1000, false);
PHP;

	/** The permissions of the pages that show the table of the providers' streams. */
	private const PROVIDER_STREAMS = ['streams', 'add_stream', 'edit_stream', 'add_movie', 'edit_movie'];

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'lines_live', 'lines_activity', 'streams', 'mag_devices', 'enigma2_devices', 'providers', 'providers_streams'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		// A reseller with a line, a MAG device and an Enigma2 device, and a provider with one stream.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`) VALUES (2, 'Resellers', 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`) VALUES (5, 'reseller', 2)");
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `is_mag`, `is_e2`) VALUES (7, 5, 'viewer', 'secret', 0, 0), (8, 5, 'magline', 'secret', 1, 0), (9, 5, 'e2line', 'secret', 0, 1)");
		$this->rDb->exec("INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (1, 8, '" . base64_encode('00:1A:79:00:00:01') . "')");
		$this->rDb->exec("INSERT INTO `enigma2_devices` (`device_id`, `user_id`, `mac`) VALUES (1, 9, '00:1A:79:00:00:02')");
		$this->rDb->exec("INSERT INTO `providers` (`id`, `name`, `ip`, `port`, `username`, `password`, `enabled`, `status`) VALUES (3, 'Upstream', '192.0.2.50', 80, 'feed', 'feedsecret', 1, 1)");
		$this->rDb->exec("INSERT INTO `providers_streams` (`provider_id`, `stream_id`, `stream_display_name`, `type`) VALUES (3, 44, 'News', 'live')");

		$this->rDir = sys_get_temp_dir() . '/xcvm-misc2-tables-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['db']);
	}

	/**
	 * One table of ./table, asked by an administrator whose group lists $rAdvanced.
	 *
	 * @param list<string> $rAdvanced
	 * @return list<int>|null the record of each of its rows (the line, for a device), null when it answered nothing
	 */
	private function rows(string $rHandler, array $rAdvanced, int $rGroup = 5): ?array {
		$rIn = ['schema' => $this->rDb->schema(), 'handler' => $rHandler, 'advanced' => array_values($rAdvanced), 'group' => $rGroup];
		$rProc = proc_open([...xcvm_test_child_php(), $this->rDir . 'child.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		if ($rOut === '') {
			return null;
		}

		$rRows = json_decode($rOut, true)['data'] ?? null;
		$this->assertIsArray($rRows, $rOut . $rErr);

		return array_map(static fn(array $rRow): int => (int) ($rRow['line_id'] ?? $rRow['id']), $rRows);
	}

	/** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}> the page, its permission, its table and the handler, the permission of the list, the row */
	public static function massEditPages(): array {
		return [
			'Mass Edit Lines' => ['line_mass', 'mass_edit_lines', 'lines', 'handleLines', 'users', 7],
			'Mass Edit Users' => ['user_mass', 'mass_edit_users', 'reg_users', 'handleRegUsers', 'mng_regusers', 5],
			'Mass Edit Mags' => ['mag_mass', 'mass_edit_mags', 'mags', 'handleMags', 'manage_mag', 8],
			'Mass Edit Enigmas' => ['enigma_mass', 'mass_edit_enigmas', 'enigmas', 'handleEnigmas', 'manage_e2', 9],
		];
	}

	#[DataProvider('massEditPages')]
	public function testAMassEditPageReadsTheTableItShows(string $rPage, string $rPermission, string $rTable, string $rHandler, string $rListPermission, int $rRow): void {
		// The page opens with its permission, and asks ./table for this table.
		$GLOBALS['db'] = new stdClass();
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => [$rPermission]];
		$this->assertTrue(PageAuthorization::checkPermissions($rPage, false));
		$this->assertStringContainsString("d.id = '" . $rTable . "';", (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/' . $rPage . '.php'));
		$this->assertMatchesRegularExpression('/case "' . $rTable . '":\s+\$this->' . $rHandler . '\(/', (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/TableController.php'));

		$this->assertSame([$rRow], $this->rows($rHandler, [$rPermission]), 'with the permission of the page');
		$this->assertSame([$rRow], $this->rows($rHandler, [$rListPermission]), 'with the permission of the list');
		$this->assertNull($this->rows($rHandler, array_diff(PermissionReference::keys(), [$rPermission, $rListPermission])), 'with every other permission');
	}

	public function testTheTableOfLinesIsNotReadWithThePermissionThatMassEditsTheUsers(): void {
		$this->assertNull($this->rows('handleLines', ['mass_edit_users']));
	}

	public function testTheTableOfTheProvidersStreamsTakesThePermissionsOfThePagesThatShowIt(): void {
		// What the admin API asks for the same rows.
		$this->assertSame(self::PROVIDER_STREAMS, AdminApiController::ACTION_PERMISSIONS['get_provider_streams']);

		$this->assertNull($this->rows('handleProviderStreams', array_diff(PermissionReference::keys(), self::PROVIDER_STREAMS)), 'with every other permission');
		foreach (self::PROVIDER_STREAMS as $rPermission) {
			$this->assertSame([3], $this->rows('handleProviderStreams', [$rPermission]), 'with ' . $rPermission);
		}
		$this->assertSame([3], $this->rows('handleProviderStreams', ['ticket'], 1), 'group 1');
		$this->assertSame([3], $this->rows('handleProviderStreams', []), 'a group that lists no permissions');
	}
}
