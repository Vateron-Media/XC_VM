<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

final class LineCacheConcurrencyTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
require $argv[1];
$in = json_decode($argv[2], true);
define('LINES_TMP_PATH', $in['dir'] . '/lines/');
define('CACHE_TMP_PATH', $in['dir'] . '/cache/');
XcVm\Core\Config\SettingsManager::set(['case_sensitive_line' => 1]);
$db = new class (TestDb::connect($in['schema']), $in) extends XcVm\Core\Database\DatabaseHandler {
	public function __construct(PDO $pdo, private array $in) { $this->dbh = $pdo; }
	public function query(string $query, mixed $buffered = false) {
		$result = parent::query(...func_get_args());
		if ($result && str_contains($query, 'LEFT JOIN `mag_devices`')) {
			file_put_contents($this->in['dir'] . '/read-' . $this->in['role'], '1');
			if ($this->in['hold']) {
				$end = microtime(true) + 10;
				while (!file_exists($this->in['dir'] . '/release') && microtime(true) < $end) { usleep(10000); }
			}
		}
		return $result;
	}
};
$GLOBALS['db'] = $db;
file_put_contents($in['dir'] . '/started-' . $in['role'], '1');
$job = new XcVm\Cli\CronJobs\CacheEngineCronJob();
ob_start();
(new ReflectionMethod($job, 'generateLines'))->invoke($job, $in['full'] ? 0 : null, $in['full'] ? 1 : null, $in['full'] ? [] : [1]);
$output = ob_get_clean();
echo json_encode(['failed' => file_exists(CACHE_TMP_PATH . 'cache_engine_failed'), 'output' => $output]);
PHP;

	private TestDb $db;

	private string $dir;

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['lines', 'mag_devices'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		$this->db->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->db->query("INSERT INTO `lines` (`id`, `username`, `password`, `exp_date`) VALUES (1, 'viewer', 'secret', 111)");
		$this->dir = sys_get_temp_dir() . '/xcvm-cache-race-' . bin2hex(random_bytes(6));
		mkdir($this->dir . '/lines', 0700, true);
		mkdir($this->dir . '/cache', 0700, true);
		file_put_contents($this->dir . '/worker.php', self::CHILD);
	}

	protected function tearDown(): void {
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($files as $file) {
			$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($this->dir);
	}

	private function start(string $role, bool $full, bool $hold): array {
		$input = ['dir' => $this->dir, 'schema' => $this->db->schema(), 'role' => $role, 'full' => $full, 'hold' => $hold];
		$process = proc_open([PHP_BINARY, $this->dir . '/worker.php', dirname(__DIR__) . '/bootstrap.php', json_encode($input)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		return [$process, $pipes];
	}

	private function waitFor(string $file): void {
		$end = microtime(true) + 5;
		while (!file_exists($this->dir . '/' . $file) && microtime(true) < $end) {
			usleep(10000);
		}
		$this->assertFileExists($this->dir . '/' . $file);
	}

	private function finish(array $worker): array {
		[$process, $pipes] = $worker;
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $output . $error);
		$this->assertSame('', $error);
		return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
	}

	public static function builders(): array {
		return ['incremental/incremental' => [false, false], 'full/incremental' => [true, false], 'incremental/full' => [false, true]];
	}

	#[DataProvider('builders')]
	public function testAnOlderReaderCannotOverwriteTheRenewedLine(bool $oldFull, bool $newFull): void {
		$old = $this->start('old', $oldFull, true);
		$new = null;
		try {
			$this->waitFor('read-old');
			$this->db->query('UPDATE `lines` SET `exp_date` = 99999999 WHERE `id` = 1');
			$new = $this->start('new', $newFull, false);
			$this->waitFor('started-new');
			usleep(400000);
			$this->assertFileDoesNotExist($this->dir . '/read-new', 'the newer worker waits before reading, not just before publishing an already stale snapshot');
		} finally {
			touch($this->dir . '/release');
			$this->assertFalse($this->finish($old)['failed']);
			if ($new !== null) {
				$this->assertFalse($this->finish($new)['failed']);
			}
		}
		$cached = igbinary_unserialize(file_get_contents($this->dir . '/lines/line_i_1'));
		$this->assertSame(99999999, (int) $cached['exp_date']);
	}

	public function testALockFailureKeepsTheOldCacheAndMarksTheRunFailed(): void {
		file_put_contents($this->dir . '/cache/line_locks', 'blocked');
		$old = igbinary_serialize(['exp_date' => 111]);
		file_put_contents($this->dir . '/lines/line_i_1', $old);
		$result = $this->finish($this->start('new', false, false));
		$this->assertTrue($result['failed']);
		$this->assertSame($old, file_get_contents($this->dir . '/lines/line_i_1'));
	}
}
