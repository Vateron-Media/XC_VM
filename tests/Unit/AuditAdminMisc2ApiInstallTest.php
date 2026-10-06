<?php

use PHPUnit\Framework\TestCase;

/**
 * The admin API installs what the panel's install form installs: install_server
 * a load balancer (the install command's type 2), install_proxy a proxy
 * (type 1). Each adds the server's row, marks it as being installed and starts
 * the install command for it.
 *
 * An install ends in a background command, so the action runs in a child PHP
 * where nothing is started: the command line is kept instead.
 */
final class AuditAdminMisc2ApiInstallTest extends TestCase {
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

namespace {
	foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
		defined($rName) || define($rName, $rValue);
	}

	$rStarted = [];
	$db = new \TestDb();
	foreach (['servers', 'users', 'users_groups'] as $rTable) {
		$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
	}
	$db->exec("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `server_type`, `status`, `is_main`, `parent_id`) VALUES"
		. " (1, 'main', '192.0.2.1', 0, 1, 1, '[]'), (9, 'lb', '192.0.2.9', 0, 1, 0, '[]')");
	$db->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`) VALUES (1, 'Administrators', 1, 0, '[]')");
	$db->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES (1, 'admin', 1, 1, '', '11111111111111111111111111111111')");
	\XcVm\Infrastructure\Database\DatabaseFactory::set($db);
	// A new load balancer installs in API mode: MAIN grants it no database access.
	\XcVm\Core\Config\SettingsManager::set(['cluster_api_enabled' => 1, 'lb_new_node_mode' => 'api']);

	\XcVm\Core\Http\RequestManager::set(['api_key' => '11111111111111111111111111111111', 'action' => $rIn['action']] + $rIn['data']);
	ob_start();
	(new \XcVm\Public\Controllers\Api\AdminApiController())->index();
	$rAnswer = json_decode((string) ob_get_clean(), true);

	$db->query('SELECT `id`, `server_type`, `status` FROM `servers` WHERE `id` > 9');
	echo json_encode(['status' => $rAnswer['status'] ?? null, 'added' => $db->get_rows(), 'started' => $rStarted]);
}
PHP;

	/** What an API client sends with either install. */
	private const REQUEST = ['server_name' => 'new', 'server_ip' => '192.0.2.20', 'ssh_port' => '22', 'root_username' => 'root', 'root_password' => 'secret'];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-misc2-api-install-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
		file_put_contents($this->rDir . 'run.php', self::CHILD);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Ask the API for an install.
	 *
	 * @param array<string, string> $rData
	 * @return array{status: string|null, added: list<array<string, mixed>>, started: list<string>}
	 */
	private function ask(string $rAction, array $rData): array {
		$rIn = ['action' => $rAction, 'data' => $rData, 'dir' => $this->rDir . 'bin/'];
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', $this->rDir . 'run.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'PATH' => (string) getenv('PATH')] + TestDb::env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);
		return $rResult;
	}

	public function testInstallServerInstallsALoadBalancer(): void {
		$rAfter = $this->ask('install_server', self::REQUEST);

		$this->assertSame('STATUS_SUCCESS', $rAfter['status']);
		$this->assertEquals([['id' => 10, 'server_type' => 0, 'status' => 3]], $rAfter['added'], 'a load balancer, marked as being installed');
		$this->assertCount(1, $rAfter['started']);
		$this->assertStringContainsString('console.php server:install 2 10 22 - - 80 443 0 ', $rAfter['started'][0]);
	}

	public function testInstallProxyInstallsAProxy(): void {
		$rAfter = $this->ask('install_proxy', self::REQUEST + ['parent_id' => '[9]', 'http_broadcast_port' => '8080', 'https_broadcast_port' => '8443']);

		$this->assertSame('STATUS_SUCCESS', $rAfter['status']);
		$this->assertEquals([['id' => 10, 'server_type' => 1, 'status' => 3]], $rAfter['added'], 'a proxy, marked as being installed');
		$this->assertCount(1, $rAfter['started']);
		$this->assertStringContainsString('console.php server:install 1 10 22 - - 8080 8443 0 0 ', $rAfter['started'][0]);
	}

	/** A request without the SSH access of the server installs nothing. */
	public function testAnInstallWithoutTheSshAccessIsRefused(): void {
		$rAfter = $this->ask('install_server', array_diff_key(self::REQUEST, ['root_password' => 0]));

		$this->assertSame(['status' => 'STATUS_INVALID_INPUT', 'added' => [], 'started' => []], $rAfter);
	}
}
