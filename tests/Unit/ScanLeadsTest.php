<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\GroupService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\Ajax\MultiAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\SearchAjaxController;
use XcVm\Public\Controllers\Admin\Ajax\ServerAjaxController;
use XcVm\Tests\Support\InstallSchema;

/**
 * Leads of the 2026-10-10 code scan that reading the code confirmed.
 *
 * The admin-ajax actions end the request themselves, so they are driven
 * through a subclass whose json() throws the answer instead.
 */
final class ScanLeadsTest extends TestCase {
	/** What a bulk delete of streams reads or clears. */
	private const TABLES = [
		'streams', 'streams_servers', 'servers', 'signals', 'lines_logs', 'mag_claims', 'streams_episodes', 'streams_errors',
		'streams_logs', 'streams_options', 'streams_stats', 'watch_refresh', 'recordings', 'lines_activity', 'lines_live',
	];

	/** A reseller's panel action in a child PHP, over this test's schema: the action ends the request itself. */
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
$db = new \XcVm\Core\Database\DatabaseHandler();
\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
\XcVm\Core\Http\RequestManager::set($rIn['request']);
// No guide on disk: the channels answered are what is under test.
defined('EPG_PATH') || define('EPG_PATH', sys_get_temp_dir() . '/xcvm-scan-leads-no-epg/');

\XcVm\Infrastructure\ResellerApiDispatcher::dispatch($rIn['action'], ['id' => 5, 'reports' => [5]], $rIn['permissions']);
PHP;

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (self::TABLES as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		// 3: live, on server 1. 4: live, on servers 1 and 2. 5: live, on servers 1 and 2. 7: a movie, on server 1. 8: a radio, on none.
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`) VALUES (3, 1, 'one'), (4, 1, 'two'), (5, 1, 'three'), (7, 2, 'movie'), (8, 4, 'radio')");
		$this->rDb->exec('INSERT INTO `streams_servers` (`stream_id`, `server_id`) VALUES (3, 1), (4, 1), (4, 2), (5, 1), (5, 2), (7, 1)');

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

	/** @param list<string> $rAdvanced the advanced permissions of the administrator's group; none listed is all of them */
	private function signIn(array $rAdvanced, int $rGroup = 5): void {
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => $rGroup];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => $rAdvanced];
	}

	/** @param list<int|string> $rIDs */
	private function bulk(string $rType, string $rSub, array $rIDs): void {
		RequestManager::set(['type' => $rType, 'sub' => $rSub, 'ids' => json_encode($rIDs)]);
		try {
			(new ScanLeadsMulti())->multi();
		} catch (ScanLeadsAnswer) {
		}
	}

	/** @return list<int> */
	private function streams(): array {
		$this->rDb->query('SELECT `id` FROM `streams` ORDER BY `id`');
		return array_map('intval', array_column($this->rDb->get_rows(), 'id'));
	}

	/** @return list<string> "stream-server" */
	private function placed(): array {
		$this->rDb->query("SELECT CONCAT(`stream_id`, '-', `server_id`) AS `at` FROM `streams_servers` ORDER BY `stream_id`, `server_id`");
		return array_column($this->rDb->get_rows(), 'at');
	}

	/** The permission checked is the type's in the request: it reaches the streams of that type only. */
	public function testABulkActionReachesOnlyTheStreamsOfItsType(): void {
		$this->signIn(['edit_radio']);

		$this->bulk('radio', 'delete', [7, '7-1', 8]);

		$this->assertSame([3, 4, 5, 7], $this->streams(), 'the radio is deleted, the movie named under type=radio is not');
		$this->assertContains('7-1', $this->placed());
	}

	/** A stream taken off its last server is deleted, as the row's own delete does. */
	public function testABulkDeleteDeletesAStreamTakenOffItsLastServer(): void {
		$this->signIn([], 1);

		$this->bulk('stream', 'delete', ['3-1', 4, '5-1']);

		$this->assertSame([5, 7, 8], $this->streams(), 'off its only server, off both its servers: gone. Off one of two: kept');
		$this->assertSame(['5-2', '7-1'], $this->placed());
	}

	/** A deleted group is no other group's sub-reseller group: a reseller paid for an account in it. */
	public function testADeletedGroupLeavesTheOtherGroupsLists(): void {
		foreach (['users_groups', 'users_packages', 'users'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `can_delete`, `subresellers`) VALUES (2, 'Resellers', 1, 1, '[5,7]'), (5, 'Gone', 1, 1, NULL), (7, 'Subs', 1, 1, '[]')");

		$this->assertTrue(GroupService::deleteById(5));

		$this->rDb->query('SELECT `group_id`, `subresellers` FROM `users_groups` ORDER BY `group_id`');
		$this->assertSame([2 => '[7]', 7 => '[]'], array_column($this->rDb->get_rows(), 'subresellers', 'group_id'));
	}

	/** A code holds no term of its own: the package codes not redeemed yet are sold for is not deleted. */
	public function testAPackageWithCodesNotRedeemedYetIsNotDeleted(): void {
		foreach (['users_packages', 'activation_codes', 'lines'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `official_duration`, `official_duration_in`) VALUES (9, 'Year', 12, 'months')");
		$this->rDb->exec("INSERT INTO `activation_codes` (`id`, `activation_code`, `package_id`, `status`) VALUES (1, 'AAAA1111', 9, 1)");

		$this->assertFalse(PackageService::deleteById(9));
		$this->assertIsArray(PackageService::getById(9));

		$this->rDb->exec('UPDATE `activation_codes` SET `status` = 2, `activated_at` = 1 WHERE `id` = 1');
		$this->assertTrue(PackageService::deleteById(9));
	}

	/** An id is a number and a code is text: a code of digits is not another code's id. */
	public function testACodeOfDigitsIsNotReadAsAnId(): void {
		$this->assertSame(12, ActiveCodeService::reference('12', null));
		$this->assertSame(12, ActiveCodeService::reference(12, 'ignored'));
		$this->assertSame('00001234', ActiveCodeService::reference(null, '00001234'), 'the code parameter is a code');
		$this->assertSame('AB12', ActiveCodeService::reference('AB12', null), 'an id that is no number names a code, as before');
		$this->assertSame('', ActiveCodeService::reference(null, null));

		$this->rDb->exec(InstallSchema::table('activation_codes'));
		$this->rDb->exec("INSERT INTO `activation_codes` (`id`, `activation_code`, `status`, `mac`) VALUES (1234, 'OTHER', 2, 'AA'), (5, '00001234', 2, 'BB')");

		$this->assertSame('SUCCESS', ActiveCodeService::resetDevice('00001234', [], true)['status'] ?? null);

		$this->rDb->query('SELECT `id`, `mac` FROM `activation_codes` ORDER BY `id`');
		$this->assertSame([5 => null, 1234 => 'AA'], array_column($this->rDb->get_rows(), 'mac', 'id'), 'the device of code 00001234 is reset, not that of the code with id 1234');
	}

	/** A prebuffer shorter than a segment is one segment: it was array_slice(..., -0), the whole playlist. */
	public function testAPrebufferShorterThanASegmentIsOneSegment(): void {
		$rPlaylist = sys_get_temp_dir() . '/xcvm-scan-leads-' . getmypid() . '.m3u8';
		file_put_contents($rPlaylist, "#EXTM3U\n" . implode('', array_map(static fn(int $i): string => "#EXTINF:8.0,\n9_{$i}.ts\n", range(1, 6))));

		try {
			$this->assertSame(['9_6.ts'], StreamUtils::getPlaylistSegments($rPlaylist, 6, 8), 'six seconds of eight-second segments');
			$this->assertSame(['9_4.ts', '9_5.ts', '9_6.ts'], StreamUtils::getPlaylistSegments($rPlaylist, 30, 10));
			$this->assertCount(6, StreamUtils::getPlaylistSegments($rPlaylist, -1));
		} finally {
			unlink($rPlaylist);
		}
	}

	/** An archive is what its content says: an upload has no extension, and a stored one is named .zip whatever it is. */
	public function testAnArchiveIsKnownByItsContent(): void {
		$rFile = sys_get_temp_dir() . '/xcvm-scan-leads-' . getmypid() . '_1.0.0.zip';
		$rKinds = [
			'zip' => "PK\x03\x04rest",
			'tar' => str_pad('module.json', 257, "\0") . "ustar\0" . str_repeat("\0", 250),
			'gzip' => (string) gzencode('a tar'),
			'json' => '{"result":false,"error":"missing"}',
			'empty' => '',
		];

		try {
			$rSeen = [];
			foreach ($rKinds as $rName => $rBytes) {
				file_put_contents($rFile, $rBytes);
				$rSeen[$rName] = ModuleManager::archiveKind($rFile);
			}
			$this->assertSame(['zip' => 'zip', 'tar' => 'tar', 'gzip' => 'tar', 'json' => null, 'empty' => null], $rSeen);
		} finally {
			unlink($rFile);
		}
	}

	/** The proxy list's disable is the server list's: neither turns MAIN off. */
	public function testAProxyActionDoesNotDisableMain(): void {
		$this->signIn([], 1);
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_name`, `is_main`, `enabled`) VALUES (1, 'main', 1, 1), (2, 'proxy', 0, 1)");

