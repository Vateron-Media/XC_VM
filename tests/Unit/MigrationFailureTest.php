<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/** Run the real importer on disposable source and target schemas. */
final class MigrationFailureTest extends TestCase {
	private TestDb $source;

	private TestDb $target;

	private string $dir;

	protected function setUp(): void {
		$this->source = new TestDb();
		$this->target = new TestDb();
		$this->dir = sys_get_temp_dir() . '/xcvm-import-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
		$this->source->exec('CREATE TABLE `access_codes` (`id` int PRIMARY KEY)');
		$this->source->exec('CREATE TABLE `lines` (`id` int PRIMARY KEY, `username` varchar(255), `password` varchar(255))');
		$this->source->exec("CREATE TABLE `settings` (`server_name` varchar(255), `default_timezone` varchar(255))");
		$this->source->exec("INSERT INTO `settings` VALUES ('Import fixture', 'UTC')");
		foreach (['lines', 'settings', 'streams', 'streams_servers', 'recordings', 'cluster_meta', 'cluster_changes', 'cluster_stream_ver'] as $table) {
			$this->target->exec(InstallSchema::table($table));
		}
		$this->target->exec('ALTER TABLE `lines` ADD UNIQUE KEY `test_username` (`username`)');
		$this->target->exec('INSERT INTO `settings` (`id`) VALUES (1)');
		file_put_contents($this->dir . '/.migration.options', '["lines"]');
		file_put_contents($this->dir . '/run.php', <<<'PHP'
<?php

define('TMP_PATH', $argv[5] . '/');
require $argv[1];
$db = new TestDb($argv[3]);
$source = new TestDb($argv[2]);
if (in_array($argv[4], ['seed_failure', 'version_failure'], true)) {
	$db = new XcVm\Tests\Support\QueryLogDb($db);
	$db->rRefuse = $argv[4] === 'seed_failure' ? '/INSERT INTO `cluster_stream_ver`/' : '/cluster_meta/';
}
$source->connected = $argv[4] !== 'disconnected';
XcVm\Core\Events\Migration\LegacyTableMigrationEvent::useDir(TMP_PATH . 'module-backups/');
XcVm\Infrastructure\Database\DatabaseFactory::set($db);
$result = (new XcVm\Cli\Commands\MigrateCommand($source))->execute([]);
echo "\n#RESULT#", json_encode(['result' => $result, 'status' => trim(file_get_contents(TMP_PATH . '.migration.status')), 'pid_file' => file_exists(TMP_PATH . '.migration.pid')]);
exit($result);
PHP);
	}

	protected function tearDown(): void {
		foreach (glob($this->dir . '/*') ?: [] as $file) {
			unlink($file);
		}
		foreach (glob($this->dir . '/.migration.*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->dir);
	}

	private function runImport(string $mode = 'normal'): array {
		$process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $this->dir . '/run.php', dirname(__DIR__) . '/bootstrap.php', $this->source->schema(), $this->target->schema(), $mode, $this->dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		$this->assertSame('', $error, $output);
		$this->assertFileDoesNotExist($this->dir . '/.migration.pid', 'finished imports remove their own PID file');
		$parts = explode('#RESULT#', $output);
		$this->assertCount(2, $parts, $output);
		return ['code' => $code, 'output' => $parts[0]] + json_decode($parts[1], true);
	}

	public function testAFailedInsertKeepsTheEntireSourceAndReportsFailure(): void {
		$this->source->exec("INSERT INTO `lines` VALUES (1, 'duplicate', 'first'), (2, 'duplicate', 'second'), (3, 'later', 'third')");
		$result = $this->runImport();
		$this->assertSame(1, $result['code'], $result['output']);
		$this->assertSame('3', $result['status']);
		$this->assertStringNotContainsString('Migration has been completed!', $result['output']);
		$this->source->query('SELECT COUNT(*) FROM `lines`');
		$this->assertSame(3, (int) $this->source->get_col(), 'all original rows remain available for retry');
	}

	public function testASuccessfulImportCopiesLinesAndThenCleansTheSource(): void {
		$this->source->exec("INSERT INTO `lines` VALUES (1, 'first', 'secret'), (2, 'second', 'secret')");
		$result = $this->runImport();
		$this->assertSame(0, $result['code'], $result['output']);
		$this->assertSame('2', $result['status']);
		$this->assertStringContainsString('Migration has been completed!', $result['output']);
		$this->target->query('SELECT `username` FROM `lines` ORDER BY `id`');
		$this->assertSame(['first', 'second'], $this->target->get_column());
		$this->source->query('SHOW TABLES');
		$this->assertSame([], $this->source->get_rows());
	}

	public function testAConnectionFailureIsANonzeroExitAndKeepsTheSource(): void {
		$this->source->exec("INSERT INTO `lines` VALUES (1, 'first', 'secret')");
		$result = $this->runImport('disconnected');
		$this->assertSame(1, $result['code']);
		$this->assertSame('3', $result['status']);
		$this->source->query('SELECT COUNT(*) FROM `lines`');
		$this->assertSame(1, (int) $this->source->get_col());
	}
	public function testAVersionWriteFailureKeepsTheSource(): void {
		$this->source->exec("INSERT INTO `lines` VALUES (1, 'first', 'secret')");
		foreach (['seed_failure', 'version_failure'] as $mode) {
			$result = $this->runImport($mode);
			$this->assertSame(1, $result['code'], $result['output']);
			$this->assertSame('3', $result['status']);
			$this->assertStringNotContainsString('Migration has been completed!', $result['output']);
			$this->source->query('SELECT COUNT(*) FROM `lines`');
			$this->assertSame(1, (int) $this->source->get_col());
		}
	}

}
