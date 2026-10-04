<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamStateWriter;

/**
 * A node's runtime state for a stream (pids, status, probe results, progress)
 * is written through StreamStateWriter; desired state stays MAIN's. The SQL
 * sink writes the same UPDATE the ~30 call sites wrote, and any column
 * outside the runtime-state list is refused.
 */
final class StreamStateWriterTest extends TestCase {

	private function recorder(): object {
		return new class {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return true;
			}
		};
	}

	protected function tearDown(): void {
		StreamStateWriter::useSink(null);
	}

	public function testUpdateByStreamAndServer(): void {
		$rDb = $this->recorder();
		StreamStateWriter::update(12, 3, ['pid' => 4242, 'stream_status' => 0, 'stream_info' => null], $rDb);
		$this->assertSame([[
			'UPDATE `streams_servers` SET `pid` = ?, `stream_status` = ?, `stream_info` = ? WHERE `stream_id` = ? AND `server_id` = ?',
			[4242, 0, null, 12, 3],
		]], $rDb->rQueries);
	}

	public function testUpdateByRowId(): void {
		$rDb = $this->recorder();
		StreamStateWriter::updateRow(77, ['progress_info' => ''], $rDb);
		$this->assertSame([['UPDATE `streams_servers` SET `progress_info` = ? WHERE `server_stream_id` = ?', ['', 77]]], $rDb->rQueries);
		StreamStateWriter::updateRow(77, [], $rDb);
		$this->assertCount(1, $rDb->rQueries, 'nothing to write, no query');
	}

	public function testDesiredStateColumnsAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('parent_id');
		StreamStateWriter::update(1, 1, ['pid' => 1, 'parent_id' => 5], $this->recorder());
	}

	public function testSinkReceivesTheIntent(): void {
		$rSeen = [];
		StreamStateWriter::useSink(function (string $rWhere, array $rFields, array $rKeys) use (&$rSeen): bool {
			$rSeen[] = [$rWhere, $rFields, $rKeys];
			return true;
		});
		StreamStateWriter::update(5, 2, ['monitor_pid' => 9]);
		$this->assertSame([['`stream_id` = ? AND `server_id` = ?', ['monitor_pid' => 9], [5, 2]]], $rSeen);
	}

	public function testRuntimeWritersUseTheSeam(): void {
		$rRoot = MAIN_HOME;
		foreach ([
			'Domain/Stream/StreamProcess.php', 'Cli/Commands/MonitorCommand.php', 'Cli/CronJobs/StreamsCronJob.php',
			'Cli/CronJobs/VodCronJob.php', 'Cli/CronJobs/CleanupCronJob.php', 'Cli/Commands/CreatedCommand.php',
			'Cli/Commands/DelayCommand.php', 'Cli/Commands/ScannerCommand.php',
		] as $rFile) {
			$this->assertStringNotContainsString('UPDATE `streams_servers` SET', (string) file_get_contents($rRoot . $rFile), $rFile);
		}
	}
}
