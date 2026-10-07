<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every install starts: the server is marked as being installed and its
 * background command is run, a load balancer that installs in cluster mode 2
 * with the Redis connection handler on included (a node in mode 2 never opens
 * MAIN's Redis: ConnectionTracker::openStore).
 *
 * Three entry points are held to it: the install form for a new server, the
 * form for a server that exists, and the reinstall_server action. Each ends in
 * a background command, so it runs in a child PHP where nothing is started:
 * the command line is kept instead.
 */
final class AuditAdminUsersInstallStartTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
namespace {
	$rIn = json_decode($argv[1], true);
	define('BIN_PATH', $rIn['dir']);
	define('SERVER_ID', 1);
	require getenv('XCVM_TEST_BOOTSTRAP');
}

namespace XcVm\Domain\Server {
	// Nothing is started: the command line is kept.
	function shell_exec(string $rCommand) {
		$GLOBALS['rStarted'][] = $rCommand;
		return null;
	}
}

namespace XcVm\Public\Controllers\Admin\Ajax {
	function shell_exec(string $rCommand) {
		$GLOBALS['rStarted'][] = $rCommand;
		return null;
	}

	/** The reinstall action, its answer kept in place of ending the request. */
	final class AuditAdminUsersReinstall extends ServerAjaxController {
		protected function json(array $rData, int $rFlags = 0): never {
			throw new \AuditAdminUsersReinstallAnswer($rData);
		}
	}
}

namespace {
	final class AuditAdminUsersReinstallAnswer extends RuntimeException {
		public function __construct(public array $rData) {
			parent::__construct('answered');
		}
	}

	foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
		defined($rName) || define($rName, $rValue);
	}

	$rStarted = [];
	$db = new \TestDb();
	$db->exec(\XcVm\Tests\Support\InstallSchema::serversTable());
	foreach (['029_create_cluster_nodes', '032_create_cluster_audit', '035_add_cluster_epoch_eph', '039_add_cluster_node_root_ready', '041_add_cluster_node_features', '045_add_cluster_node_audit', '052_add_cluster_node_db_revoked_at'] as $rName) {
		$db->exec(\XcVm\Tests\Support\InstallSchema::migration($rName));
	}
	$db->exec("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `server_type`, `status`, `is_main`, `parent_id`) VALUES"
		. " (1, 'main', '192.0.2.1', 0, 1, 1, '[]'), (9, 'lb', '192.0.2.9', 0, 1, 0, '[]'), (11, 'proxy', '192.0.2.11', 1, 1, 0, '[9]')");
	if ($rIn['node_mode'] !== null) {
		$db->query("INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `created_at`, `updated_at`) VALUES (9, '00000000-0000-4000-a000-000000000009', 'active', ?, 0, 0)", $rIn['node_mode']);
	}
	\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
	\XcVm\Core\Config\SettingsManager::set($rIn['settings']);
	\XcVm\Core\Localization\Translator::init(MAIN_HOME . 'Core/Localization/lang');

	$rUserInfo = ['id' => 1, 'member_group_id' => 1];
	$rPermissions = ['is_admin' => 1, 'advanced' => []];
	$rServers = \XcVm\Domain\Server\ServerRepository::getStreamingSimple($rPermissions, 'all');
	$rProxies = \XcVm\Domain\Server\ServerRepository::getProxySimple($rPermissions);
	$rSsh = ['ssh_port' => '22', 'root_username' => 'root', 'root_password' => 'secret'];

	if ($rIn['via'] == 'new') {
		$rAnswer = \XcVm\Domain\Server\ServerService::install($rSsh + ['type' => (string) $rIn['type'], 'server_name' => 'new', 'server_ip' => '192.0.2.20', 'parent_id' => '[9]', 'http_broadcast_port' => '80', 'https_broadcast_port' => '443'], $rServers, $rProxies);
	} elseif ($rIn['via'] == 'form') {
		$rAnswer = \XcVm\Domain\Server\ServerService::install($rSsh + ['type' => (string) $rIn['type'], 'edit' => (string) $rIn['server'], 'parent_id' => '[9]', 'http_broadcast_port' => '80', 'https_broadcast_port' => '443'], $rServers, $rProxies);
	} else {
		mkdir(BIN_PATH . 'install/', 0700, true);
		file_put_contents(BIN_PATH . 'install/' . $rIn['server'] . '.json', json_encode(['root_username' => 'root', 'ssh_port' => 22] + ($rIn['type'] == 1 ? ['http_broadcast_port' => 80, 'https_broadcast_port' => 443] : [])));
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		// The action reads the panel's server list: load balancers and proxies.
		$rServers += $rProxies;
		\XcVm\Core\Http\RequestManager::set(['server_id' => (string) $rIn['server'], 'root_password' => 'secret']);
		try {
			(new \XcVm\Public\Controllers\Admin\Ajax\AuditAdminUsersReinstall())->reinstallServer();
		} catch (\AuditAdminUsersReinstallAnswer $rThrown) {
			$rAnswer = $rThrown->rData;
		}
	}

	$db->query('SELECT `id`, `status` FROM `servers` ORDER BY `id`');
	echo json_encode([
		'answer' => array_diff_key($rAnswer, ['data' => 0]),
		'servers' => array_column($db->get_rows(), 'status', 'id'),
		'started' => $rStarted,
	]);
}
PHP;

	private const MODE_TWO_FOR_NEW_NODES = ['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api'];
	private const CLUSTER_API = ['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'legacy'];
	private const REDIS = ['redis_handler' => 1];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-install-refusal-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
		file_put_contents($this->rDir . 'run.php', self::CHILD);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Ask for an install through an entry point.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param int|null             $rNodeMode the cluster mode of load balancer 9, null when it is not a cluster node
	 * @return array{answer: array<string, mixed>, servers: array<int, int>, started: list<string>}
	 */
	private function install(string $rVia, array $rSettings, ?int $rNodeMode = null, int $rType = 2, int $rServer = 9): array {
		$rIn = ['via' => $rVia, 'settings' => $rSettings, 'node_mode' => $rNodeMode, 'type' => $rType, 'server' => ($rVia == 'new' ? 0 : $rServer), 'dir' => $this->rDir . 'bin/'];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'run.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'PATH' => (string) getenv('PATH')] + TestDb::env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);
		return $rResult;
	}

	/** @return array<string, array{0: string, 1: array<string, mixed>, 2: int|null, 3?: int, 4?: int}> */
	public static function startedInstalls(): array {
		return [
			'a new load balancer, new nodes in mode 2' => ['new', self::MODE_TWO_FOR_NEW_NODES + self::REDIS, null],
			'the form, new nodes in mode 2' => ['form', self::MODE_TWO_FOR_NEW_NODES + self::REDIS, null],
			'the form, a node in mode 2' => ['form', self::CLUSTER_API + self::REDIS, 2],
			'the action, new nodes in mode 2' => ['action', self::MODE_TWO_FOR_NEW_NODES + self::REDIS, 1],
			'the action, a node in mode 2' => ['action', self::CLUSTER_API + self::REDIS, 2],
			'a new load balancer, the Redis handler off' => ['new', self::MODE_TWO_FOR_NEW_NODES, null],
			'the form, the Redis handler off' => ['form', self::MODE_TWO_FOR_NEW_NODES, 2],
			'the form, a node in mode 1' => ['form', self::CLUSTER_API + self::REDIS, 1],
			'the form, no cluster API' => ['form', self::REDIS, null],
			'the form, a node left in mode 2 with the cluster API off' => ['form', ['cluster_api_enabled' => 0, 'lb_new_node_mode' => 'api'] + self::REDIS, 2],
			'the form, a proxy' => ['form', self::MODE_TWO_FOR_NEW_NODES + self::REDIS, null, 1, 11],
			'the action, the Redis handler off' => ['action', self::CLUSTER_API, 2],
			'the action, a node in mode 1' => ['action', self::CLUSTER_API + self::REDIS, 1],
			'the action, a proxy' => ['action', self::MODE_TWO_FOR_NEW_NODES + self::REDIS, null, 1, 11],
		];
	}

	/** @param array<string, mixed> $rSettings */
	#[DataProvider('startedInstalls')]
	public function testEveryInstallStarts(string $rVia, array $rSettings, ?int $rNodeMode, int $rType = 2, int $rServer = 9): void {
		$rAfter = $this->install($rVia, $rSettings, $rNodeMode, $rType, $rServer);
		$rInstalled = ($rVia == 'new' ? 12 : $rServer);

		$this->assertCount(1, $rAfter['started']);
		$this->assertStringContainsString('console.php server:install ' . $rType . ' ' . $rInstalled . ' 22 - -', $rAfter['started'][0]);
		$this->assertEquals(3, $rAfter['servers'][$rInstalled], 'the server is marked as being installed');
		$this->assertSame($rVia == 'action' ? ['result' => true] : ['status' => STATUS_SUCCESS], $rAfter['answer']);
	}
}
