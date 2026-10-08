<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Vod\SeriesService;

/**
 * Deleting a series deletes its episodes, and the bouquets are scanned for
 * what is gone once, after the last of them: the scan is a process of its
 * own (BouquetService::scan()), and one per episode would start as many at
 * once as the series has episodes.
 *
 * The delete runs in a child PHP where nothing is started: the scans it asks
 * for are counted.
 */
final class AuditBouquetFollowSeriesDeleteTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-seriesdelete-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Call $rMethod with $rID on a panel with series 1 (episodes 11, 12, 13),
	 * series 2 (episode 21) and the movie 30.
	 *
	 * @return array{scans: list<string>, streams: list<int>, episodes: list<int>, series: list<int>}
	 */
	private function after(string $rMethod, int $rID): array {
		$rScript = $this->rDir . 'run.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace XcVm\Domain\Bouquet {
				// Nothing is started: the line is kept.
				function shell_exec(string $rCommand) {
					file_put_contents(getenv('XCVM_TEST_DIR') . 'scans', $rCommand . "\n", FILE_APPEND);
					return null;
				}
			}

			namespace {
				require getenv('XCVM_TEST_BOOTSTRAP');
				$rDb = new \TestDb();
				foreach (['streams', 'streams_series', 'streams_episodes', 'streams_servers', 'streams_errors', 'streams_logs', 'streams_options', 'streams_stats', 'lines_logs', 'lines_live', 'lines_activity', 'mag_claims', 'recordings', 'servers', 'watch_refresh'] as $rTable) {
					$rDb->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
				}
				$rDb->exec('INSERT INTO `streams` (`id`, `type`) VALUES (11, 5), (12, 5), (13, 5), (21, 5), (30, 2)');
				$rDb->exec("INSERT INTO `streams_series` (`id`, `title`) VALUES (1, 'Show'), (2, 'Other')");
				$rDb->exec('INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 1, 11), (1, 2, 1, 12), (1, 3, 1, 13), (1, 1, 2, 21)');
				\XcVm\Infrastructure\Database\DatabaseFactory::set($rDb);
				\XcVm\Core\Config\SettingsManager::set([]);

				call_user_func($argv[1], (int) $argv[2]);

				$rOut = ['scans' => is_file(getenv('XCVM_TEST_DIR') . 'scans') ? file(getenv('XCVM_TEST_DIR') . 'scans', FILE_IGNORE_NEW_LINES) : []];
				foreach (['streams' => 'SELECT `id` FROM `streams` ORDER BY `id`', 'episodes' => 'SELECT `stream_id` FROM `streams_episodes` ORDER BY `stream_id`', 'series' => 'SELECT `id` FROM `streams_series` ORDER BY `id`'] as $rName => $rQuery) {
					$rDb->query($rQuery);
					$rOut[$rName] = array_map('intval', $rDb->get_column());
				}
				echo json_encode($rOut);
			}
			PHP);
		$rProc = proc_open([PHP_BINARY, $rScript, $rMethod, (string) $rID], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, ['XCVM_TEST_DIR' => $this->rDir, 'XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'PATH' => (string) getenv('PATH')] + TestDb::env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);
		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, $rOut . $rErr);
		return $rResult;
	}

	public function testDeletingASeriesScansTheBouquetsOnce(): void {
		$rAfter = $this->after(SeriesService::class . '::deleteSeriesById', 1);

		$this->assertSame([[21, 30], [21], [2]], [$rAfter['streams'], $rAfter['episodes'], $rAfter['series']], 'the series and its episodes are gone, nothing else');
		$this->assertCount(1, $rAfter['scans']);
		$this->assertStringContainsString('console.php tools bouquets', $rAfter['scans'][0]);
	}

	public function testDeletingOneStreamStillScansTheBouquets(): void {
		$rAfter = $this->after(StreamRepository::class . '::deleteStream', 30);

		$this->assertSame([11, 12, 13, 21], $rAfter['streams']);
		$this->assertCount(1, $rAfter['scans']);
	}
}
