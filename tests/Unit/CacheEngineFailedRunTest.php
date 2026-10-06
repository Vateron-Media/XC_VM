<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * A cache engine run in which a worker could not write a cache file counts as
 * failed, as one that could not read the database does: the run leaves
 * last_cache where the last good run put it and says so in Panel Logs. A line
 * whose credentials cannot name a file ('/' in them) has no lookup file by
 * design, and does not fail the run.
 *
 * The cache directories are constants, so each run is a child PHP with its own.
 */
final class CacheEngineFailedRunTest extends TestCase {
	private const CHILD = <<<'PHP'
<?php
require %BOOTSTRAP%;
$rIn = json_decode($argv[1], true);
foreach (['LINES_TMP_PATH' => 'lines', 'CACHE_TMP_PATH' => 'cache', 'STREAMS_TMP_PATH' => 'streams', 'SERIES_TMP_PATH' => 'series'] as $rName => $rSub) {
	define($rName, $rIn['dir'] . $rSub . '/');
}
\XcVm\Core\Config\SettingsManager::set(['case_sensitive_line' => 1]);
\XcVm\Core\Logging\FileLogger::setLogFile($rIn['dir'] . 'panel.log');
$rInner = new class (TestDb::connect($rIn['schema'])) extends \XcVm\Core\Database\DatabaseHandler {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
};
// generateLines() reads the statement's result from the handle: the query log only records finishRun()'s.
$rLog = new \XcVm\Tests\Support\QueryLogDb($rInner);
$GLOBALS['db'] = $rIn['do'] === 'lines' ? $rInner : $rLog;
if ($rIn['block'] !== null) {
	// Something in the way of the entry's temporary file: its write fails.
	mkdir(LINES_TMP_PATH . '.' . $rIn['block'] . '.' . getmypid() . '.tmp');
}
$rJob = new \XcVm\Cli\CronJobs\CacheEngineCronJob();
ob_start();
if ($rIn['do'] === 'lines') {
	(new ReflectionMethod($rJob, 'generateLines'))->invoke($rJob, 0, 3);
} else {
	(new ReflectionMethod($rJob, 'finishRun'))->invoke($rJob, $rIn['failed'], time() - 5);
}
$rOutput = ob_get_clean();
echo json_encode(['failed' => file_exists(CACHE_TMP_PATH . 'cache_engine_failed'), 'output' => $rOutput, 'queries' => $rLog->rQueries]);
PHP;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'mag_devices', 'settings'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDir = sys_get_temp_dir() . '/xcvm-engine-failed-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		foreach (['lines', 'cache', 'streams', 'series'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function line(int $rId, string $rPassword): void {
		$this->rDb->query('INSERT INTO `lines` (`id`, `username`, `password`, `bouquet`, `allowed_outputs`, `allowed_ips`, `allowed_ua`) VALUES (?, ?, ?, ?, ?, ?, ?)', $rId, 'user' . $rId, $rPassword, '[1]', '[1,2]', '[]', '[]');
	}

	/** @return array{failed: bool, output: string, queries: list<string>} */
	private function child(array $rIn): array {
		$rIn += ['schema' => $this->rDb->schema(), 'dir' => $this->rDir, 'block' => null, 'failed' => false];
		exec(implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $this->rDir . 'child.php', (string) json_encode($rIn)])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$rAnswer = json_decode(implode("\n", $rOut), true);
		$this->assertIsArray($rAnswer, implode("\n", $rOut));
		return $rAnswer;
	}

	/** @return list<array<string, mixed>> the Panel Logs entries the run left */
	private function logged(): array {
		$rFile = $this->rDir . 'panel.log';
		return is_file($rFile) ? array_map(static fn(string $rLine): array => json_decode((string) base64_decode($rLine), true), file($rFile, FILE_IGNORE_NEW_LINES)) : [];
	}

	public function testAWriteThatFailsMarksTheRunFailed(): void {
		foreach ([1, 2, 3] as $rId) {
			$this->line($rId, 'secret');
		}
		$rRun = $this->child(['do' => 'lines']);
		$this->assertFalse($rRun['failed'], 'every entry written: ' . $rRun['output']);

		$this->assertTrue($this->child(['do' => 'lines', 'block' => 'line_i_2'])['failed']);
	}

	public function testALineWhoseCredentialsCannotNameAFileDoesNotFailTheRun(): void {
		$this->line(1, 'secret');
		$this->line(2, 'ab/cd');

		$rRun = $this->child(['do' => 'lines']);

		$this->assertFalse($rRun['failed']);
		$this->assertFileExists($this->rDir . 'lines/line_i_2', 'the line itself is cached');
	}

	public function testAFailedRunLeavesLastCacheAndIsLogged(): void {
		$rRun = $this->child(['do' => 'finish', 'failed' => true]);

		$this->assertSame([], preg_grep('/last_cache/', $rRun['queries']));
		$this->assertStringNotContainsString('Cache updated!', $rRun['output']);
		$this->assertFileExists($this->rDir . 'cache/cache_complete');
		$rLogged = $this->logged();
		$this->assertCount(1, $rLogged);
		$this->assertSame('cron', $rLogged[0]['type']);
		$this->assertSame('cron:cache_engine', $rLogged[0]['extra']);
	}

	public function testACleanRunMovesLastCache(): void {
		$rRun = $this->child(['do' => 'finish', 'failed' => false]);

		$this->assertCount(1, preg_grep('/UPDATE `settings` SET `last_cache`/', $rRun['queries']));
		$this->assertStringContainsString('Cache updated!', $rRun['output']);
		$this->assertSame([], $this->logged());
	}
}