		foreach ([1, 2] as $rServerID) {
			RequestManager::set(['sub' => 'disable', 'server_id' => $rServerID]);
			try {
				(new ScanLeadsServer())->proxy();
			} catch (ScanLeadsAnswer) {
			}
		}

		$this->rDb->query('SELECT `id`, `enabled` FROM `servers` ORDER BY `id`');
		$this->assertSame([1 => 1, 2 => 0], array_map('intval', array_column($this->rDb->get_rows(), 'enabled', 'id')));
	}

	/**
	 * What this suite cannot run (a table that ends the request, a page's
	 * script, Redis): the fix is in the source.
	 */
	public function testTheFixesThatCannotRunHereAreInPlace(): void {
		$rSource = static fn(string $rFile): string => (string) file_get_contents(MAIN_HOME . $rFile);

		// A reseller's REST tables are rendered in the request: the HTTP round trip reached no table.
		$rWrapper = $rSource('Public/Controllers/Api/ResellerAPIWrapper.php');
		$this->assertStringContainsString('(new ResellerTableController())->index();', $rWrapper);
		$this->assertStringNotContainsString('curl_init', $rWrapper);

		// A connection the filter leaves out is not listed (Redis).
		foreach (['Public/Controllers/Admin/TableController.php', 'Infrastructure/ResellerTableRenderer.php'] as $rFile) {
			$this->assertMatchesRegularExpression('/\$rKeyCount--;\s+continue;/', $rSource($rFile), $rFile);
		}

		// A created channel's on-demand servers are on its form.
		$this->assertStringContainsString('$rOnDemand[] = intval($rServer[\'id\']);', $rSource('Public/Controllers/Admin/CreatedChannelController.php'));

		// Root's heartbeat is renamed in (AtomicFileTest: a link under the name is replaced, not followed).
		$this->assertStringContainsString("AtomicFile::write(CONFIG_PATH . 'signals.last'", $rSource('Cli/CronJobs/RootSignalsCronJob.php'));

		// The review posts the rows of every page of its table.
		$this->assertStringContainsString("rows().nodes().to$().find('input[type=\"hidden\"]')", $rSource('Public/Views/admin/review.php'));
	}

	/**
	 * The answer of a reseller's panel action.
	 *
	 * @param array<string, mixed> $rRequest
	 * @param list<int>            $rStreamIDs the streams of the reseller's packages
	 */
	private function reseller(string $rAction, array $rRequest, array $rStreamIDs): mixed {
		$rChild = sys_get_temp_dir() . '/xcvm-scan-leads-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($rChild, str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
		$rIn = ['schema' => $this->rDb->schema(), 'action' => $rAction, 'request' => $rRequest, 'permissions' => ['can_view_vod' => 1, 'stream_ids' => $rStreamIDs]];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $rChild, (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		@unlink($rChild);
		$this->assertSame('', $rErr, $rOut);
		return json_decode($rOut, true);
	}

	/**
	 * A reseller's guide lists the streams of its packages: the list asked for
	 * is the request's. A stream in no category is listed too: it ended the answer.
	 */
	public function testAResellersGuideHoldsOnlyTheStreamsOfItsPackages(): void {
		$this->rDb->exec(InstallSchema::table('streams_categories'));

		$rGuide = $this->reseller('get_epg', ['channels' => '3,7,4'], [3, 5]);

		$this->assertSame([3], array_map('intval', array_column($rGuide['Channels'], 'Id')), 'streams 7 and 4 are not in its packages');
		$this->assertSame('No Category', $rGuide['Channels'][0]['CategoryName']);
	}

	/** A device has no ban or enable state of its own: the buttons offered are its line's. */
	public function testADeviceFoundBySearchIsOfferedWhatItsLineAllows(): void {
		$this->signIn([], 1);
		$rLine = ['id' => 12, 'member_id' => 0, 'admin_enabled' => 1, 'enabled' => 1, 'exp_date' => null, 'is_trial' => 0, 'is_restreamer' => 0, 'last_activity' => null, 'last_activity_array' => '[]'];
		$rBuild = new ReflectionMethod(SearchAjaxController::class, 'buildDeviceItem');

		$rItem = $rBuild->invoke(new SearchAjaxController(), ['table' => 'mag_devices', 'mag_id' => 4, 'user_id' => 12, 'mac' => '00:1A:79:00:00:01'], ['rDeviceLines' => [12 => $rLine], 'rLineConnectionCount' => [], 'rOwnerNames' => []]);

		$this->assertSame(['Edit', 'Kill Connections', 'Ban', 'Disable'], array_slice(array_column($rItem['actions'], 'title'), 0, 4), 'an enabled line that is not banned was offered Unban and Enable');
	}
}

/** The answer an action gave, thrown in place of ending the request. */
final class ScanLeadsAnswer extends RuntimeException {
}

trait ScanLeadsAnswers {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new ScanLeadsAnswer((string) json_encode($rData));
	}
}

final class ScanLeadsMulti extends MultiAjaxController {
	use ScanLeadsAnswers;
}

final class ScanLeadsServer extends ServerAjaxController {
	use ScanLeadsAnswers;
}
